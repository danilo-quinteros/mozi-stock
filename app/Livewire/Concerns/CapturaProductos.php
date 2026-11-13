<?php

namespace App\Livewire\Concerns;

use App\Domain\Inventario\Consultas\StockLotes;
use App\Enums\Capacidad;
use App\Models\Modelo;
use App\Models\Producto;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

/**
 * Captura consecutiva de productos (V3): buscar o escanear y Enter agrega; repetir el
 * código suma 1; cantidades editables y quitar por fila o selección. Solo prepara datos:
 * el stock y las cantidades se validan de nuevo en la acción de dominio.
 *
 * Compras, ajustes y traspasos no ofrecen productos sin control de stock; si llegan por escáner se
 * avisa y no se agregan (LibroStock los rechaza igual). Las ventas (POS y Pedidos) los admiten.
 *
 * Tallas y colores (V2): con la capacidad, el buscador agrupa las combinaciones en su modelo y elegirlo abre el
 * selector (EligeVariantes). El código de barras o el SKU exacto de una combinación la agrega directamente.
 */
trait CapturaProductos
{
    use EligeVariantes;

    public string $busqueda = '';

    /**
     * Líneas capturadas; los pedidos agregan lista de precio y descuento, y las compras el costo y, en productos con
     * vencimiento, lote y vencimiento. Las compras identifican cada fila con `uid` porque un producto puede repetirse
     * (una línea por lote); las demás pantallas la identifican con el id del producto.
     *
     * @var list<array{id: int, cantidad: string, tipo_precio?: string, descuento_tipo?: string, descuento_valor?: string, costo?: string, uid?: string, lote?: string, vencimiento?: string, lote_id?: string}>
     */
    public array $lineas = [];

    /**
     * @var list<int|string>
     */
    public array $seleccionadas = [];

    public ?string $mensajeCaptura = null;

    /**
     * Resultados del buscador por texto, para no repetir la consulta en la misma petición.
     *
     * @var array<string, array{productos: Collection<int, Producto>, modelos: Collection<int, Modelo>}>
     */
    protected array $entradasBuscador = [];

    /**
     * @return Collection<int, Producto>
     */
    #[Computed]
    public function sugerencias(): Collection
    {
        return $this->buscarEntradas($this->busqueda)['productos'];
    }

    /**
     * Modelos que coinciden con la búsqueda (solo con «Tallas y colores» activa); elegir uno abre el selector.
     *
     * @return Collection<int, Modelo>
     */
    #[Computed]
    public function sugerenciasModelos(): Collection
    {
        return $this->buscarEntradas($this->busqueda)['modelos'];
    }

    /**
     * Hasta 8 sugerencias. Con la capacidad, las combinaciones encontradas por nombre se agrupan en su modelo (una
     * entrada por modelo, primero); las que coinciden exactamente por SKU o código de barras se muestran solas.
     *
     * @return array{productos: Collection<int, Producto>, modelos: Collection<int, Modelo>}
     */
    private function buscarEntradas(string $texto): array
    {
        $termino = trim($texto);

        if (mb_strlen($termino) < 2) {
            return ['productos' => collect(), 'modelos' => collect()];
        }

        if (isset($this->entradasBuscador[$termino])) {
            return $this->entradasBuscador[$termino];
        }

        $agrupar = Gate::allows('capacidad', Capacidad::Variantes);
        $productos = $this->coincidencias($termino)
            ->when(! $this->admiteSinControlDeStock(), fn (Builder $q) => $q->where('productos.controla_stock', true))
            ->with('unidad:id,abreviatura')
            ->orderBy('productos.nombre')
            ->limit($agrupar ? 40 : 8)
            ->get(['productos.id', 'productos.nombre', 'productos.marca', 'productos.contenido', 'productos.sku', 'productos.codigo_barras', 'productos.unidad_medida_id', 'productos.modelo_id']);

        if (! $agrupar) {
            return $this->entradasBuscador[$termino] = ['productos' => $productos, 'modelos' => collect()];
        }

        $sueltos = $productos->filter(fn (Producto $p): bool => $p->modelo_id === null
            || $p->codigo_barras === $termino || ($p->sku !== null && mb_strtolower($p->sku) === mb_strtolower($termino)));
        $modeloIds = $productos->diffKeys($sueltos)->pluck('modelo_id')->unique()->values()->all();
        $modelos = $modeloIds === [] ? collect() : Modelo::query()->whereIn('id', $modeloIds)->where('activo', true)
            ->with('unidad:id,abreviatura')
            ->withCount(['variantes as combinaciones' => fn (Builder $q) => $q->where('activo', true)])
            ->orderBy('nombre')->limit(8)->get(['id', 'nombre', 'sku_base', 'unidad_medida_id']);

        return $this->entradasBuscador[$termino] = [
            'productos' => $sueltos->values()->take(max(0, 8 - $modelos->count())),
            'modelos' => $modelos,
        ];
    }

    /**
     * Productos activos de la empresa (scope) que coinciden por nombre, marca, SKU, código de barras o contenido.
     *
     * @return Builder<Producto>
     */
    private function coincidencias(string $termino): Builder
    {
        return Producto::filtrarPorTexto(Producto::query()
            ->where('productos.activo', true)
            ->leftJoin('unidades_medida as um', 'um.id', '=', 'productos.unidad_medida_id'), $termino);
    }

    /**
     * Sin sugerencias en compras, ajustes o traspasos: nombres (hasta 3) de los productos que sí coinciden pero no
     * controlan stock, para decir por qué no aparecen en vez de «Producto no encontrado». Una consulta, solo cuando
     * la búsqueda quedó vacía; las pantallas de venta los admiten y no la necesitan.
     *
     * @return Collection<int, string>
     */
    #[Computed]
    public function coincidenciasSinControl(): Collection
    {
        return $this->sinControlQueCoinciden($this->busqueda);
    }

    /**
     * @return Collection<int, string>
     */
    private function sinControlQueCoinciden(string $texto): Collection
    {
        $termino = trim($texto);
        $entradas = $this->buscarEntradas($termino);

        if ($this->admiteSinControlDeStock() || mb_strlen($termino) < 2
            || $entradas['productos']->isNotEmpty() || $entradas['modelos']->isNotEmpty()) {
            return collect();
        }

        return $this->coincidencias($termino)
            ->where('productos.controla_stock', false)
            ->orderBy('productos.nombre')
            ->limit(3)
            ->pluck('productos.nombre');
    }

    /**
     * Enter en el buscador: coincidencia exacta de código de barras o SKU (lector), o
     * única sugerencia por nombre. Devuelve si se agregó un producto.
     */
    public function agregarDesdeBuscador(?string $texto = null): bool
    {
        $termino = trim($texto ?? $this->busqueda);

        if ($termino === '') {
            return false;
        }

        $exacto = Producto::query()
            ->where('activo', true)
            ->where(fn (Builder $q) => $q->where('codigo_barras', $termino)->orWhere('sku', $termino))
            ->value('id');

        if ($exacto !== null) {
            return $this->agregarProducto((int) $exacto);
        }

        $this->busqueda = $termino;
        $entradas = $this->buscarEntradas($termino);
        $sugerencias = $entradas['productos'];

        // Un solo modelo y ningún producto suelto: Enter abre su selector de talla y color.
        if ($sugerencias->isEmpty() && $entradas['modelos']->count() === 1) {
            return $this->abrirSelector($entradas['modelos']->first()->id);
        }

        if ($sugerencias->count() === 1 && $entradas['modelos']->isEmpty()) {
            return $this->agregarProducto($sugerencias->first()->id);
        }

        $sugerencias = $sugerencias->concat($entradas['modelos']);
        $sinControl = $sugerencias->isEmpty() ? $this->sinControlQueCoinciden($termino) : collect();
        $this->mensajeCaptura = match (true) {
            $sinControl->isNotEmpty() => __(':producto no controla stock. Para comprarlo, ajustarlo o traspasarlo, marca «Controla stock» en Productos.', ['producto' => $sinControl->join(', ')]),
            $sugerencias->isEmpty() => __('No se encontró «:termino». Revisa el código o crea el producto en Productos.', ['termino' => $termino]),
            default => __('Hay varias coincidencias: elige una de la lista.'),
        };

        return false;
    }

    public function agregarProducto(int $id): bool
    {
        // El scope de empresa impide agregar productos de otra empresa.
        $producto = Producto::query()->where('activo', true)->with('unidad:id,abreviatura')->find($id, ['id', 'nombre', 'contenido', 'unidad_medida_id', 'controla_stock']);

        if ($producto === null) {
            $this->mensajeCaptura = __('El producto no está disponible.');

            return false;
        }

        if (! $producto->controla_stock && ! $this->admiteSinControlDeStock()) {
            $this->mensajeCaptura = __(':producto no controla stock.', ['producto' => $producto->nombre]);

            return false;
        }

        foreach ($this->lineas as $indice => $linea) {
            if ($linea['id'] === $producto->id) {
                $actual = Decimal::normalizar($linea['cantidad'], Decimal::ESCALA_CANTIDAD) ?? '0';
                $this->lineas[$indice]['cantidad'] = Decimal::simple(Decimal::sumar($actual, '1'));
                $this->limpiarBusqueda("{$producto->nombre}: +1");

                return true;
            }
        }

        $this->lineas[] = $this->nuevaLinea($producto->id);
        $this->limpiarBusqueda(__(':producto agregado.', ['producto' => $producto->nombre]));

        return true;
    }

    /**
     * Suma una cantidad a la línea del producto o crea una (cuadrícula de tallas y colores). En compras, $costo llena
     * el costo de la línea nueva o el de una existente que aún no lo tenía; después se edita por línea.
     */
    protected function agregarCantidad(int $productoId, string $cantidad, ?string $costo = null): void
    {
        foreach ($this->lineas as $indice => $linea) {
            if ($linea['id'] === $productoId) {
                $actual = Decimal::normalizar($linea['cantidad'], Decimal::ESCALA_CANTIDAD) ?? '0';
                $this->lineas[$indice]['cantidad'] = Decimal::simple(Decimal::sumar($actual, $cantidad));

                if ($costo !== null && array_key_exists('costo', $linea) && $linea['costo'] === '') {
                    $this->lineas[$indice]['costo'] = $costo;
                }

                return;
            }
        }

        $linea = $this->nuevaLinea($productoId);
        $linea['cantidad'] = $cantidad;

        if ($costo !== null && array_key_exists('costo', $linea)) {
            $linea['costo'] = $costo;
        }

        $this->lineas[] = $linea;
    }

    /**
     * Las pantallas de venta lo redefinen: un plato o un servicio se vende sin mover stock
     * (ESPECIFICACION_PRODUCTOS_SIN_STOCK.md § 3). Compras, ajustes y traspasos no los ofrecen.
     */
    protected function admiteSinControlDeStock(): bool
    {
        return false;
    }

    /**
     * Línea que se crea al agregar un producto. Cada pantalla puede añadir sus columnas.
     *
     * @return array{id: int, cantidad: string, tipo_precio?: string, descuento_tipo?: string, descuento_valor?: string, costo?: string, uid?: string, lote?: string, vencimiento?: string}
     */
    protected function nuevaLinea(int $productoId): array
    {
        return ['id' => $productoId, 'cantidad' => '1'];
    }

    /**
     * Identifica una fila: su uid si la pantalla lo usa (compras), si no el id del producto.
     *
     * @param  array{id: int, uid?: string}  $linea
     */
    protected function claveLinea(array $linea): int|string
    {
        return $linea['uid'] ?? $linea['id'];
    }

    public function quitarLinea(int|string $clave): void
    {
        $clave = (string) $clave;
        $this->lineas = array_values(array_filter($this->lineas, fn (array $linea) => (string) $this->claveLinea($linea) !== $clave));
        $this->seleccionadas = array_values(array_filter($this->seleccionadas, fn ($sel) => (string) $sel !== $clave));
    }

    public function quitarSeleccionadas(): void
    {
        $claves = array_map('strval', $this->seleccionadas);
        $this->lineas = array_values(array_filter($this->lineas, fn (array $linea) => ! in_array((string) $this->claveLinea($linea), $claves, true)));
        $this->seleccionadas = [];
    }

    public function seleccionarTodas(): void
    {
        $this->seleccionadas = count($this->seleccionadas) === count($this->lineas) ? [] : array_map(fn (array $linea) => $this->claveLinea($linea), $this->lineas);
    }

    /**
     * @return array<int, string> producto_id => cantidad tal como la escribió el usuario
     */
    protected function lineasParaAccion(): array
    {
        $resultado = [];

        foreach ($this->lineas as $linea) {
            $resultado[$linea['id']] = $linea['cantidad'];
        }

        return $resultado;
    }

    /**
     * Datos de los productos de la tabla y su stock en una sucursal: dos consultas en total.
     *
     * Con $vendible, el stock de los productos con lote excluye las unidades vencidas (ESPECIFICACION_LOTES_Y_
     * VENCIMIENTOS.md § 4): es lo que muestran ventas, POS y traspasos. Ajustes muestra el total, que incluye lo
     * vencido porque físicamente está. Sin la capacidad o sin productos marcados no hay consulta extra.
     *
     * @return array{productos: Collection<int, Producto>, stock: array<int, string>, vencidas: array<int, string>}
     */
    protected function datosDeLineas(?int $sucursalId, bool $vendible = false): array
    {
        $ids = array_column($this->lineas, 'id');
        $productos = Producto::query()->whereIn('id', $ids)
            ->with(['unidad:id,abreviatura,admite_decimales', 'categoria:id,nombre'])
            // Tallas y colores § 4.5: la miniatura de una variante sin foto usa la del modelo.
            ->when(Gate::allows('capacidad', Capacidad::Variantes), fn (Builder $q) => $q->with('modelo:id,imagen_path'))
            ->get()->keyBy('id');
        $stock = $sucursalId === null || $ids === [] ? [] : DB::table('stock_niveles')
            ->where('empresa_id', auth()->user()?->empresa_id)
            ->where('sucursal_id', $sucursalId)
            ->whereIn('producto_id', $ids)
            ->pluck('cantidad', 'producto_id')
            ->map(fn ($cantidad) => (string) $cantidad)
            ->all();
        $vencidas = $sucursalId === null || $ids === [] || ! $productos->contains(fn (Producto $p): bool => (bool) $p->controla_vencimiento)
            ? [] : app(StockLotes::class)->vencidas((int) auth()->user()?->empresa_id, $sucursalId, array_map('intval', $ids));

        foreach ($vendible ? $vencidas : [] as $productoId => $cantidad) {
            $stock[$productoId] = Decimal::restar($stock[$productoId] ?? '0', $cantidad);
        }

        return ['productos' => $productos, 'stock' => $stock, 'vencidas' => $vencidas];
    }

    protected function reiniciarCaptura(): void
    {
        $this->lineas = [];
        $this->seleccionadas = [];
        $this->busqueda = '';
        $this->mensajeCaptura = null;
        $this->cerrarSelector();
    }

    private function limpiarBusqueda(string $mensaje): void
    {
        $this->busqueda = '';
        $this->mensajeCaptura = $mensaje;
    }
}
