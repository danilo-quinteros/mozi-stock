<?php

namespace App\Domain\Catalogo\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Catalogo\Variantes;
use App\Domain\Inventario\StockInicial;
use App\Models\Color;
use App\Models\GrupoTalla;
use App\Models\Modelo;
use App\Models\Talla;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * Crea o edita un modelo y sus variantes (ESPECIFICACION_TALLAS_Y_COLORES.md § 4 y § 8), todo en una transacción:
 *
 * - las combinaciones son tallas × colores (sin talla o sin color = una sola fila o columna); cada una puede marcarse
 *   para no crearse;
 * - las nuevas se insertan con UNA inserción en bloque, con el nombre «Modelo · Talla · Color», el SKU sugerido o el
 *   escrito, la categoría, la unidad, el precio y el stock mínimo del modelo. Nunca escribe saldos: el stock inicial opcional del alta es una entrada «Inventario inicial» (StockInicial);
 * - editar agrega solo las combinaciones que faltan; quitar una con historial la desactiva y una sin historial se
 *   elimina; cambiar nombre, categoría o unidad se aplica a todas con una sola sentencia; el precio del modelo se
 *   copia, también con una sola sentencia, solo a las variantes sin precio propio;
 * - desactivar el modelo desactiva todas sus variantes;
 * - el SKU y el código de barras siguen siendo únicos en la empresa (validación por combinación y, ante una carrera,
 *   el índice único de la base); un error en una combinación no guarda nada;
 * - la ficha del modelo se bloquea con FOR UPDATE y después sus variantes, en ese orden.
 *
 * La foto general se guarda en el disco privado, en la carpeta de la empresa (mismas reglas que el producto).
 */
final class GuardarModelo
{
    public const DISCO = GuardarProducto::DISCO;

    private const CAMPOS = ['nombre', 'categoria_id', 'unidad_medida_id', 'sku_base', 'descripcion', 'precio_consumidor',
        'precio_minorista', 'precio_mayorista', 'stock_minimo', 'grupo_talla_id', 'activo'];

    /** Tablas cuyo registro de una variante cuenta como historial: con historial se desactiva, sin él se elimina. */
    private const HISTORIAL = ['movimientos_stock', 'pedido_items', 'venta_items', 'compra_items', 'devolucion_items', 'lotes'];

    public function __construct(
        private readonly Auditor $auditor,
        private readonly Variantes $variantes,
        private readonly StockInicial $stockInicial,
    ) {}

    /**
     * Con $stockInicial ({sucursal_id, clave, cantidades: clave «talla-color» => cantidad}), la misma transacción
     * registra la entrada «Inventario inicial» de las combinaciones (aplicarStockInicial).
     *
     * @param  array<string, mixed>  $datos  ver guardar()
     * @param  array{sucursal_id: int|string|null, clave: string, cantidades: array<mixed>}|null  $stockInicial
     */
    public function crear(User $actor, array $datos, ?UploadedFile $imagen = null, ?array $stockInicial = null): Modelo
    {
        Gate::forUser($actor)->authorize('create', Modelo::class);
        $modelo = new Modelo;
        $modelo->empresa_id = (int) $actor->empresa_id;

        if ($stockInicial === null) {
            return $this->guardar($actor, $modelo, $datos, $imagen, false);
        }

        $preparado = $this->stockInicial->preparar($actor, $stockInicial);
        $cantidades = $stockInicial['cantidades'];

        return $this->guardar($actor, $modelo, $datos, $imagen, false,
            fn (Modelo $nuevo) => $this->aplicarStockInicial($actor, $nuevo, $preparado, $cantidades));
    }

    /**
     * Stock inicial de las combinaciones recién creadas (StockInicial): una sola entrada «Inventario inicial» en la
     * sucursal elegida, con una línea por combinación con cantidad. Las celdas vacías no llevan stock; una cantidad de
     * una celda que no se creó se ignora. Sin ninguna cantidad, no se guarda nada.
     *
     * @param  array{sucursal_id: int, clave: string, lote: mixed, vencimiento: mixed}  $preparado
     * @param  array<mixed>  $cantidades  clave «talla-color» => cantidad
     */
    private function aplicarStockInicial(User $actor, Modelo $modelo, array $preparado, array $cantidades): void
    {
        $ids = DB::table('productos')->where('empresa_id', $modelo->empresa_id)->where('modelo_id', $modelo->id)
            ->get(['id', 'talla_id', 'color_id'])
            ->mapWithKeys(fn (object $v): array => [Variantes::clave($v->talla_id === null ? null : (int) $v->talla_id, $v->color_id === null ? null : (int) $v->color_id) => (int) $v->id]);
        $lineas = [];

        foreach ($cantidades as $clave => $cantidad) {
            $texto = is_scalar($cantidad) ? trim((string) $cantidad) : '';

            if ($texto !== '' && $ids->has((string) $clave)) {
                $lineas[] = ['producto_id' => (int) $ids->get((string) $clave), 'cantidad' => $texto];
            }
        }

        if ($lineas === []) {
            throw ValidationException::withMessages(['stock_inicial' => __('Escribe la cantidad de al menos una combinación o desmarca «Cargar stock inicial».')]);
        }

        $this->stockInicial->aplicar($actor, $preparado, $lineas, __('Stock inicial de :modelo', ['modelo' => $modelo->nombre]));
    }

    /**
     * @param  array<string, mixed>  $datos  ver guardar()
     */
    public function actualizar(User $actor, Modelo $modelo, array $datos, ?UploadedFile $imagen = null, bool $quitarImagen = false): Modelo
    {
        Gate::forUser($actor)->authorize('update', $modelo);

        return $this->guardar($actor, $modelo, $datos, $imagen, $quitarImagen);
    }

    /**
     * Combinaciones que resultan de las tallas y colores elegidos, en el orden de la cuadrícula. Sin tallas (o sin
     * colores) hay una sola columna (o fila) con null.
     *
     * @param  list<int>  $tallas
     * @param  list<int>  $colores
     * @return list<array{0: int|null, 1: int|null}>
     */
    public static function combinaciones(array $tallas, array $colores): array
    {
        $resultado = [];

        foreach ($colores === [] ? [null] : $colores as $color) {
            foreach ($tallas === [] ? [null] : $tallas as $talla) {
                $resultado[] = [$talla, $color];
            }
        }

        return $resultado;
    }

    /**
     * @param  array{
     *     nombre?: string, categoria_id?: int|string|null, unidad_medida_id?: int|string, sku_base?: string|null,
     *     descripcion?: string|null, precio_consumidor?: string, precio_minorista?: string|null, precio_mayorista?: string|null,
     *     stock_minimo?: string, grupo_talla_id?: int|string|null, tallas?: list<int|string>, colores?: list<int|string>,
     *     combinaciones?: array<string, array{crear?: bool, sku?: string|null, codigo_barras?: string|null}>, activo?: bool
     * }|array<string, mixed>  $datos  combinaciones por clave «talla-color» (Variantes::clave); sin «sku» se usa el sugerido
     */
    private function guardar(User $actor, Modelo $modelo, array $datos, ?UploadedFile $imagen, bool $quitarImagen, ?callable $despues = null): Modelo
    {
        $empresaId = (int) $actor->empresa_id;
        abort_if($modelo->exists && $modelo->empresa_id !== $empresaId, 404);
        $this->variantes->exigir($empresaId);

        $imagenAnterior = $modelo->imagen_path;
        $imagenNueva = $imagen?->storeAs("empresas/{$empresaId}/modelos", Str::random(40).'.'.$imagen->extension(), self::DISCO);

        if ($imagen !== null && $imagenNueva === false) {
            throw ValidationException::withMessages(['imagen' => __('No se pudo guardar la imagen.')]);
        }

        try {
            $guardado = DB::transaction(function () use ($actor, $modelo, $datos, $empresaId, $imagenNueva, $quitarImagen, $despues): Modelo {
                $bloqueado = $modelo->exists ? Modelo::query()->whereKey($modelo->id)->lockForUpdate()->firstOrFail() : $modelo;
                $antes = $bloqueado->exists ? $this->resumen($bloqueado) : null;
                $valores = $this->validarModelo($empresaId, $bloqueado, $datos);

                foreach ($valores as $campo => $valor) {
                    $bloqueado->setAttribute($campo, $valor);
                }

                if (is_string($imagenNueva)) {
                    $bloqueado->imagen_path = $imagenNueva;
                } elseif ($quitarImagen) {
                    $bloqueado->imagen_path = null;
                }

                $cambios = [
                    'nombre' => $bloqueado->exists && $bloqueado->isDirty('nombre'),
                    'clasificacion' => $bloqueado->exists && $bloqueado->isDirty(['categoria_id', 'unidad_medida_id']),
                    'precio' => $bloqueado->exists && $bloqueado->isDirty(['precio_consumidor', 'precio_minorista', 'precio_mayorista']),
                ];

                try {
                    $bloqueado->save();
                } catch (QueryException $e) {
                    throw $this->duplicado($e, 'nombre', __('Ya existe un modelo con ese nombre.'));
                }

                $resultado = $this->sincronizarVariantes($empresaId, $bloqueado, $datos, $cambios);
                $this->auditor->registrar($antes === null ? 'modelo.creado' : 'modelo.actualizado', $bloqueado, $actor, $empresaId,
                    $antes, $this->resumen($bloqueado) + ['variantes' => $resultado]);

                if ($despues !== null) {
                    $despues($bloqueado);
                }

                return $bloqueado;
            });
        } catch (Throwable $e) {
            if (is_string($imagenNueva)) {
                Storage::disk(self::DISCO)->delete($imagenNueva);
            }

            throw $e;
        }

        $modelo->setRawAttributes($guardado->getAttributes(), true);
        $modelo->exists = true;

        if (is_string($imagenAnterior) && $imagenAnterior !== $modelo->imagen_path) {
            Storage::disk(self::DISCO)->delete($imagenAnterior);
        }

        return $modelo;
    }

    /**
     * Valida los datos de la ficha del modelo (sin variantes). También la usa AgruparEnModelo para un modelo nuevo.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function validarModelo(int $empresaId, Modelo $modelo, array $datos): array
    {
        $errores = [];
        $nombre = trim((string) ($datos['nombre'] ?? ''));

        if ($nombre === '' || mb_strlen($nombre) > Variantes::MAX_NOMBRE_MODELO) {
            $errores['nombre'] = __('Escribe un nombre de hasta :max caracteres.', ['max' => Variantes::MAX_NOMBRE_MODELO]);
        } elseif (DB::table('modelos')->where('empresa_id', $empresaId)->where('nombre', $nombre)->when($modelo->exists, fn ($q) => $q->where('id', '!=', $modelo->id))->exists()) {
            $errores['nombre'] = __('Ya existe un modelo con ese nombre.');
        }

        $categoriaId = filled($datos['categoria_id'] ?? null) ? (int) $datos['categoria_id'] : null;
        $unidadId = (int) ($datos['unidad_medida_id'] ?? 0);

        if ($categoriaId !== null && ! DB::table('categorias')->where('empresa_id', $empresaId)->where('id', $categoriaId)->exists()) {
            $errores['categoria_id'] = __('Selecciona una categoría de tu empresa.');
        }

        if (! DB::table('unidades_medida')->where('empresa_id', $empresaId)->where('id', $unidadId)->exists()) {
            $errores['unidad_medida_id'] = __('Selecciona una unidad de medida de tu empresa.');
        }

        $skuBase = Str::upper(trim((string) ($datos['sku_base'] ?? '')));

        if ($skuBase !== '' && (mb_strlen($skuBase) > Variantes::MAX_SKU_BASE || preg_match(Variantes::PATRON_SKU, $skuBase) !== 1)) {
            $errores['sku_base'] = __('Usa hasta :max letras, números, puntos, guiones o barras.', ['max' => Variantes::MAX_SKU_BASE]);
        }

        $descripcion = trim((string) ($datos['descripcion'] ?? ''));

        if (mb_strlen($descripcion) > 2000) {
            $errores['descripcion'] = __('La descripción admite hasta 2000 caracteres.');
        }

        $precios = [];

        foreach (['precio_consumidor' => true, 'precio_minorista' => false, 'precio_mayorista' => false] as $campo => $obligatorio) {
            $valor = $datos[$campo] ?? null;

            if (! $obligatorio && ! filled($valor)) {
                $precios[$campo] = null;

                continue;
            }

            $precio = Decimal::normalizar(is_scalar($valor) ? (string) $valor : '', Decimal::ESCALA_DINERO);

            if ($precio === null || Decimal::comparar($precio, '0', Decimal::ESCALA_DINERO) < 0) {
                $errores[$campo] = __('Ingresa un precio válido en Bs, mayor o igual a 0 y con hasta 2 decimales.');
            }

            $precios[$campo] = $precio;
        }

        $minimo = Decimal::normalizar((string) ($datos['stock_minimo'] ?? '0'), Decimal::ESCALA_CANTIDAD);

        if ($minimo === null || Decimal::comparar($minimo, '0') < 0) {
            $errores['stock_minimo'] = __('Ingresa un mínimo mayor o igual a 0.');
        }

        $grupoId = filled($datos['grupo_talla_id'] ?? null) ? (int) $datos['grupo_talla_id'] : null;

        // Un grupo desactivado ya no se ofrece para modelos nuevos; el que el modelo ya usa se conserva.
        if ($grupoId !== null && ! GrupoTalla::query()->where('empresa_id', $empresaId)->whereKey($grupoId)
            ->where(fn ($q) => $q->where('activo', true)->when($modelo->grupo_talla_id !== null, fn ($q) => $q->orWhere('id', $modelo->grupo_talla_id)))->exists()) {
            $errores['grupo_talla_id'] = __('Elige un grupo de tallas activo de tu empresa.');
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return [
            'nombre' => $nombre,
            'categoria_id' => $categoriaId,
            'unidad_medida_id' => $unidadId,
            'sku_base' => $skuBase === '' ? null : $skuBase,
            'descripcion' => $descripcion === '' ? null : $descripcion,
            'precio_consumidor' => $precios['precio_consumidor'],
            'precio_minorista' => $precios['precio_minorista'],
            'precio_mayorista' => $precios['precio_mayorista'],
            'stock_minimo' => $minimo,
            'grupo_talla_id' => $grupoId,
            'activo' => $modelo->exists && array_key_exists('activo', $datos) ? (bool) $datos['activo'] : ($modelo->exists ? $modelo->activo : true),
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array{nombre: bool, clasificacion: bool, precio: bool}  $cambios
     * @return array<string, int>
     */
    private function sincronizarVariantes(int $empresaId, Modelo $modelo, array $datos, array $cambios): array
    {
        $tallaIds = $this->ids($datos['tallas'] ?? []);
        $colorIds = $this->ids($datos['colores'] ?? []);
        /** @var array<string, array<string, mixed>> $opciones */
        $opciones = is_array($datos['combinaciones'] ?? null) ? $datos['combinaciones'] : [];

        $existentes = DB::table('productos')->where('empresa_id', $empresaId)->where('modelo_id', $modelo->id)->orderBy('id')
            ->lockForUpdate()->get(['id', 'talla_id', 'color_id', 'activo', 'sku', 'codigo_barras'])
            ->keyBy(fn (object $v): string => Variantes::clave($v->talla_id === null ? null : (int) $v->talla_id, $v->color_id === null ? null : (int) $v->color_id));

        $tallas = $this->tallas($empresaId, $modelo->grupo_talla_id, $tallaIds);
        $colores = $this->colores($empresaId, $colorIds);

        $deseadas = [];

        foreach (self::combinaciones($tallaIds, $colorIds) as [$tallaId, $colorId]) {
            $clave = Variantes::clave($tallaId, $colorId);

            if ((bool) ($opciones[$clave]['crear'] ?? true)) {
                $deseadas[$clave] = [$tallaId, $colorId];
            }
        }

        if ($deseadas === []) {
            throw ValidationException::withMessages(['combinaciones' => __('El modelo debe tener al menos una combinación.')]);
        }

        // Una talla o un color desactivados no se usan en combinaciones nuevas (§ 3.3).
        $errores = [];

        foreach ($deseadas as $clave => [$tallaId, $colorId]) {
            if ($existentes->has($clave)) {
                continue;
            }

            if ($tallaId !== null && ! $tallas[$tallaId]->activa) {
                $errores['tallas'] = __('La talla :talla está desactivada: no se usa en combinaciones nuevas.', ['talla' => $tallas[$tallaId]->nombre]);
            }

            if ($colorId !== null && ! $colores[$colorId]->activo) {
                $errores['colores'] = __('El color :color está desactivado: no se usa en combinaciones nuevas.', ['color' => $colores[$colorId]->nombre]);
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        // Quitar (§ 4.3): con historial se desactiva; sin historial se elimina y libera su SKU y su código.
        $quitar = $existentes->filter(fn (object $v, string $clave): bool => (bool) $v->activo && ! isset($deseadas[$clave]));
        $sinHistorial = $this->sinHistorial($empresaId, array_values($quitar->pluck('id')->map(fn ($id): int => (int) $id)->all()));
        $eliminar = $quitar->filter(fn (object $v): bool => in_array((int) $v->id, $sinHistorial, true));
        $desactivar = $quitar->reject(fn (object $v): bool => in_array((int) $v->id, $sinHistorial, true));
        $codigos = $this->codigos($empresaId, $modelo, $deseadas, $existentes, $opciones, $tallas, $colores, $sinHistorial);

        $resultado = ['creadas' => 0, 'reactivadas' => 0, 'desactivadas' => 0, 'eliminadas' => 0, 'renombradas' => 0, 'con_precio_del_modelo' => 0];

        if ($eliminar->isNotEmpty()) {
            $ids = $eliminar->pluck('id')->all();
            DB::table('stock_niveles')->where('empresa_id', $empresaId)->whereIn('producto_id', $ids)->delete();
            $resultado['eliminadas'] = DB::table('productos')->where('empresa_id', $empresaId)->whereIn('id', $ids)->delete();
        }

        $ahora = now();

        if ($desactivar->isNotEmpty()) {
            $resultado['desactivadas'] = DB::table('productos')->where('empresa_id', $empresaId)->whereIn('id', $desactivar->pluck('id')->all())
                ->update(['activo' => false, 'updated_at' => $ahora]);
        }

        // SKU y código de barras editados en combinaciones que ya existían. Primero se liberan (NULL) los que cambian,
        // para que dos variantes puedan intercambiarlos sin chocar con el índice único.
        $actualizar = [];

        foreach ($deseadas as $clave => $par) {
            $variante = $existentes->get($clave);

            if ($variante !== null && ($variante->sku !== $codigos[$clave]['sku'] || $variante->codigo_barras !== $codigos[$clave]['codigo_barras'])) {
                $actualizar[(int) $variante->id] = $codigos[$clave];
            }
        }

        if ($actualizar !== []) {
            DB::table('productos')->where('empresa_id', $empresaId)->whereIn('id', array_keys($actualizar))->update(['sku' => null, 'codigo_barras' => null]);

            foreach ($actualizar as $id => $codigo) {
                $this->escribir(fn () => DB::table('productos')->where('empresa_id', $empresaId)->where('id', $id)->update($codigo + ['updated_at' => $ahora]));
            }
        }

        // Activas: las combinaciones elegidas, si el modelo está activo. Desactivar el modelo desactiva todas (§ 8.6).
        $elegidas = $existentes->filter(fn (object $v, string $clave): bool => isset($deseadas[$clave]))->pluck('id')->all();

        if ($elegidas !== []) {
            $resultado['reactivadas'] = $modelo->activo
                ? DB::table('productos')->where('empresa_id', $empresaId)->whereIn('id', $elegidas)->where('activo', false)->update(['activo' => true, 'updated_at' => $ahora])
                : 0;

            if (! $modelo->activo) {
                $resultado['desactivadas'] += DB::table('productos')->where('empresa_id', $empresaId)->whereIn('id', $elegidas)->where('activo', true)
                    ->update(['activo' => false, 'updated_at' => $ahora]);
            }
        }

        if ($cambios['clasificacion']) {
            DB::table('productos')->where('empresa_id', $empresaId)->where('modelo_id', $modelo->id)
                ->update(['categoria_id' => $modelo->categoria_id, 'unidad_medida_id' => $modelo->unidad_medida_id, 'updated_at' => $ahora]);
        }

        if ($cambios['precio']) {
            // § 4.4: solo las variantes que usan el precio del modelo; las de precio propio no cambian.
            $resultado['con_precio_del_modelo'] = DB::table('productos')->where('empresa_id', $empresaId)->where('modelo_id', $modelo->id)->where('precio_propio', false)
                ->update(['precio_consumidor' => $modelo->precio_consumidor, 'precio_minorista' => $modelo->precio_minorista,
                    'precio_mayorista' => $modelo->precio_mayorista, 'updated_at' => $ahora]);
        }

        if ($cambios['nombre']) {
            $resultado['renombradas'] = Variantes::regenerarNombres($empresaId, 'modelo_id', $modelo->id);
        }

        $nuevas = [];

        foreach ($deseadas as $clave => [$tallaId, $colorId]) {
            if ($existentes->has($clave)) {
                continue;
            }

            $nuevas[] = [
                'empresa_id' => $empresaId, 'modelo_id' => $modelo->id, 'talla_id' => $tallaId, 'color_id' => $colorId,
                'categoria_id' => $modelo->categoria_id, 'unidad_medida_id' => $modelo->unidad_medida_id, 'tipo_venta' => 'unidad',
                'sku' => $codigos[$clave]['sku'], 'codigo_barras' => $codigos[$clave]['codigo_barras'],
                'nombre' => Variantes::nombre($modelo->nombre, $tallaId === null ? null : $tallas[$tallaId]->nombre, $colorId === null ? null : $colores[$colorId]->nombre),
                'precio_consumidor' => $modelo->precio_consumidor, 'precio_minorista' => $modelo->precio_minorista,
                'precio_mayorista' => $modelo->precio_mayorista, 'precio_propio' => false,
                'stock_minimo' => $modelo->stock_minimo, 'activo' => $modelo->activo, 'controla_stock' => true, 'controla_vencimiento' => false,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ];
        }

        if ($nuevas !== []) {
            // Una sola inserción en bloque (§ 4.2.4); el índice único de clave_variante y los de SKU y código de
            // barras rechazan cualquier choque que la validación no haya visto (carrera con otra petición).
            $this->escribir(fn () => DB::table('productos')->insert($nuevas));
            $resultado['creadas'] = count($nuevas);
        }

        return array_filter($resultado);
    }

    /**
     * SKU y código de barras finales de cada combinación elegida, validados: formato, repetidos en el mismo modelo y
     * ya usados por otro producto de la empresa (§ 8.3 y § 8.4). Los errores van por combinación.
     *
     * @param  array<string, array{0: int|null, 1: int|null}>  $deseadas
     * @param  Collection<string, stdClass>  $existentes
     * @param  array<string, array<string, mixed>>  $opciones
     * @param  array<int, Talla>  $tallas
     * @param  array<int, Color>  $colores
     * @param  list<int>  $liberadas  variantes que se eliminan (su SKU queda libre)
     * @return array<string, array{sku: string|null, codigo_barras: string|null}>
     */
    private function codigos(int $empresaId, Modelo $modelo, array $deseadas, Collection $existentes, array $opciones, array $tallas, array $colores, array $liberadas): array
    {
        $codigos = [];
        $errores = [];

        foreach ($deseadas as $clave => [$tallaId, $colorId]) {
            $variante = $existentes->get($clave);
            $opcion = $opciones[$clave] ?? [];

            if (array_key_exists('sku', $opcion)) {
                $sku = Str::upper(trim((string) $opcion['sku']));
            } elseif ($variante !== null) {
                $sku = (string) $variante->sku;
            } else {
                $sku = (string) Variantes::sku($modelo->sku_base, $tallaId === null ? null : $tallas[$tallaId]->nombre, $colorId === null ? null : $colores[$colorId]->codigo);
            }

            $codigo = array_key_exists('codigo_barras', $opcion) ? trim((string) $opcion['codigo_barras']) : (string) ($variante->codigo_barras ?? '');

            if ($sku !== '' && (mb_strlen($sku) > Variantes::MAX_SKU || preg_match(Variantes::PATRON_SKU, $sku) !== 1)) {
                $errores["combinaciones.{$clave}.sku"] = __('Usa hasta :max letras, números, puntos, guiones o barras.', ['max' => Variantes::MAX_SKU]);
            }

            if (mb_strlen($codigo) > 64) {
                $errores["combinaciones.{$clave}.codigo_barras"] = __('El código de barras admite hasta 64 caracteres.');
            }

            $codigos[$clave] = ['sku' => $sku === '' ? null : $sku, 'codigo_barras' => $codigo === '' ? null : $codigo];
        }

        foreach (['sku' => __('Ese código ya está en otra combinación del modelo.'), 'codigo_barras' => __('Ese código de barras ya está en otra combinación del modelo.')] as $campo => $mensaje) {
            $valores = array_filter(array_map(fn (array $c): ?string => $c[$campo] === null ? null : Str::lower($c[$campo]), $codigos));

            foreach (array_keys(array_filter(array_count_values($valores), fn (int $n): bool => $n > 1)) as $repetido) {
                foreach (array_keys($valores, $repetido, true) as $clave) {
                    $errores["combinaciones.{$clave}.{$campo}"] ??= $mensaje;
                }
            }
        }

        // Las variantes elegidas que ya existían reciben los valores de $codigos (ya revisados entre sí): sus valores
        // actuales en la base no cuentan como choque, así dos combinaciones pueden intercambiar su SKU.
        $ignorar = array_merge($liberadas, $existentes->filter(fn (object $v, string $clave): bool => isset($deseadas[$clave]))
            ->pluck('id')->map(fn ($id): int => (int) $id)->values()->all());

        foreach (['sku' => __('Ya existe un producto con ese código en tu empresa.'), 'codigo_barras' => __('Ese código de barras ya está asignado a otro producto.')] as $campo => $mensaje) {
            $buscados = array_values(array_unique(array_filter(array_column($codigos, $campo))));

            if ($buscados === []) {
                continue;
            }

            $usados = DB::table('productos')->where('empresa_id', $empresaId)->whereIn($campo, $buscados)
                ->whereNotIn('id', $ignorar === [] ? [0] : $ignorar)->pluck($campo)->map(fn ($valor): string => Str::lower((string) $valor))->all();

            foreach ($codigos as $clave => $codigo) {
                if ($codigo[$campo] !== null && in_array(Str::lower($codigo[$campo]), $usados, true)) {
                    $errores["combinaciones.{$clave}.{$campo}"] ??= $mensaje;
                }
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return $codigos;
    }

    /**
     * Tallas elegidas: del grupo del modelo y de la empresa. Sin grupo no hay tallas.
     *
     * @param  list<int>  $ids
     * @return array<int, Talla>
     */
    private function tallas(int $empresaId, ?int $grupoId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        if ($grupoId === null) {
            throw ValidationException::withMessages(['tallas' => __('Elige un grupo de tallas o «Sin talla».')]);
        }

        $tallas = Talla::query()->where('empresa_id', $empresaId)->where('grupo_talla_id', $grupoId)->whereIn('id', $ids)->get()->keyBy('id')->all();

        if (count($tallas) !== count($ids)) {
            throw ValidationException::withMessages(['tallas' => __('Elige tallas del grupo seleccionado.')]);
        }

        return $tallas;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, Color>
     */
    private function colores(int $empresaId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $colores = Color::query()->where('empresa_id', $empresaId)->whereIn('id', $ids)->get()->keyBy('id')->all();

        if (count($colores) !== count($ids)) {
            throw ValidationException::withMessages(['colores' => __('Elige colores de tu empresa.')]);
        }

        return $colores;
    }

    /**
     * Variantes sin ningún registro en el historial y con stock 0 en todas las sucursales: se pueden eliminar (§ 4.3).
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function sinHistorial(int $empresaId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $conHistorial = DB::table('stock_niveles')->where('empresa_id', $empresaId)->whereIn('producto_id', $ids)->where('cantidad', '!=', 0)
            ->pluck('producto_id')->map(fn ($id): int => (int) $id)->all();

        foreach (self::HISTORIAL as $tabla) {
            $conHistorial = array_merge($conHistorial, DB::table($tabla)->where('empresa_id', $empresaId)->whereIn('producto_id', $ids)
                ->distinct()->pluck('producto_id')->map(fn ($id): int => (int) $id)->all());
        }

        return array_values(array_diff($ids, $conHistorial));
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $valores): array
    {
        if (! is_array($valores)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter($valores, fn ($v): bool => is_scalar($v) && ctype_digit((string) $v) && (int) $v > 0))));
    }

    /**
     * Escritura que puede chocar con un índice único (SKU, código de barras o combinación repetida por una carrera).
     */
    private function escribir(callable $escritura): void
    {
        try {
            $escritura();
        } catch (QueryException $e) {
            throw $this->duplicado($e, 'combinaciones', __('Otra persona guardó al mismo tiempo un código o una combinación igual. Revisa los datos y vuelve a guardar.'));
        }
    }

    private function duplicado(QueryException $e, string $campo, string $mensaje): Throwable
    {
        // 23000 / 1062: clave duplicada. Cualquier otro error se propaga tal cual.
        return ($e->errorInfo[1] ?? null) === 1062 ? ValidationException::withMessages([$campo => $mensaje]) : $e;
    }

    /**
     * @return array<string, mixed>
     */
    private function resumen(Modelo $modelo): array
    {
        return $modelo->only(self::CAMPOS) + ['imagen' => $modelo->imagen_path !== null];
    }
}
