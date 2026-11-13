<?php

namespace App\Domain\Catalogo\Actions;

use App\Domain\Catalogo\Importacion\CamposImportacion;
use App\Domain\Catalogo\Importacion\LectorHojaCalculo;
use App\Domain\Catalogo\Importacion\ValidadorImportacion;
use App\Domain\Inventario\LibroLotes;
use App\Models\ImportacionProducto;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pasos previos a confirmar una importación de productos: subir el archivo (disco privado,
 * carpeta de la empresa), leer la hoja a la tabla de filas, asignar columnas y valores
 * (categorías y unidades) y validar. Nada de esto crea productos, categorías, unidades ni stock.
 */
final class PrepararImportacionProductos
{
    public const DISCO = 'local';

    public const MAX_KB = 10240;

    private const LOTE = 500;

    /**
     * Sinónimos de unidades frecuentes, ya normalizados. La primera entrada es el nombre base.
     */
    private const UNIDADES = [
        ['unidad', 'unidades', 'u', 'un', 'und', 'unid', 'uni', 'pza', 'pz', 'pieza', 'piezas', 'c u', 'cu'],
        ['kilogramo', 'kilogramos', 'kg', 'kgs', 'kilo', 'kilos', 'k'],
        ['gramo', 'gramos', 'g', 'gr', 'grs'],
        ['litro', 'litros', 'l', 'lt', 'lts'],
        ['mililitro', 'mililitros', 'ml', 'mls', 'cc'],
        ['caja', 'cajas', 'cj', 'cja'],
        ['paquete', 'paquetes', 'paq', 'pqt', 'pack'],
        ['metro', 'metros', 'm', 'mt', 'mts'],
        ['docena', 'docenas', 'doc'],
        ['bolsa', 'bolsas'],
        ['botella', 'botellas', 'bot'],
        ['lata', 'latas'],
        ['par', 'pares'],
        ['rollo', 'rollos'],
    ];

    private const UNIDADES_CON_DECIMALES = ['kilogramo', 'litro', 'metro'];

    public function __construct(
        private readonly LectorHojaCalculo $lector,
        private readonly ValidadorImportacion $validador,
        private readonly LibroLotes $lotes,
    ) {}

    /** Las columnas de lote solo se proponen y aceptan con «Lotes y vencimientos» activa. */
    private function conLotes(int $empresaId): bool
    {
        return $this->lotes->activo($empresaId);
    }

    public function crear(User $actor, UploadedFile $archivo): ImportacionProducto
    {
        Gate::forUser($actor)->authorize('create', Producto::class);

        $extension = Str::lower($archivo->getClientOriginalExtension());

        if (! in_array($extension, LectorHojaCalculo::EXTENSIONES, true)) {
            throw ValidationException::withMessages(['archivo' => __('Sube un archivo .xlsx, .ods o .csv. Si tienes un .xls antiguo, ábrelo en Excel y guárdalo como .xlsx.')]);
        }

        $empresaId = (int) $actor->empresa_id;
        $ruta = $archivo->storeAs("empresas/{$empresaId}/importaciones", Str::random(40).'.'.$extension, self::DISCO);

        if ($ruta === false) {
            throw ValidationException::withMessages(['archivo' => __('No se pudo guardar el archivo. Inténtalo de nuevo.')]);
        }

        try {
            $hojas = $this->lector->hojas(Storage::disk(self::DISCO)->path($ruta), $extension);

            return DB::transaction(function () use ($actor, $archivo, $ruta, $hojas, $empresaId): ImportacionProducto {
                $importacion = new ImportacionProducto;
                $importacion->empresa_id = $empresaId;
                $importacion->usuario_id = $actor->id;
                $importacion->archivo_nombre = mb_substr(basename($archivo->getClientOriginalName()), 0, 255);
                $importacion->archivo_path = $ruta;
                $importacion->hojas = $hojas;
                $importacion->save();

                // La primera hoja visible con datos.
                foreach ($hojas as $hoja) {
                    if ($this->cargarHoja($importacion, $hoja['indice']) > 0) {
                        return $importacion;
                    }
                }

                throw ValidationException::withMessages(['archivo' => __('El archivo no tiene datos.')]);
            });
        } catch (Throwable $e) {
            Storage::disk(self::DISCO)->delete($ruta);

            throw $e;
        }
    }

    public function cambiarHoja(User $actor, ImportacionProducto $importacion, int $hoja): void
    {
        $this->autorizarAbierta($actor, $importacion);

        if (! in_array($hoja, array_column($importacion->hojas ?? [], 'indice'), true) || $importacion->archivo_path === null) {
            throw ValidationException::withMessages(['hoja' => __('Elige una hoja del archivo.')]);
        }

        DB::transaction(function () use ($importacion, $hoja): void {
            if ($this->cargarHoja($importacion, $hoja) === 0) {
                throw ValidationException::withMessages(['hoja' => __('Esa hoja está vacía.')]);
            }
        });
    }

    /**
     * Vuelve a proponer columnas al elegir otra fila de encabezados.
     */
    public function cambiarEncabezados(User $actor, ImportacionProducto $importacion, int $fila): void
    {
        $this->autorizarAbierta($actor, $importacion);

        if (! $importacion->filas()->where('fila', $fila)->exists()) {
            throw ValidationException::withMessages(['fila_encabezados' => __('Elige una fila con datos.')]);
        }

        $importacion->fila_encabezados = $fila;
        $importacion->mapeo = CamposImportacion::detectar(array_column($this->columnas($importacion), 'encabezado', 'indice'), $this->conLotes($importacion->empresa_id));
        $importacion->estado = ImportacionProducto::COLUMNAS;
        $importacion->save();
    }

    /**
     * Columnas con datos: encabezado (o «Columna C» si no tiene) y hasta tres ejemplos.
     *
     * @return list<array{indice: int, letra: string, encabezado: string, ejemplos: list<string>}>
     */
    public function columnas(ImportacionProducto $importacion): array
    {
        $encabezados = (array) json_decode((string) DB::table('importacion_producto_filas')
            ->where('importacion_id', $importacion->id)->where('fila', $importacion->fila_encabezados)->value('celdas'), true);
        $muestra = DB::table('importacion_producto_filas')->where('importacion_id', $importacion->id)
            ->where('fila', '>', $importacion->fila_encabezados)->orderBy('fila')->limit(200)->pluck('celdas');
        $ejemplos = [];

        foreach ($muestra as $celdas) {
            foreach ((array) json_decode((string) $celdas, true) as $indice => $valor) {
                if (count($ejemplos[$indice] ?? []) < 3) {
                    $ejemplos[$indice][] = (string) $valor;
                }
            }
        }

        $indices = array_unique(array_merge(array_keys($encabezados), array_keys($ejemplos)));
        sort($indices);

        return array_map(fn (int $indice) => [
            'indice' => $indice,
            'letra' => self::letra($indice),
            'encabezado' => trim((string) ($encabezados[$indice] ?? '')) ?: __('Columna :letra', ['letra' => self::letra($indice)]),
            'ejemplos' => $ejemplos[$indice] ?? [],
        ], $indices);
    }

    /**
     * @param  array{fila_encabezados: int, mapeo: array<int|string, string>, unidad_defecto_id: int|null, usar_stock: bool|null, sucursal_stock_id: int|null}  $datos
     */
    public function asignarColumnas(User $actor, ImportacionProducto $importacion, array $datos): void
    {
        $this->autorizarAbierta($actor, $importacion);

        $indices = array_column($this->columnas($importacion), 'indice');
        $campos = CamposImportacion::campos($this->conLotes($importacion->empresa_id));
        $mapeo = [];
        $errores = [];

        foreach ($datos['mapeo'] as $indice => $campo) {
            if ($campo === '' || ! in_array((int) $indice, $indices, true)) {
                continue;
            }

            if (! isset($campos[$campo])) {
                $errores['mapeo'] = __('Elige un campo válido para cada columna.');
            } elseif (isset($mapeo[$campo])) {
                $errores['mapeo'] = __('«:campo» está asignado a dos columnas. Deja solo una.', ['campo' => __(CamposImportacion::CAMPOS[$campo]['etiqueta'])]);
            } else {
                $mapeo[$campo] = (int) $indice;
            }
        }

        foreach (CamposImportacion::CAMPOS as $campo => $definicion) {
            if ($definicion['obligatorio'] && ! isset($mapeo[$campo]) && ! isset($errores['mapeo'])) {
                $errores['mapeo'] = __('Indica qué columna tiene «:campo».', ['campo' => __($definicion['etiqueta'])]);
            }
        }

        $unidad = $datos['unidad_defecto_id'] === null ? null : DB::table('unidades_medida')
            ->where('empresa_id', $importacion->empresa_id)->where('activa', true)->where('id', $datos['unidad_defecto_id'])->value('id');

        if ($unidad === null) {
            $errores['unidad_defecto_id'] = __('Elige la unidad de medida para los productos que no la indican.');
        }

        $usarStock = isset($mapeo['stock']) ? $datos['usar_stock'] : null;
        $sucursal = null;

        if (isset($mapeo['stock']) && $usarStock === null) {
            $errores['usar_stock'] = __('Indica si quieres cargar la columna de stock como stock inicial.');
        }

        if ($usarStock === true) {
            $sucursal = $datos['sucursal_stock_id'] === null ? null : DB::table('sucursales')
                ->where('empresa_id', $importacion->empresa_id)->where('activa', true)->where('id', $datos['sucursal_stock_id'])->value('id');

            if ($sucursal === null) {
                $errores['sucursal_stock_id'] = __('Elige la sucursal donde se cargará el stock inicial.');
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        $importacion->mapeo = $mapeo;
        $importacion->unidad_defecto_id = (int) $unidad;
        $importacion->usar_stock = $usarStock;
        $importacion->sucursal_stock_id = $sucursal === null ? null : (int) $sucursal;
        $importacion->valores = $this->proponerValores($importacion);

        if (($importacion->valores['categorias'] ?? []) === [] && ($importacion->valores['unidades'] ?? []) === []) {
            $this->revisar($importacion);

            return;
        }

        $importacion->estado = ImportacionProducto::VALORES;
        $importacion->save();
    }

    /**
     * @param  array{categorias?: array<string, array{destino: string|int|null, nombre?: string|null}>, unidades?: array<string, array{destino: string|int|null, nombre?: string|null, abreviatura?: string|null, decimales?: bool|null}>}  $elegidos
     */
    public function asignarValores(User $actor, ImportacionProducto $importacion, array $elegidos): void
    {
        $this->autorizarAbierta($actor, $importacion);

        $empresaId = $importacion->empresa_id;
        $categorias = DB::table('categorias')->where('empresa_id', $empresaId)->pluck('nombre', 'id');
        $unidades = DB::table('unidades_medida')->where('empresa_id', $empresaId)->where('activa', true)->pluck('nombre', 'id');
        $nombresUnidades = DB::table('unidades_medida')->where('empresa_id', $empresaId)->pluck('nombre')->map(CamposImportacion::clave(...))->all();
        $valores = $importacion->valores ?? [];
        $errores = [];

        foreach ($valores['categorias'] ?? [] as $clave => $valor) {
            $destino = $elegidos['categorias'][$clave]['destino'] ?? null;
            $nombre = trim((string) ($elegidos['categorias'][$clave]['nombre'] ?? $valor['nombre']));

            if ($destino === 'crear') {
                if ($nombre === '' || mb_strlen($nombre) > 80) {
                    $errores["categorias.{$clave}"] = __('El nombre de la categoría «:valor» debe tener entre 1 y 80 caracteres.', ['valor' => $valor['nombre']]);
                } elseif ($categorias->contains(fn (string $existente) => CamposImportacion::clave($existente) === CamposImportacion::clave($nombre))) {
                    $errores["categorias.{$clave}"] = __('Ya existe la categoría «:valor»: elígela en la lista.', ['valor' => $nombre]);
                }

                $valores['categorias'][$clave]['nombre_nuevo'] = $nombre;
            } elseif ($destino !== 'ninguna' && ! $categorias->has((int) $destino)) {
                $errores["categorias.{$clave}"] = __('Indica qué hacer con la categoría «:valor».', ['valor' => $valor['nombre']]);
            }

            $valores['categorias'][$clave]['destino'] = in_array($destino, ['crear', 'ninguna'], true) ? $destino : (int) $destino;
        }

        foreach ($valores['unidades'] ?? [] as $clave => $valor) {
            $elegido = $elegidos['unidades'][$clave] ?? [];
            $destino = $elegido['destino'] ?? null;

            if ($destino === 'crear') {
                $nombre = trim((string) ($elegido['nombre'] ?? $valor['nombre']));
                $abreviatura = trim((string) ($elegido['abreviatura'] ?? ''));

                if ($nombre === '' || mb_strlen($nombre) > 60 || $abreviatura === '' || mb_strlen($abreviatura) > 15) {
                    $errores["unidades.{$clave}"] = __('La unidad «:valor» necesita un nombre (hasta 60 caracteres) y una abreviatura (hasta 15).', ['valor' => $valor['nombre']]);
                } elseif (in_array(CamposImportacion::clave($nombre), $nombresUnidades, true)) {
                    $errores["unidades.{$clave}"] = __('Ya existe la unidad «:valor»: elígela en la lista.', ['valor' => $nombre]);
                }

                $valores['unidades'][$clave] = array_merge($valor, [
                    'destino' => 'crear', 'nombre_nuevo' => $nombre, 'abreviatura' => $abreviatura, 'decimales' => (bool) ($elegido['decimales'] ?? false),
                ]);
            } elseif (! $unidades->has((int) $destino)) {
                $errores["unidades.{$clave}"] = __('Indica qué hacer con la unidad «:valor».', ['valor' => $valor['nombre']]);
            } else {
                $valores['unidades'][$clave]['destino'] = (int) $destino;
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        $importacion->valores = $valores;
        $this->revisar($importacion);
    }

    public function volverAColumnas(User $actor, ImportacionProducto $importacion): void
    {
        $this->autorizarAbierta($actor, $importacion);
        $importacion->estado = ImportacionProducto::COLUMNAS;
        $importacion->save();
    }

    public function volverAValores(User $actor, ImportacionProducto $importacion): void
    {
        $this->autorizarAbierta($actor, $importacion);

        if (($importacion->valores['categorias'] ?? []) !== [] || ($importacion->valores['unidades'] ?? []) !== []) {
            $importacion->estado = ImportacionProducto::VALORES;
            $importacion->save();
        }
    }

    public function cancelar(User $actor, ImportacionProducto $importacion): void
    {
        $this->autorizarAbierta($actor, $importacion);

        $ruta = $importacion->archivo_path;

        DB::transaction(function () use ($importacion): void {
            $importacion->filas()->delete();
            $importacion->estado = ImportacionProducto::CANCELADA;
            $importacion->archivo_path = null;
            $importacion->resumen = null;
            $importacion->save();
        });

        if ($ruta !== null) {
            Storage::disk(self::DISCO)->delete($ruta);
        }
    }

    private function revisar(ImportacionProducto $importacion): void
    {
        $importacion->estado = ImportacionProducto::REVISION;
        $importacion->resumen = $this->validador->validar($importacion);
        $importacion->save();
    }

    private function autorizarAbierta(User $actor, ImportacionProducto $importacion): void
    {
        Gate::forUser($actor)->authorize('create', Producto::class);

        if ($importacion->empresa_id !== (int) $actor->empresa_id) {
            abort(404);
        }

        if (! $importacion->abierta()) {
            throw ValidationException::withMessages(['importacion' => __('Esta importación ya terminó. Sube el archivo de nuevo para empezar otra.')]);
        }
    }

    /**
     * Reemplaza las filas guardadas por las de la hoja indicada. Devuelve cuántas tiene.
     */
    private function cargarHoja(ImportacionProducto $importacion, int $hoja): int
    {
        $importacion->filas()->delete();
        $ruta = Storage::disk(self::DISCO)->path((string) $importacion->archivo_path);
        $extension = Str::lower(pathinfo((string) $importacion->archivo_path, PATHINFO_EXTENSION));
        $lote = [];
        $primeras = [];
        $total = 0;

        foreach ($this->lector->filas($ruta, $extension, $hoja) as $fila) {
            $total++;

            if (count($primeras) < 20) {
                $primeras[] = $fila;
            }

            $lote[] = [
                'empresa_id' => $importacion->empresa_id,
                'importacion_id' => $importacion->id,
                'fila' => $fila['fila'],
                'celdas' => json_encode($fila['celdas'], JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
                'numericas' => $fila['numericas'] === [] ? null : json_encode($fila['numericas']),
            ];

            if (count($lote) === self::LOTE) {
                DB::table('importacion_producto_filas')->insert($lote);
                $lote = [];
            }
        }

        if ($lote !== []) {
            DB::table('importacion_producto_filas')->insert($lote);
        }

        $importacion->hoja = $hoja;
        $importacion->total_filas = $total;
        $importacion->fila_encabezados = self::detectarEncabezados($primeras);
        $importacion->estado = ImportacionProducto::COLUMNAS;
        $importacion->valores = null;
        $importacion->usar_stock = null;
        $importacion->resumen = null;
        $importacion->save();
        $importacion->mapeo = $total === 0 ? [] : CamposImportacion::detectar(array_column($this->columnas($importacion), 'encabezado', 'indice'), $this->conLotes($importacion->empresa_id));
        $importacion->save();

        return $total;
    }

    /**
     * La primera fila con al menos dos textos (no números) se toma como encabezados; así se
     * saltan títulos como «Lista de precios 2026» que ocupan una sola celda.
     *
     * @param  list<array{fila: int, celdas: array<int, string>, numericas: list<int>}>  $primeras
     */
    private static function detectarEncabezados(array $primeras): int
    {
        foreach ($primeras as $fila) {
            if (count($fila['celdas']) - count($fila['numericas']) >= 2) {
                return $fila['fila'];
            }
        }

        return $primeras[0]['fila'] ?? 1;
    }

    /**
     * Propone el destino de cada categoría y unidad distinta del archivo: la existente con el
     * mismo nombre (o abreviatura/sinónimo en unidades) o crear una nueva. Conserva lo que el
     * usuario ya había elegido para el mismo valor.
     *
     * @return array{categorias: array<string, array<string, mixed>>, unidades: array<string, array<string, mixed>>}
     */
    private function proponerValores(ImportacionProducto $importacion): array
    {
        $mapeo = $importacion->mapeo ?? [];
        $anteriores = $importacion->valores ?? [];
        $distintos = ['categorias' => [], 'unidades' => []];

        if (isset($mapeo['categoria']) || isset($mapeo['unidad'])) {
            $filas = DB::table('importacion_producto_filas')->where('importacion_id', $importacion->id)
                ->where('fila', '>', $importacion->fila_encabezados)->pluck('celdas');

            foreach ($filas as $celdas) {
                $celdas = (array) json_decode((string) $celdas, true);

                foreach (['categorias' => 'categoria', 'unidades' => 'unidad'] as $grupo => $campo) {
                    $texto = isset($mapeo[$campo]) ? trim((string) ($celdas[$mapeo[$campo]] ?? '')) : '';
                    $clave = CamposImportacion::clave($texto);

                    if ($clave === '') {
                        continue;
                    }

                    $distintos[$grupo][$clave] ??= ['nombre' => mb_substr($texto, 0, 80), 'filas' => 0];
                    $distintos[$grupo][$clave]['filas']++;
                }
            }
        }

        $empresaId = $importacion->empresa_id;
        $categorias = DB::table('categorias')->where('empresa_id', $empresaId)->get(['id', 'nombre']);
        $unidades = DB::table('unidades_medida')->where('empresa_id', $empresaId)->where('activa', true)->get(['id', 'nombre', 'abreviatura']);

        foreach ($distintos['categorias'] as $clave => &$valor) {
            $previo = $anteriores['categorias'][$clave] ?? null;
            $existente = $categorias->first(fn (object $categoria) => CamposImportacion::clave($categoria->nombre) === $clave);
            $valor['destino'] = $previo['destino'] ?? ($existente !== null ? (int) $existente->id : 'crear');
            $valor['nombre_nuevo'] = $previo['nombre_nuevo'] ?? $valor['nombre'];
        }

        unset($valor);

        foreach ($distintos['unidades'] as $clave => &$valor) {
            $previo = $anteriores['unidades'][$clave] ?? null;
            $sinonimos = collect(self::UNIDADES)->first(fn (array $grupo) => in_array($clave, $grupo, true)) ?? [$clave];
            $existente = $unidades->first(fn (object $unidad) => in_array(CamposImportacion::clave($unidad->nombre), $sinonimos, true))
                ?? $unidades->first(fn (object $unidad) => in_array(CamposImportacion::clave($unidad->abreviatura), $sinonimos, true));

            $valor = array_merge($valor, $previo ?? [], [
                'nombre' => $valor['nombre'],
                'filas' => $valor['filas'],
                'destino' => $previo['destino'] ?? ($existente !== null ? (int) $existente->id : 'crear'),
                'nombre_nuevo' => $previo['nombre_nuevo'] ?? mb_substr(Str::ucfirst($valor['nombre']), 0, 60),
                'abreviatura' => $previo['abreviatura'] ?? mb_substr($valor['nombre'], 0, 15),
                'decimales' => $previo['decimales'] ?? in_array($sinonimos[0], self::UNIDADES_CON_DECIMALES, true),
            ]);
        }

        unset($valor);

        return $distintos;
    }

    private static function letra(int $indice): string
    {
        $letra = '';

        for ($n = $indice + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letra = chr(65 + ($n - 1) % 26).$letra;
        }

        return $letra;
    }
}
