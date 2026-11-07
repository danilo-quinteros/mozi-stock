<?php

namespace App\Domain\AgenteMozi\Datos;

use App\Domain\AgenteMozi\Herramientas\ArgumentoInvalido;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Ejecuta una consulta genérica sobre una fuente del catálogo (§ 5.1). El modelo solo elige nombres
 * de campos, operadores y valores: las expresiones SQL salen de Campo y los valores viajan siempre
 * como parámetros. Listar devuelve filas; agregar agrupa por hasta 2 campos con hasta 6 medidas y
 * filtra medidas en una consulta externa (equivale a HAVING). El total de coincidencias se calcula
 * aparte, para que el modelo sepa si vio todo o un recorte.
 */
final class ConsultaDatos
{
    public const OPERADORES = ['equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with', 'in', 'gt', 'gte', 'lt', 'lte', 'is_empty', 'is_not_empty'];

    private const OPERADORES_TEXTO = ['equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with', 'in', 'is_empty', 'is_not_empty'];

    private const OPERADORES_VALOR = ['equals', 'not_equals', 'in', 'gt', 'gte', 'lt', 'lte', 'is_empty', 'is_not_empty'];

    /** Campos de tiempo: una agregación por ellos se ordena cronológicamente por defecto. */
    private const TEMPORALES = ['fecha', 'semana', 'mes', 'anio', 'dia_semana', 'hora'];

    private const MAX_CAMPOS = 20;

    private const MAX_GRUPOS = 2;

    private const MAX_MEDIDAS = 6;

    public function __construct(private readonly CatalogoDatos $catalogo) {}

    /**
     * @param  array<string, mixed>  $a  argumentos validados de query_business_data
     * @return array{fuente: Fuente, columnas: list<array{field: string, label: string, type: string, nullable: bool}>, filas: list<array<string, string|int|null>>, total: int, periodo: array{from: string, to: string}|null, filtros: list<string>, agrupado: list<string>}
     */
    public function ejecutar(array $a): array
    {
        $fuente = $this->catalogo->fuente((string) $a['source']);
        $grupos = $this->nombres($a['group_by'] ?? []);
        $medidas = $this->nombres($a['measures'] ?? []);
        $campos = $this->nombres($a['fields'] ?? []);
        $agregado = $grupos !== [] || $medidas !== [];

        if ($agregado && $campos !== []) {
            throw new ArgumentoInvalido('fields solo sirve para listar. Para agregar usa group_by (dimensiones) y measures (medidas), sin fields.');
        }

        if (count($grupos) > self::MAX_GRUPOS || count($medidas) > self::MAX_MEDIDAS || count($campos) > self::MAX_CAMPOS) {
            throw new ArgumentoInvalido('Máximo '.self::MAX_GRUPOS.' campos en group_by, '.self::MAX_MEDIDAS.' en measures y '.self::MAX_CAMPOS.' en fields.');
        }

        $consulta = $this->catalogo->consulta($fuente);
        $periodo = $this->periodo($consulta, $fuente, $a['date_from'] ?? null, $a['date_to'] ?? null);
        $condiciones = [];
        $descritos = [];
        $porEstado = false;

        foreach ((array) ($a['filters'] ?? []) as $filtro) {
            $campo = $this->campo($fuente, (string) ($filtro['field'] ?? ''), null);
            $descritos[] = $this->filtrar($consulta, $campo, $filtro, $agregado, $condiciones);
            $porEstado = $porEstado || $campo->nombre === 'estado';
        }

        if ($fuente->soloActivos !== null && ! $porEstado) {
            $consulta->whereRaw($fuente->soloActivos);
        }

        $limite = (int) ($a['limit'] ?? 100);
        $orden = is_array($a['sort'] ?? null) ? $a['sort'] : null;

        [$columnas, $resultado, $total] = $agregado
            ? $this->agregar($fuente, $consulta, $grupos, $medidas === [] ? ['conteo'] : $medidas, $condiciones, $orden, $limite)
            : $this->listar($fuente, $consulta, $campos === [] ? $fuente->predeterminados : $campos, $orden, $limite);

        return [
            'fuente' => $fuente,
            'columnas' => array_map(fn (Campo $c) => ['field' => $c->nombre, 'label' => $c->etiqueta, 'type' => $c->tipo, 'nullable' => true], $columnas),
            'filas' => array_values(array_map(fn (object $fila) => $this->fila($fila, $columnas), $resultado)),
            'total' => $total,
            'periodo' => $periodo,
            'filtros' => $descritos,
            'agrupado' => $grupos,
        ];
    }

    /**
     * Valor de la base de datos convertido al tipo del Lienzo: dinero con 2 decimales, cantidades
     * con 3, porcentajes con 1, siempre como texto decimal (nunca float).
     */
    public static function valor(mixed $valor, string $tipo): string|int|null
    {
        if ($valor === null) {
            return null;
        }

        // PDO devuelve DECIMAL como texto; un DOUBLE inesperado se pasa a texto sin operar con él.
        $texto = is_float($valor) ? sprintf('%.6F', $valor) : (string) $valor;

        return match ($tipo) {
            'integer' => (int) $texto,
            'money' => Decimal::redondear($texto, 2),
            'number' => Decimal::redondear($texto, 3),
            'percent' => Decimal::redondear($texto, 1),
            'date' => substr($texto, 0, 10),
            default => mb_substr($texto, 0, 200),
        };
    }

    /**
     * @param  list<Campo>  $columnas
     * @return array<string, string|int|null>
     */
    private function fila(object $fila, array $columnas): array
    {
        $valores = (array) $fila;
        $limpia = [];

        foreach ($columnas as $campo) {
            $limpia[$campo->nombre] = self::valor($valores[$campo->nombre] ?? null, $campo->tipo);
        }

        return $limpia;
    }

    /**
     * @param  list<string>  $nombres
     * @param  array{field?: mixed, direction?: mixed}|null  $orden
     * @return array{0: list<Campo>, 1: array<int, \stdClass>, 2: int}
     */
    private function listar(Fuente $fuente, Builder $consulta, array $nombres, ?array $orden, int $limite): array
    {
        $columnas = array_map(fn (string $nombre) => $this->campo($fuente, $nombre, false), array_values(array_unique($nombres)));
        $total = (clone $consulta)->count();
        [$campoOrden, $direccion] = $orden === null
            ? [$fuente->campo($fuente->orden[0]), $fuente->orden[1]]
            : [$this->campo($fuente, (string) ($orden['field'] ?? ''), false), $this->direccion($orden)];

        if ($campoOrden !== null) {
            $consulta->orderByRaw(($campoOrden->ordenSql ?? $campoOrden->sql).' '.$direccion);
        }

        $filas = $consulta
            ->select(array_map(fn (Campo $c) => DB::raw("{$c->sql} AS `{$c->nombre}`"), $columnas))
            ->limit($limite)
            ->get()->all();

        return [$columnas, $filas, $total];
    }

    /**
     * GROUP BY usa las expresiones (no los alias: MySQL resolvería primero columnas de las tablas) y
     * la consulta externa filtra las medidas y ordena por alias.
     *
     * @param  list<string>  $grupos
     * @param  list<string>  $medidas
     * @param  list<array{campo: Campo, sql: literal-string, valores: list<string>}>  $condiciones
     * @param  array{field?: mixed, direction?: mixed}|null  $orden
     * @return array{0: list<Campo>, 1: array<int, \stdClass>, 2: int}
     */
    private function agregar(Fuente $fuente, Builder $consulta, array $grupos, array $medidas, array $condiciones, ?array $orden, int $limite): array
    {
        $dimensiones = array_map(fn (string $nombre) => $this->campo($fuente, $nombre, false), array_values(array_unique($grupos)));
        $metricas = array_map(fn (string $nombre) => $this->campo($fuente, $nombre, true), array_values(array_unique($medidas)));
        $columnas = [...$dimensiones, ...$metricas];
        // MIN() sobre la propia expresión agrupada: ONLY_FULL_GROUP_BY no siempre reconoce un CASE
        // idéntico al del GROUP BY cuando usa columnas de un LEFT JOIN.
        $select = [
            ...array_map(fn (Campo $c) => DB::raw("MIN({$c->sql}) AS `{$c->nombre}`"), $dimensiones),
            ...array_map(fn (Campo $c) => DB::raw("{$c->sql} AS `{$c->nombre}`"), $metricas),
        ];

        foreach ($dimensiones as $dimension) {
            $consulta->groupByRaw($dimension->sql);

            if ($dimension->ordenSql !== null) {
                $select[] = DB::raw("MIN({$dimension->ordenSql}) AS `__orden_{$dimension->nombre}`");
            }
        }

        $externa = DB::query()->fromSub($consulta->select($select), 't');

        foreach ($condiciones as $condicion) {
            $externa->whereRaw($condicion['sql'], $condicion['valores']);
        }

        $total = (clone $externa)->count();
        [$campoOrden, $direccion] = $this->ordenAgregado($dimensiones, $metricas, $orden);

        if ($campoOrden !== null) {
            $externa->orderBy($campoOrden->ordenSql !== null && in_array($campoOrden, $dimensiones, true)
                ? "t.__orden_{$campoOrden->nombre}" : "t.{$campoOrden->nombre}", $direccion);
        }

        return [$columnas, $externa->limit($limite)->get()->all(), $total];
    }

    /**
     * Sin orden pedido: cronológico si se agrupa por tiempo; si no, la primera medida de mayor a menor.
     *
     * @param  list<Campo>  $dimensiones
     * @param  list<Campo>  $metricas
     * @param  array{field?: mixed, direction?: mixed}|null  $orden
     * @return array{0: Campo|null, 1: 'asc'|'desc'}
     */
    private function ordenAgregado(array $dimensiones, array $metricas, ?array $orden): array
    {
        if ($orden !== null) {
            $nombre = (string) ($orden['field'] ?? '');

            foreach ([...$dimensiones, ...$metricas] as $campo) {
                if ($campo->nombre === $nombre) {
                    return [$campo, $this->direccion($orden)];
                }
            }

            throw new ArgumentoInvalido("Para ordenar una agregación usa un campo de group_by o una medida de measures; {$nombre} no está entre ellos.");
        }

        if ($dimensiones !== [] && in_array($dimensiones[0]->nombre, self::TEMPORALES, true)) {
            return [$dimensiones[0], 'asc'];
        }

        return [$dimensiones === [] ? null : $metricas[0], 'desc'];
    }

    /**
     * Aplica un filtro: sobre un campo, como WHERE; sobre una medida, como condición de la consulta
     * externa de la agregación. Devuelve su descripción legible para los metadatos.
     *
     * @param  array<string, mixed>  $filtro
     * @param  list<array{campo: Campo, sql: literal-string, valores: list<string>}>  $condiciones
     */
    private function filtrar(Builder $consulta, Campo $campo, array $filtro, bool $agregado, array &$condiciones): string
    {
        $op = (string) ($filtro['op'] ?? '');
        $permitidos = $campo->esTexto() ? self::OPERADORES_TEXTO : self::OPERADORES_VALOR;

        if (! in_array($op, $permitidos, true)) {
            throw new ArgumentoInvalido("El operador {$op} no sirve para {$campo->nombre} ({$campo->tipo}). Operadores válidos: ".implode(', ', $permitidos).'.');
        }

        if ($campo->medida && ! $agregado) {
            throw new ArgumentoInvalido("{$campo->nombre} es una medida: solo se puede filtrar en una agregación (con group_by o measures).");
        }

        $columna = $campo->medida ? "`t`.`{$campo->nombre}`" : $campo->sql;
        $valores = match ($op) {
            'is_empty', 'is_not_empty' => [],
            'in' => $this->lista($campo, $filtro['values'] ?? null),
            default => [$this->literal($campo, $filtro['value'] ?? null, $op)],
        };
        $marcador = match ($campo->tipo) {
            'string' => '?',
            'date' => 'CAST(? AS DATE)',
            default => 'CAST(? AS DECIMAL(30,6))',
        };
        $vacio = $campo->esTexto() ? "({$columna} IS NULL OR {$columna} = '')" : "{$columna} IS NULL";
        $sql = match ($op) {
            'equals' => "{$columna} = {$marcador}",
            'not_equals' => "({$columna} <> {$marcador} OR {$columna} IS NULL)",
            'contains', 'starts_with', 'ends_with' => "{$columna} LIKE ?",
            'not_contains' => "({$columna} NOT LIKE ? OR {$columna} IS NULL)",
            'in' => "{$columna} IN (".implode(', ', array_fill(0, count($valores), $marcador)).')',
            'gt' => "{$columna} > {$marcador}",
            'gte' => "{$columna} >= {$marcador}",
            'lt' => "{$columna} < {$marcador}",
            'lte' => "{$columna} <= {$marcador}",
            'is_empty' => $vacio,
            'is_not_empty' => "NOT {$vacio}",
        };

        if (in_array($op, ['contains', 'not_contains', 'starts_with', 'ends_with'], true)) {
            $escapado = addcslashes($valores[0], '\\%_');
            $valores = [match ($op) {
                'starts_with' => $escapado.'%',
                'ends_with' => '%'.$escapado,
                default => '%'.$escapado.'%',
            }];
        }

        if ($campo->medida) {
            $condiciones[] = ['campo' => $campo, 'sql' => $sql, 'valores' => $valores];
        } else {
            $consulta->whereRaw($sql, $valores);
        }

        return mb_substr(trim($campo->nombre.' '.$op.' '.implode(', ', array_map(fn (string $v) => trim($v, '%'), $valores))), 0, 80);
    }

    private function literal(Campo $campo, mixed $valor, string $op): string
    {
        $texto = match (true) {
            is_string($valor) => trim($valor),
            is_int($valor) => (string) $valor,
            is_float($valor) => (string) json_encode($valor),
            default => throw new ArgumentoInvalido("El filtro {$op} sobre {$campo->nombre} necesita un valor en value."),
        };

        if ($texto === '') {
            throw new ArgumentoInvalido("El filtro {$op} sobre {$campo->nombre} necesita un valor no vacío.");
        }

        return match ($campo->tipo) {
            'string' => mb_strlen($texto) <= 200 ? $texto : throw new ArgumentoInvalido('Un valor de texto admite hasta 200 caracteres.'),
            'date' => $this->esFecha($texto) ? $texto : throw new ArgumentoInvalido("{$campo->nombre} es una fecha: usa el formato AAAA-MM-DD."),
            default => Decimal::normalizar($texto, 6) ?? throw new ArgumentoInvalido("{$campo->nombre} es numérico: usa un número con punto decimal, sin separador de miles (por ejemplo 1500.50)."),
        };
    }

    /**
     * @return list<string>
     */
    private function lista(Campo $campo, mixed $valores): array
    {
        if (! is_array($valores) || $valores === [] || count($valores) > 50) {
            throw new ArgumentoInvalido("El operador in sobre {$campo->nombre} necesita entre 1 y 50 valores en values.");
        }

        return array_values(array_map(fn (mixed $v) => $this->literal($campo, $v, 'in'), $valores));
    }

    /**
     * Filtra la fecha principal por días de negocio: [desde 00:00, hasta + 1 día) en hora local.
     *
     * @return array{from: string, to: string}|null
     */
    private function periodo(Builder $consulta, Fuente $fuente, mixed $desde, mixed $hasta): ?array
    {
        $desde = is_string($desde) && $desde !== '' ? $desde : null;
        $hasta = is_string($hasta) && $hasta !== '' ? $hasta : null;

        if ($desde === null && $hasta === null) {
            return null;
        }

        if ($fuente->fechaSql === null) {
            throw new ArgumentoInvalido("La fuente {$fuente->nombre} no tiene fecha principal: quita date_from y date_to y filtra por sus campos.");
        }

        if ($desde !== null && $hasta !== null && $desde > $hasta) {
            throw new ArgumentoInvalido('date_from debe ser anterior o igual a date_to.');
        }

        $zona = $this->catalogo->desfase();
        $limite = fn (string $dia, int $mas) => $fuente->fechaConHora
            ? CarbonImmutable::createFromFormat('!Y-m-d', $dia, $zona)->addDays($mas)->utc()->format('Y-m-d H:i:s')
            : CarbonImmutable::createFromFormat('!Y-m-d', $dia)->addDays($mas)->format('Y-m-d');

        if ($desde !== null) {
            $consulta->where(DB::raw($fuente->fechaSql), '>=', $limite($desde, 0));
        }

        if ($hasta !== null) {
            $consulta->where(DB::raw($fuente->fechaSql), '<', $limite($hasta, 1));
        }

        return $desde !== null ? ['from' => $desde, 'to' => $hasta ?? $this->catalogo->hoy()] : null;
    }

    /**
     * @param  bool|null  $medida  true = solo medidas, false = solo campos, null = cualquiera
     */
    private function campo(Fuente $fuente, string $nombre, ?bool $medida): Campo
    {
        $campo = $fuente->campo($nombre);
        $administrador = $this->catalogo->administrador();
        $nombres = fn (bool $medidas) => implode(', ', array_map(fn (Campo $c) => $c->nombre, $fuente->visibles($administrador, $medidas)));

        if ($campo === null || ($medida !== null && $campo->medida !== $medida)) {
            throw new ArgumentoInvalido(match ($medida) {
                true => "{$nombre} no es una medida de {$fuente->nombre}. Medidas: {$nombres(true)}.",
                false => "{$nombre} no es un campo de {$fuente->nombre}. Campos: {$nombres(false)}.",
                null => "{$nombre} no existe en {$fuente->nombre}. Campos: {$nombres(false)}. Medidas: {$nombres(true)}.",
            });
        }

        if ($campo->soloAdministrador && ! $administrador) {
            throw new ArgumentoInvalido("{$nombre} no está disponible para el rol del usuario (vendedor).");
        }

        return $campo;
    }

    /**
     * @param  array{direction?: mixed}  $orden
     * @return 'asc'|'desc'
     */
    private function direccion(array $orden): string
    {
        return ($orden['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
    }

    /**
     * @return list<string>
     */
    private function nombres(mixed $nombres): array
    {
        return is_array($nombres) ? array_values(array_filter(array_map(fn (mixed $n) => is_string($n) ? trim($n) : '', $nombres), fn (string $n) => $n !== '')) : [];
    }

    private function esFecha(string $valor): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
