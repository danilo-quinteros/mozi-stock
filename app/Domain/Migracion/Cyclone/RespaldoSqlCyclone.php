<?php

namespace App\Domain\Migracion\Cyclone;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lee el respaldo .sql de Cyclone (exportado con phpMyAdmin o mysqldump) SIN ejecutarlo: solo toma los valores de los
 * INSERT de sus 7 tablas y descarta cualquier otra sentencia (DROP, UPDATE, SET, tablas ajenas...). Los valores se
 * guardan con parámetros enlazados en tablas TEMPORARY de una conexión propia: existen solo durante la petición, otras
 * sesiones no las ven y no chocan con las tablas de MoziStock (allí son plurales: productos, clientes, sucursales...).
 *
 * La estructura es la de la base de Cyclone, incluidos los precios FLOAT, para que OrigenCyclone lea exactamente lo
 * mismo que leería de su base importada (los convierte a DECIMAL en SQL, nunca con float en PHP).
 */
final class RespaldoSqlCyclone
{
    public const CONEXION = 'cyclone_respaldo';

    /** Tamaño máximo del archivo (el respaldo real de noviembre de 2026 pesa unos 775 KB). */
    public const MAX_KB = 10240;

    private const LOTE = 500;

    /** Columnas que lee OrigenCyclone, con los tipos de la base de Cyclone. */
    private const ESTRUCTURA = [
        'sucursal' => [
            'id' => 'INT NOT NULL PRIMARY KEY', 'nombre' => 'VARCHAR(255) NOT NULL', 'codigo_siat' => 'TINYINT(1) NOT NULL',
            'direccion' => 'TEXT NULL', 'telefono' => 'VARCHAR(15) NULL',
        ],
        'unidad_presentacion' => ['id' => 'INT NOT NULL PRIMARY KEY', 'nombre' => 'VARCHAR(30) NOT NULL', 'unidad_medida_siat' => 'VARCHAR(50) NOT NULL'],
        'linea_produccion' => ['id' => 'INT NOT NULL PRIMARY KEY', 'nombre' => 'VARCHAR(30) NOT NULL'],
        'producto' => [
            'id' => 'INT NOT NULL PRIMARY KEY', 'codigo' => 'VARCHAR(20) NOT NULL', 'nombre' => 'VARCHAR(100) NOT NULL',
            'foto' => 'VARCHAR(100) NULL', 'unidad_presentacion' => 'INT NOT NULL', 'linea_produccion' => 'INT NOT NULL',
            'stock_minimo' => 'INT NOT NULL', 'stock_maximo' => 'INT NULL', 'costo_produccion' => 'FLOAT NOT NULL',
            'precio_minorista' => 'FLOAT NOT NULL', 'precio_mayorista' => 'FLOAT NOT NULL', 'precio_consumidor' => 'FLOAT NOT NULL',
            'descripcion' => 'TEXT NOT NULL', 'estado' => 'TINYINT(1) NOT NULL',
        ],
        'stock_sucursal' => ['id' => 'INT NOT NULL PRIMARY KEY', 'producto' => 'INT NOT NULL', 'sucursal' => 'INT NOT NULL', 'stock' => 'INT NULL'],
        'cliente' => [
            'id' => 'INT NOT NULL PRIMARY KEY', 'nombre_completo' => 'VARCHAR(100) NOT NULL', 'razon_social' => 'VARCHAR(100) NULL',
            'nit' => 'VARCHAR(15) NULL', 'email' => 'VARCHAR(100) NULL', 'telefono' => 'VARCHAR(20) NULL', 'direccion' => 'TEXT NULL',
            'estado' => 'TINYINT(1) NOT NULL',
        ],
        'proveedor' => [
            'id' => 'INT NOT NULL PRIMARY KEY', 'nombre_completo' => 'VARCHAR(100) NOT NULL', 'email' => 'VARCHAR(100) NULL',
            'telefono' => 'VARCHAR(20) NULL', 'direccion' => 'TEXT NULL', 'estado' => 'TINYINT(1) NULL',
        ],
    ];

    /** Secuencias de escape de MySQL dentro de cadenas; cualquier otra «\x» es la letra x. */
    private const ESCAPES = ['0' => "\0", 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1A", '%' => '\%', '_' => '\_'];

    private string $sql = '';

    /** @var array<string, list<string>> columnas de cada tabla según su CREATE TABLE (para INSERT sin lista de columnas) */
    private array $columnasCreate = [];

    /**
     * Carga el archivo en tablas temporales y devuelve el origen listo para PlanMigracionCyclone. Si el archivo no es un
     * respaldo de Cyclone que se pueda leer, lanza ValidationException (clave «respaldo») con el motivo en español.
     */
    public function cargar(string $ruta): OrigenCyclone
    {
        $contenido = is_file($ruta) ? file_get_contents($ruta) : false;

        if ($contenido === false) {
            $this->fallar(__('No se pudo leer el archivo del respaldo.'));
        }

        if (strlen($contenido) > self::MAX_KB * 1024) {
            $this->fallar(__('El respaldo no puede superar 10 MB.'));
        }

        if (str_starts_with($contenido, "\xEF\xBB\xBF")) {
            $contenido = substr($contenido, 3);
        }

        if (! mb_check_encoding($contenido, 'UTF-8')) {
            $this->fallar(__('El respaldo debe estar en UTF-8, como lo exporta phpMyAdmin.'));
        }

        $this->sql = $contenido;
        $this->columnasCreate = [];
        $db = $this->conexion();

        try {
            $presentes = $this->leer($db);
        } finally {
            $this->sql = '';
        }

        $faltantes = array_values(array_diff(array_keys(self::ESTRUCTURA), $presentes));

        if ($faltantes !== []) {
            $this->fallar(__('El archivo no parece un respaldo de Cyclone: no trae las tablas :tablas.', ['tablas' => implode(', ', $faltantes)]));
        }

        return new OrigenCyclone($db);
    }

    /**
     * Cierra la conexión propia: MySQL borra sus tablas temporales.
     */
    public function liberar(): void
    {
        DB::purge(self::CONEXION);
    }

    private function conexion(): ConnectionInterface
    {
        config(['database.connections.'.self::CONEXION => config('database.connections.'.config('database.default'))]);
        DB::purge(self::CONEXION);
        $db = DB::connection(self::CONEXION);

        try {
            foreach (self::ESTRUCTURA as $tabla => $columnas) {
                $definicion = implode(', ', array_map(fn (string $c, string $tipo): string => "`{$c}` {$tipo}", array_keys($columnas), $columnas));
                $db->statement("DROP TEMPORARY TABLE IF EXISTS `{$tabla}`");
                $db->statement("CREATE TEMPORARY TABLE `{$tabla}` ({$definicion}) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
        } catch (QueryException $e) {
            $this->fallar(__('La base de datos no permitió preparar la lectura del respaldo (tablas temporales): :motivo', ['motivo' => $e->errorInfo[2] ?? $e->getCode()]));
        }

        return $db;
    }

    /**
     * Recorre las sentencias del archivo y carga los INSERT de las tablas de Cyclone; el resto se descarta sin ejecutarse.
     *
     * @return list<string> tablas de Cyclone que aparecen en el archivo (con CREATE TABLE o INSERT)
     */
    private function leer(ConnectionInterface $db): array
    {
        $presentes = [];

        foreach ($this->sentencias() as [$inicio, $fin]) {
            $cabecera = substr($this->sql, $inicio, min(4000, $fin - $inicio));

            if (preg_match('/^CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`?\w+`?\.)?`?(\w+)`?\s*\(/i', $cabecera, $m)) {
                $tabla = strtolower($m[1]);

                if (isset(self::ESTRUCTURA[$tabla])) {
                    $presentes[$tabla] = true;
                    preg_match_all('/^\s*`([^`]+)`\s/m', substr($this->sql, $inicio + strlen($m[0]), $fin - $inicio - strlen($m[0])), $columnas);
                    $this->columnasCreate[$tabla] = array_map('strtolower', $columnas[1]);
                }

                continue;
            }

            if (! preg_match('/^(?:INSERT|REPLACE)\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)\s+)*(?:INTO\s+)?(?:`?\w+`?\.)?`?(\w+)`?\s*(?:\(([^)]*)\))?\s*VALUES\s*/i', $cabecera, $m)) {
                continue;
            }

            $tabla = strtolower($m[1]);

            if (! isset(self::ESTRUCTURA[$tabla])) {
                continue;
            }

            $presentes[$tabla] = true;
            $columnas = isset($m[2]) && trim($m[2]) !== ''
                ? array_map(fn (string $c): string => strtolower(trim($c, " \t\r\n`")), explode(',', $m[2]))
                : ($this->columnasCreate[$tabla] ?? $this->fallar(__('El INSERT de la tabla :tabla no indica sus columnas (línea :linea).', ['tabla' => $tabla, 'linea' => $this->linea($inicio)])));
            $faltantes = array_diff(array_keys(self::ESTRUCTURA[$tabla]), $columnas);

            if ($faltantes !== []) {
                $this->fallar(__('Al respaldo le falta la columna :columna de la tabla :tabla.', ['columna' => implode(', ', $faltantes), 'tabla' => $tabla]));
            }

            $this->insertar($db, $tabla, $columnas, $this->tuplas($inicio + strlen($m[0]), $fin, $tabla, count($columnas)));
        }

        return array_keys($presentes);
    }

    /**
     * Inicio (primer carácter que no es espacio ni comentario) y fin (el «;») de cada sentencia. Respeta cadenas y
     * nombres entre comillas, así un «;» dentro de un texto no corta la sentencia.
     *
     * @return \Generator<int, array{0: int, 1: int}>
     */
    private function sentencias(): \Generator
    {
        $sql = $this->sql;
        $largo = strlen($sql);
        $i = 0;
        $inicio = null;

        while ($i < $largo) {
            $c = $sql[$i];

            if ($c === "'" || $c === '"' || $c === '`') {
                $inicio ??= $i;
                $i = $this->finDeCadena($i);
            } elseif ($c === '#' || ($c === '-' && ($sql[$i + 1] ?? '') === '-' && ($i + 2 >= $largo || ctype_space($sql[$i + 2])))) {
                $salto = strpos($sql, "\n", $i);
                $i = $salto === false ? $largo : $salto + 1;
            } elseif ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                $salto = strpos($sql, '*/', $i + 2);
                $i = $salto === false ? $largo : $salto + 2;
            } elseif ($c === ';') {
                if ($inicio !== null) {
                    yield [$inicio, $i];
                }

                $inicio = null;
                $i++;
            } elseif (ctype_space($c)) {
                $i += strspn($sql, " \t\r\n\f\v", $i);
            } else {
                $inicio ??= $i;
                $i += 1 + strcspn($sql, "'\"`#-/; \t\r\n", $i + 1);
            }
        }

        if ($inicio !== null) {
            yield [$inicio, $largo];
        }
    }

    /**
     * Posición siguiente a la comilla que cierra la cadena que empieza en $i (admite «\'» y comillas dobladas).
     */
    private function finDeCadena(int $i): int
    {
        $sql = $this->sql;
        $comilla = $sql[$i];
        $buscar = $comilla === '`' ? '`' : $comilla.'\\';
        $j = $i + 1;

        while (true) {
            $k = $j + strcspn($sql, $buscar, $j);

            if ($k >= strlen($sql)) {
                $this->fallar(__('El respaldo tiene un texto sin cerrar desde la línea :linea.', ['linea' => $this->linea($i)]));
            }

            if ($sql[$k] === '\\') {
                $j = $k + 2;
            } elseif (($sql[$k + 1] ?? '') === $comilla) {
                $j = $k + 2;
            } else {
                return $k + 1;
            }
        }
    }

    /**
     * Filas de «VALUES (...), (...)» entre $i y el fin de la sentencia.
     *
     * @return list<list<?string>>
     */
    private function tuplas(int $i, int $fin, string $tabla, int $columnas): array
    {
        $sql = $this->sql;
        $filas = [];

        while (true) {
            $i = $this->saltarEspacios($i, $fin);

            if ($i >= $fin || $sql[$i] !== '(') {
                $this->fallarEn($i, $tabla);
            }

            $i++;
            $fila = [];

            while (true) {
                $i = $this->saltarEspacios($i, $fin);
                [$valor, $i] = $this->valor($i, $fin, $tabla);
                $fila[] = $valor;
                $i = $this->saltarEspacios($i, $fin);
                $c = $i < $fin ? $sql[$i] : '';
                $i++;

                if ($c === ')') {
                    break;
                }

                if ($c !== ',') {
                    $this->fallarEn($i - 1, $tabla);
                }
            }

            if (count($fila) !== $columnas) {
                $this->fallar(__('Una fila de la tabla :tabla tiene :valores valores y se esperaban :columnas (línea :linea).', [
                    'tabla' => $tabla, 'valores' => count($fila), 'columnas' => $columnas, 'linea' => $this->linea($i),
                ]));
            }

            $filas[] = $fila;
            $i = $this->saltarEspacios($i, $fin);

            if ($i >= $fin) {
                return $filas;
            }

            if ($sql[$i] !== ',') {
                $this->fallarEn($i, $tabla);
            }

            $i++;
        }
    }

    /**
     * Un valor literal: texto entre comillas, número o NULL. Cualquier otra cosa (funciones, subconsultas...) es error.
     *
     * @return array{0: ?string, 1: int}
     */
    private function valor(int $i, int $fin, string $tabla): array
    {
        $sql = $this->sql;
        $c = $i < $fin ? $sql[$i] : '';

        if ($c === "'" || $c === '"') {
            $cierre = $this->finDeCadena($i);

            if ($cierre > $fin) {
                $this->fallarEn($i, $tabla);
            }

            return [$this->desescapar(substr($sql, $i + 1, $cierre - $i - 2), $c), $cierre];
        }

        if (preg_match('/\G(?:NULL\b|[-+]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][-+]?\d+)?)/i', $sql, $m, 0, $i) && $i + strlen($m[0]) <= $fin) {
            return [strcasecmp($m[0], 'NULL') === 0 ? null : $m[0], $i + strlen($m[0])];
        }

        $this->fallarEn($i, $tabla);
    }

    private function desescapar(string $texto, string $comilla): string
    {
        if (strpbrk($texto, '\\'.$comilla) === false) {
            return $texto;
        }

        return (string) preg_replace_callback('/\\\\(.)|'.preg_quote($comilla.$comilla, '/').'/s',
            fn (array $m): string => isset($m[1]) ? (self::ESCAPES[$m[1]] ?? $m[1]) : $comilla, $texto);
    }

    private function saltarEspacios(int $i, int $fin): int
    {
        return min($fin, $i + strspn($this->sql, " \t\r\n\f\v", $i));
    }

    /**
     * @param  list<string>  $columnas
     * @param  list<list<?string>>  $filas
     */
    private function insertar(ConnectionInterface $db, string $tabla, array $columnas, array $filas): void
    {
        $posiciones = array_intersect_key(array_flip($columnas), self::ESTRUCTURA[$tabla]);

        foreach (array_chunk($filas, self::LOTE) as $lote) {
            $registros = array_map(fn (array $fila): array => array_map(fn (int $posicion): ?string => $fila[$posicion], $posiciones), $lote);

            try {
                $db->table($tabla)->insert($registros);
            } catch (QueryException $e) {
                // Solo el motivo del motor (sin la consulta ni sus valores, que traen datos de clientes).
                $this->fallar(__('Una fila de la tabla :tabla no tiene el formato de Cyclone: :motivo', ['tabla' => $tabla, 'motivo' => $e->errorInfo[2] ?? $e->getCode()]));
            }
        }
    }

    private function linea(int $posicion): int
    {
        return substr_count($this->sql, "\n", 0, min($posicion, strlen($this->sql))) + 1;
    }

    private function fallarEn(int $posicion, string $tabla): never
    {
        $this->fallar(__('No se pudo leer un valor de la tabla :tabla (línea :linea). Exporta el respaldo de nuevo con phpMyAdmin, en formato SQL.', [
            'tabla' => $tabla, 'linea' => $this->linea($posicion),
        ]));
    }

    private function fallar(string $mensaje): never
    {
        throw ValidationException::withMessages(['respaldo' => $mensaje]);
    }
}
