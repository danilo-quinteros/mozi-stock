<?php

namespace App\Domain\Catalogo\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Catalogo\GeneradorSku;
use App\Domain\Catalogo\Variantes;
use App\Domain\Inventario\ControlLotes;
use App\Domain\Inventario\DatosLote;
use App\Domain\Inventario\LibroLotes;
use App\Domain\Inventario\StockInicial;
use App\Models\Producto;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Alta y edición de la ficha de producto. Nunca escribe saldos directamente: las existencias solo
 * cambian con operaciones de inventario por sucursal (el stock inicial del alta es una entrada «Inventario inicial»). La imagen se guarda en el disco
 * privado, en una carpeta de la empresa y con nombre aleatorio.
 *
 * Casilla «Controla stock» (ESPECIFICACION_PRODUCTOS_SIN_STOCK.md § 4.5): marcada por defecto. Desmarcarla
 * exige stock 0 en todas las sucursales, comprobado en la transacción con FOR UPDATE sobre la ficha y sus
 * saldos (las ventas leen la marca con bloqueo compartido). Volver a marcarla siempre se permite: el
 * producto sigue con stock 0 y necesita una entrada o una compra antes de venderse.
 *
 * Un producto con «Controla lote y vencimiento» no puede dejar de controlar stock (§ 4.6).
 *
 * Casilla «Controla lote y vencimiento» (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 5.6), solo con la capacidad activa
 * (sin ella la marca queda como está):
 * - marcarla en un producto con stock pasa ese stock al lote de regularización de cada sucursal (ControlLotes), sin
 *   cambiar el total;
 * - desmarcarla solo se permite si ningún lote con número tiene stock; el de regularización queda en cero;
 * - cada cambio queda en la auditoría del producto y en el libro por lote.
 *
 * Variantes de un modelo (ESPECIFICACION_TALLAS_Y_COLORES.md § 4.3, § 4.4 y § 8):
 * - con la capacidad activa, nombre, contenido, forma de venta, categoría, unidad, precios y estado los gobierna el
 *   modelo (GuardarModelo y CambiarPrecioVariante): aquí se conservan aunque la petición traiga otros valores;
 * - con la capacidad apagada la variante se edita como un producto suelto (rubros § 5.2). Si se le cambia el precio
 *   y deja de coincidir con el del modelo, queda con precio propio: al reactivar, el precio del modelo no la pisa.
 */
final class GuardarProducto
{
    public const DISCO = 'local';

    private const CAMPOS = ['nombre', 'marca', 'contenido', 'tipo_venta', 'sku', 'codigo_barras', 'descripcion', 'categoria_id', 'unidad_medida_id',
        'precio_consumidor', 'precio_minorista', 'precio_mayorista', 'stock_minimo', 'activo', 'controla_stock', 'controla_vencimiento'];

    public function __construct(
        private readonly Auditor $auditor,
        private readonly LibroLotes $lotes,
        private readonly ControlLotes $control,
        private readonly Variantes $variantes,
        private readonly StockInicial $stockInicial,
        private readonly GeneradorSku $skus,
    ) {}

    /**
     * Con $stockInicial ({sucursal_id, cantidad, lote?, vencimiento?, clave}), la misma transacción registra la entrada
     * «Inventario inicial» (StockInicial): si la entrada falla, el producto tampoco se crea.
     *
     * @param  array<string, mixed>  $datos
     * @param  array{sucursal_id: int|string|null, cantidad: string, lote?: mixed, vencimiento?: mixed, clave: string}|null  $stockInicial
     */
    public function crear(User $actor, array $datos, ?UploadedFile $imagen = null, ?array $stockInicial = null): Producto
    {
        Gate::forUser($actor)->authorize('create', Producto::class);

        $producto = new Producto;
        $producto->empresa_id = (int) $actor->empresa_id;
        $producto->activo = true;

        if ($stockInicial === null) {
            return $this->guardar($actor, $producto, $datos, $imagen, false, 'producto.creado');
        }

        if (array_key_exists('controla_stock', $datos) && ! (bool) $datos['controla_stock']) {
            throw ValidationException::withMessages(['stock_inicial' => __('Un producto sin control de stock no lleva stock inicial.')]);
        }

        $preparado = $this->stockInicial->preparar($actor, $stockInicial);

        return $this->guardar($actor, $producto, $datos, $imagen, false, 'producto.creado',
            fn (Producto $nuevo) => $this->stockInicial->aplicar($actor, $preparado,
                [['producto_id' => $nuevo->id, 'cantidad' => (string) $stockInicial['cantidad']]],
                __('Stock inicial de :producto', ['producto' => $nuevo->nombre])));
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(User $actor, Producto $producto, array $datos, ?UploadedFile $imagen = null, bool $quitarImagen = false): Producto
    {
        Gate::forUser($actor)->authorize('update', $producto);

        return $this->guardar($actor, $producto, $datos, $imagen, $quitarImagen, 'producto.actualizado');
    }

    public function actualizarPrecioConsumidor(User $actor, Producto $producto, string $nuevoPrecio): Producto
    {
        Gate::forUser($actor)->authorize('update', $producto);

        $precio = $this->precio($nuevoPrecio);
        if ($precio === null) {
            throw ValidationException::withMessages([
                'precio_consumidor' => __('Ingresa un precio válido en Bs, mayor o igual a 0 y con hasta 2 decimales.'),
            ]);
        }

        if ($this->gobernadaPorModelo($producto)) {
            throw ValidationException::withMessages([
                'precio_consumidor' => __('Esta combinación usa el precio de su modelo. Cámbialo desde el modelo.'),
            ]);
        }

        $antes = $this->resumen($producto);
        $producto->precio_consumidor = $precio;
        $this->marcarPrecioPropio($producto);

        DB::transaction(function () use ($actor, $producto, $antes): void {
            $producto->save();
            $this->auditor->registrar('producto.actualizado', $producto, $actor, $producto->empresa_id, $antes, $this->resumen($producto));
        });

        return $producto;
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  (callable(Producto): mixed)|null  $despues  dentro de la transacción, con la ficha ya guardada
     */
    private function guardar(User $actor, Producto $producto, array $datos, ?UploadedFile $imagen, bool $quitarImagen, string $accion, ?callable $despues = null): Producto
    {
        $empresaId = (int) $actor->empresa_id;
        $antes = $producto->exists ? $this->resumen($producto) : null;
        $imagenAnterior = $producto->imagen_path;

        if ($this->gobernadaPorModelo($producto)) {
            $datos = $this->datosDelModelo($producto) + $datos;
        }

        $this->aplicar($empresaId, $producto, $datos);
        // «Auto»: sin código escrito, se genera al guardar (la factura del SIN necesita uno por producto).
        $skuAuto = (bool) ($datos['sku_auto'] ?? false) && $producto->sku === null;

        if ($producto->exists && array_key_exists('activo', $datos)) {
            $producto->activo = (bool) $datos['activo'];
        }

        $this->marcarPrecioPropio($producto);

        $imagenNueva = $imagen?->storeAs("empresas/{$empresaId}/productos", Str::random(40).'.'.$imagen->extension(), self::DISCO);

        if ($imagen !== null && $imagenNueva === false) {
            throw ValidationException::withMessages(['imagen' => __('No se pudo guardar la imagen.')]);
        }

        if (is_string($imagenNueva)) {
            $producto->imagen_path = $imagenNueva;
        } elseif ($quitarImagen) {
            $producto->imagen_path = null;
        }

        try {
            $this->exigirStockConVencimiento($producto->controla_stock, $producto->controla_vencimiento);

            DB::transaction(function () use ($actor, $producto, $accion, $antes, $despues, $skuAuto): void {
                $cambioLote = $producto->exists ? $this->revisarControlDeStock($producto) : null;

                if ($skuAuto) {
                    $producto->sku = $this->skus->generar($producto->empresa_id, $producto->nombre);
                }

                $producto->save();

                if ($cambioLote === true) {
                    $this->control->iniciar($actor, $producto->empresa_id, [$producto->id], __('Se marcó «Controla lote y vencimiento» en :producto: su stock pasa al lote de regularización.', ['producto' => $producto->nombre]));
                } elseif ($cambioLote === false) {
                    $this->control->vaciar($actor, $producto->empresa_id, [$producto->id], __('Se desmarcó «Controla lote y vencimiento» en :producto.', ['producto' => $producto->nombre]));
                }

                $this->auditor->registrar($accion, $producto, $actor, $producto->empresa_id, $antes, $this->resumen($producto));

                if ($despues !== null) {
                    $despues($producto);
                }
            });
        } catch (Throwable $e) {
            if (is_string($imagenNueva)) {
                Storage::disk(self::DISCO)->delete($imagenNueva);
            }

            throw $e;
        }

        if (is_string($imagenAnterior) && $imagenAnterior !== $producto->imagen_path) {
            Storage::disk(self::DISCO)->delete($imagenAnterior);
        }

        return $producto;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function aplicar(int $empresaId, Producto $producto, array $datos): void
    {
        $categoriaId = filled($datos['categoria_id'] ?? null) ? (int) $datos['categoria_id'] : null;
        $unidadId = (int) ($datos['unidad_medida_id'] ?? 0);
        $errores = [];

        if ($categoriaId !== null && ! DB::table('categorias')->where('empresa_id', $empresaId)->where('id', $categoriaId)->exists()) {
            $errores['categoria_id'] = __('Selecciona una categoría de tu empresa.');
        }

        if (! DB::table('unidades_medida')->where('empresa_id', $empresaId)->where('id', $unidadId)->exists()) {
            $errores['unidad_medida_id'] = __('Selecciona una unidad de medida de tu empresa.');
        }

        $contenido = filled($datos['contenido'] ?? null)
            ? Decimal::normalizar((string) $datos['contenido'], Decimal::ESCALA_CANTIDAD)
            : null;

        if (filled($datos['contenido'] ?? null) && ($contenido === null || Decimal::comparar($contenido, '0') <= 0)) {
            $errores['contenido'] = __('Ingresa un contenido válido mayor a 0 (hasta 3 decimales).');
        }

        $consumidor = $this->precio($datos['precio_consumidor'] ?? null);
        $minorista = filled($datos['precio_minorista'] ?? null) ? $this->precio($datos['precio_minorista']) : '';
        $mayorista = filled($datos['precio_mayorista'] ?? null) ? $this->precio($datos['precio_mayorista']) : '';
        $minimo = Decimal::normalizar((string) ($datos['stock_minimo'] ?? '0'), Decimal::ESCALA_CANTIDAD);

        foreach (['precio_consumidor' => $consumidor, 'precio_minorista' => $minorista, 'precio_mayorista' => $mayorista] as $campo => $valor) {
            if ($valor === null) {
                $errores[$campo] = __('Ingresa un precio válido en Bs, mayor o igual a 0 y con hasta 2 decimales.');
            }
        }

        if ($minimo === null || Decimal::comparar($minimo, '0') < 0) {
            $errores['stock_minimo'] = __('Ingresa un mínimo mayor o igual a 0.');
        }

        // Marca: solo cambia si viene en los datos (otras llamadas no la borran sin querer).
        $marca = array_key_exists('marca', $datos) ? Producto::normalizarMarca(is_string($datos['marca']) ? $datos['marca'] : null) : $producto->marca;

        if ($marca !== null && mb_strlen($marca) > Producto::MAX_MARCA) {
            $errores['marca'] = __('La marca admite hasta :max caracteres.', ['max' => Producto::MAX_MARCA]);
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        $producto->nombre = trim((string) $datos['nombre']);
        $producto->marca = $marca;
        $producto->contenido = $contenido;
        $tipoVenta = $datos['tipo_venta'] ?? 'unidad';
        $producto->tipo_venta = $tipoVenta === 'medida' ? 'medida' : 'unidad';
        $sku = trim((string) ($datos['sku'] ?? ''));
        $producto->sku = $sku === '' ? null : Str::upper($sku);
        $codigo = trim((string) ($datos['codigo_barras'] ?? ''));
        $producto->codigo_barras = $codigo === '' ? null : $codigo;
        $descripcion = trim((string) ($datos['descripcion'] ?? ''));
        $producto->descripcion = $descripcion === '' ? null : $descripcion;
        $producto->categoria_id = $categoriaId;
        $producto->unidad_medida_id = $unidadId;
        $producto->precio_consumidor = (string) $consumidor;
        $producto->precio_minorista = $minorista === '' ? null : $minorista;
        $producto->precio_mayorista = $mayorista === '' ? null : $mayorista;
        $producto->stock_minimo = (string) $minimo;

        if (array_key_exists('controla_stock', $datos)) {
            $producto->controla_stock = (bool) $datos['controla_stock'];
        }

        // Sin la capacidad la empresa no ve la casilla y la marca queda como está (§ 3).
        if (array_key_exists('controla_vencimiento', $datos) && $this->lotes->activo($empresaId)) {
            $producto->controla_vencimiento = (bool) $datos['controla_vencimiento'];
        } elseif (! $producto->controla_stock && ! $this->lotes->activo($empresaId)) {
            // Sin la capacidad la casilla no se ve: dejar de controlar stock quita también la marca de lote (sus saldos
            // por lote ya quedaron en cero al apagar la capacidad; exigirLotesVacios lo vuelve a comprobar).
            $producto->controla_vencimiento = false;
        }
    }

    /**
     * Marcada → desmarcada solo con stock 0 en todas las sucursales (§ 4.5). Bloquea la ficha y luego sus
     * saldos, en el mismo orden que una venta (ficha con bloqueo compartido, después saldos), sin cruces.
     *
     * Devuelve el cambio de la marca de lote: true (se marca), false (se desmarca) o null (sin cambio). Desmarcarla se
     * rechaza si algún lote con número tiene stock (lotes § 5.6.2).
     */
    private function revisarControlDeStock(Producto $producto): ?bool
    {
        $actual = DB::table('productos')->where('empresa_id', $producto->empresa_id)->where('id', $producto->id)
            ->lockForUpdate()->first(['controla_stock', 'controla_vencimiento']);
        $tenia = (bool) ($actual->controla_vencimiento ?? false);

        if (! $producto->isDirty('controla_vencimiento')) {
            // Esta edición no toca la marca: vale la de la base, ya bloqueada (otra pantalla pudo cambiarla).
            $producto->controla_vencimiento = $tenia;
        }

        $cambioLote = $tenia === (bool) $producto->controla_vencimiento ? null : (bool) $producto->controla_vencimiento;
        $this->exigirStockConVencimiento($producto->controla_stock, $producto->controla_vencimiento);

        if ($cambioLote === false) {
            $this->exigirLotesVacios($producto);
        }

        if ($producto->controla_stock || ! (bool) ($actual->controla_stock ?? false)) {
            return $cambioLote;
        }

        $conSaldo = DB::table('stock_niveles')->where('empresa_id', $producto->empresa_id)->where('producto_id', $producto->id)
            ->orderBy('sucursal_id')->lockForUpdate()->get(['sucursal_id', 'cantidad'])
            ->filter(fn (object $nivel) => Decimal::comparar((string) $nivel->cantidad, '0') !== 0);

        if ($conSaldo->isEmpty()) {
            return $cambioLote;
        }

        $nombres = DB::table('sucursales')->where('empresa_id', $producto->empresa_id)
            ->whereIn('id', $conSaldo->pluck('sucursal_id')->all())->pluck('nombre', 'id');
        $detalle = $conSaldo->map(fn (object $nivel) => __(':sucursal (:cantidad)', [
            'sucursal' => $nombres[$nivel->sucursal_id] ?? '—', 'cantidad' => Decimal::formatear((string) $nivel->cantidad),
        ]))->implode(', ');

        throw ValidationException::withMessages(['controla_stock' => __('Para dejar de controlar el stock, primero llévalo a 0 en todas las sucursales con un ajuste de salida. Tiene stock en: :sucursales.', ['sucursales' => $detalle])]);
    }

    /**
     * Lotes § 5.6.2: para desmarcar, ningún lote con número puede tener stock (se perdería de qué lote es cada unidad).
     * El mensaje nombra los lotes que quedan. La ficha ya está bloqueada en exclusiva, así que ninguna operación de
     * este producto cambia sus saldos hasta que ControlLotes::vaciar() los bloquea y deja en cero.
     */
    private function exigirLotesVacios(Producto $producto): void
    {
        $quedan = DB::table('stock_lotes as sl')
            ->join('lotes as l', 'l.id', '=', 'sl.lote_id')
            ->join('sucursales as s', 's.id', '=', 'sl.sucursal_id')
            ->where('sl.empresa_id', $producto->empresa_id)
            ->where('sl.producto_id', $producto->id)
            ->where('l.es_regularizacion', false)
            ->where('sl.cantidad', '>', 0)
            ->orderBy('l.vencimiento')->orderBy('s.nombre')
            ->limit(6)
            ->get(['l.numero', 'l.vencimiento', 's.nombre as sucursal', 'sl.cantidad']);

        if ($quedan->isEmpty()) {
            return;
        }

        $detalle = $quedan->map(fn (object $fila): string => __('lote :lote (vence :fecha) en :sucursal: :cantidad', [
            'lote' => $fila->numero, 'fecha' => DatosLote::formatear((string) $fila->vencimiento),
            'sucursal' => $fila->sucursal, 'cantidad' => Decimal::formatear((string) $fila->cantidad),
        ]))->implode('; ');

        throw ValidationException::withMessages(['controla_vencimiento' => __('Para dejar de controlar lote y vencimiento, primero da de baja o regulariza el stock de sus lotes: :lotes.', ['lotes' => $detalle])]);
    }

    /**
     * Un producto con lote y vencimiento debe controlar stock (ESPECIFICACION_PRODUCTOS_SIN_STOCK.md § 4.6): sin saldo
     * no hay lotes que llevar. La base lo impide además con el CHECK productos_vencimiento_requiere_stock_check.
     */
    private function exigirStockConVencimiento(bool $controlaStock, bool $controlaVencimiento): void
    {
        if (! $controlaStock && $controlaVencimiento) {
            throw ValidationException::withMessages(['controla_stock' => __('Un producto con lote y vencimiento debe controlar stock. Para venderlo sin control de stock, primero desmarca «Controla lote y vencimiento».')]);
        }
    }

    /**
     * Variante de un modelo con la capacidad activa: el modelo gobierna sus datos comunes y su precio.
     */
    private function gobernadaPorModelo(Producto $producto): bool
    {
        return $producto->exists && $producto->modelo_id !== null && $this->variantes->activa($producto->empresa_id);
    }

    /**
     * Valores actuales de los campos que gobierna el modelo; reemplazan a los que traiga la petición.
     *
     * @return array<string, mixed>
     */
    private function datosDelModelo(Producto $producto): array
    {
        return [
            'nombre' => $producto->nombre_base,
            'contenido' => $producto->contenido,
            'tipo_venta' => $producto->tipo_venta,
            'categoria_id' => $producto->categoria_id,
            'unidad_medida_id' => $producto->unidad_medida_id,
            'precio_consumidor' => $producto->precio_consumidor,
            'precio_minorista' => $producto->precio_minorista,
            'precio_mayorista' => $producto->precio_mayorista,
            'activo' => $producto->activo,
        ];
    }

    /**
     * Variante editada como producto suelto (capacidad apagada): si su precio deja de coincidir con el del modelo,
     * pasa a precio propio para que ningún cambio posterior del modelo lo cambie en silencio.
     */
    private function marcarPrecioPropio(Producto $producto): void
    {
        if ($producto->modelo_id === null || $producto->precio_propio
            || ! $producto->isDirty(['precio_consumidor', 'precio_minorista', 'precio_mayorista'])) {
            return;
        }

        $modelo = DB::table('modelos')->where('empresa_id', $producto->empresa_id)->where('id', $producto->modelo_id)
            ->first(['precio_consumidor', 'precio_minorista', 'precio_mayorista']);

        if ($modelo === null) {
            return;
        }

        foreach (['precio_consumidor', 'precio_minorista', 'precio_mayorista'] as $campo) {
            $propio = $producto->getAttribute($campo);
            $delModelo = $modelo->{$campo};

            if (($propio === null) !== ($delModelo === null)
                || ($propio !== null && Decimal::comparar((string) $propio, (string) $delModelo, Decimal::ESCALA_DINERO) !== 0)) {
                $producto->precio_propio = true;

                return;
            }
        }
    }

    private function precio(mixed $valor): ?string
    {
        $precio = Decimal::normalizar(is_scalar($valor) ? (string) $valor : '', Decimal::ESCALA_DINERO);

        return $precio !== null && Decimal::comparar($precio, '0', Decimal::ESCALA_DINERO) >= 0 ? $precio : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resumen(Producto $producto): array
    {
        return $producto->only(self::CAMPOS) + ['imagen' => $producto->imagen_path !== null]
            + ($producto->modelo_id !== null ? ['precio_propio' => $producto->precio_propio] : []);
    }
}
