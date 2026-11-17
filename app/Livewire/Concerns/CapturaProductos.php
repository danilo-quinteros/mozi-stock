<?php

namespace App\Livewire\Concerns;

use App\Domain\CatalogoExterno\Actions\AgregarProductoExterno;
use App\Domain\CatalogoExterno\Consultas\SugerenciasCatalogoExterno;
use App\Domain\Inventario\Consultas\StockLotes;
use App\Enums\Capacidad;
use App\Models\Categoria;
use App\Models\Modelo;
use App\Models\Producto;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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
     * @var list<array{id: int, cantidad: string, tipo_precio?: string, descuento_tipo?: string, descuento_valor?: string, costo?: string, uid?: string, lote?: string, vencimiento?: string, lote_id?: string, precio?: string}>
     */
    public array $lineas = [];

    /**
     * @var list<int|string>
     */
    public array $seleccionadas = [];

    public ?string $mensajeCaptura = null;

    /** Panel «Agregar productos» abierto (solo en las pantallas que lo ofrecen, ver ofreceListadoMasivo). */
    public bool $listadoAbierto = false;

    /** Pestaña del panel: 'todos', 'stock_bajo' o el id de una categoría (se valida en pestanaListado). */
    public string $listadoTab = 'todos';

    /** Productos que muestra el panel a la vez; escribir en el buscador encuentra los demás. */
    public const MAX_LISTADO = 18;

    /** Marcados que se agregan de una vez (los marcados se acumulan entre pestañas y búsquedas). */
    public const MAX_MARCADOS = 100;

    /**
     * Resultados del buscador por texto, para no repetir la consulta en la misma petición.
     *
     * @var array<string, array{productos: Collection<int, Producto>, modelos: Collection<int, Modelo>}>
     */
    protected array $entradasBuscador = [];

    /** Si la empresa revende un proveedor externo; se consulta una vez por petición. */
    protected ?bool $vinculadaCatalogoExterno = null;

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

        $agrupar = Gate::allows('capacidad', Capacidad::Variantes) && in_array(Producto::CLASE_VENTA, $this->clasesCaptura(), true);
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
     * Productos activos de la empresa (scope), de las clases que admite la pantalla, que coinciden por nombre, marca,
     * SKU, código de barras o contenido.
     *
     * @return Builder<Producto>
     */
    private function coincidencias(string $termino): Builder
    {
        return Producto::filtrarPorTexto(Producto::query()
            ->where('productos.activo', true)
            ->whereIn('productos.clase', $this->clasesCaptura())
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
            ->with('unidad:id,abreviatura')
            ->orderBy('productos.nombre')
            ->limit(3)
            ->get(['productos.id', 'productos.nombre', 'productos.contenido', 'productos.unidad_medida_id'])
            ->map(fn (Producto $p): string => $p->nombre);
    }

    /**
     * Pantallas que ofrecen agregar desde el catálogo de un proveedor externo (Cyclone) lo que todavía no se vende:
     * compras y ajustes de ingreso, solo para el administrador. Las demás no lo ofrecen.
     */
    protected function ofreceCatalogoExterno(): bool
    {
        return false;
    }

    private function catalogoExternoDisponible(): bool
    {
        if (! $this->ofreceCatalogoExterno() || ! Gate::allows('create', Producto::class)) {
            return false;
        }

        return $this->vinculadaCatalogoExterno ??= SugerenciasCatalogoExterno::empresaVinculada((int) auth()->user()?->empresa_id);
    }

    /**
     * Productos del catálogo del proveedor que coinciden y la empresa aún no vende (debajo de los propios).
     *
     * @return Collection<int, object{id: int, nombre: string, codigo: string|null, linea: string|null, proveedor: string, inactivo: bool}>
     */
    #[Computed]
    public function sugerenciasExternas(): Collection
    {
        return $this->catalogoExternoDisponible()
            ? SugerenciasCatalogoExterno::buscar((int) auth()->user()?->empresa_id, $this->busqueda)
            : collect();
    }

    /**
     * «Agregar a mi catálogo»: crea el producto (precio 0, sin stock) o reactiva el que se dejó de vender, y lo suma
     * a la tabla. AgregarProductoExterno vuelve a comprobar permiso y vínculo en el servidor.
     */
    public function agregarDesdeCatalogoExterno(int $catalogoExternoId, AgregarProductoExterno $accion): bool
    {
        if (! $this->catalogoExternoDisponible()) {
            $this->mensajeCaptura = __('El producto no está disponible.');

            return false;
        }

        try {
            $producto = $accion->ejecutar(auth()->user(), $catalogoExternoId);
        } catch (ValidationException $e) {
            $this->mensajeCaptura = (string) collect($e->errors())->flatten()->first();

            return false;
        }

        unset($this->sugerenciasExternas);

        return $this->agregarProducto($producto->id);
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
            ->whereIn('clase', $this->clasesCaptura())
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

        // Nada se agregó: el panel (donde existe) muestra las coincidencias o por qué no hay, filtrado por lo escrito.
        $this->abrirListado();
        $sugerencias = $sugerencias->concat($entradas['modelos']);
        $sinControl = $sugerencias->isEmpty() ? $this->sinControlQueCoinciden($termino) : collect();
        $externas = $sugerencias->isEmpty() && $sinControl->isEmpty() ? $this->sugerenciasExternas() : collect();
        $this->mensajeCaptura = match (true) {
            $sinControl->isNotEmpty() => __(':producto no controla stock. Para comprarlo, ajustarlo o traspasarlo, marca «Controla stock» en Productos.', ['producto' => $sinControl->join(', ')]),
            $externas->isNotEmpty() => __('«:termino» aún no está en tu catálogo, pero sí en el de :proveedor: elige «Agregar a mi catálogo».', ['termino' => $termino, 'proveedor' => $externas->first()->proveedor]),
            $sugerencias->isEmpty() => __('No se encontró «:termino». Revisa el código o crea el producto en Productos.', ['termino' => $termino]),
            default => __('Hay varias coincidencias: elige una de la lista.'),
        };

        return false;
    }

    public function agregarProducto(int $id): bool
    {
        // El scope de empresa impide agregar productos de otra empresa.
        $producto = Producto::query()->where('activo', true)->whereIn('clase', $this->clasesCaptura())->with('unidad:id,abreviatura')->find($id, ['id', 'nombre', 'contenido', 'unidad_medida_id', 'controla_stock']);

        if ($producto === null) {
            $this->mensajeCaptura = __('El producto no está disponible.');

            return false;
        }

        if (! $producto->controla_stock && ! $this->admiteSinControlDeStock()) {
            $this->mensajeCaptura = __(':producto no controla stock.', ['producto' => $producto->nombre]);

            return false;
        }

        $this->limpiarBusqueda($this->sumarUnidad($producto->id)
            ? "{$producto->nombre}: +1"
            : __(':producto agregado.', ['producto' => $producto->nombre]));

        return true;
    }

    /**
     * Suma una unidad a la primera línea del producto o crea su línea. Devuelve si el producto ya estaba en la tabla.
     */
    private function sumarUnidad(int $productoId): bool
    {
        foreach ($this->lineas as $indice => $linea) {
            if ($linea['id'] === $productoId) {
                $actual = Decimal::normalizar($linea['cantidad'], Decimal::ESCALA_CANTIDAD) ?? '0';
                $this->lineas[$indice]['cantidad'] = Decimal::simple(Decimal::sumar($actual, '1'));

                return true;
            }
        }

        $this->lineas[] = $this->nuevaLinea($productoId);

        return false;
    }

    /**
     * Panel «Agregar productos» (selección múltiple): se abre al hacer clic en el buscador de compras, ajustes,
     * traspasos y recetas. Las ventas (POS y Pedidos) no lo ofrecen: venden de a uno, con precio y descuento.
     */
    protected function ofreceListadoMasivo(): bool
    {
        return ! $this->admiteSinControlDeStock();
    }

    public function abrirListado(): void
    {
        $this->listadoAbierto = $this->ofreceListadoMasivo();
    }

    /**
     * Escribir en el buscador abre el panel (donde se ofrece) y lo escrito lo filtra, también sin haber hecho clic.
     * Vaciar el texto no lo cierra. El escáner no lo abre: Enter vacía el texto antes de que se envíe.
     */
    public function updatedBusqueda(): void
    {
        if (trim($this->busqueda) !== '') {
            $this->abrirListado();
        }
    }

    /**
     * La lista desplegable de siempre debajo del buscador. Donde existe el panel «Agregar productos» está inhabilitada:
     * el panel la reemplaza (pedido del propietario, 17/11/2026). Para restaurarla en compras, ajustes, traspasos y
     * recetas basta con devolver true: vuelve a verse mientras el panel está cerrado. Pedidos y POS la siguen usando.
     */
    protected function muestraListaDesplegable(): bool
    {
        return ! $this->ofreceListadoMasivo();
    }

    public function cerrarListado(): void
    {
        $this->listadoAbierto = false;
        $this->listadoTab = 'todos';
    }

    /**
     * Pestaña válida del panel: 'todos', 'stock_bajo' (solo con sucursal) o el id de una categoría. Cualquier otro
     * valor (manipulado) vale como «Todos»; una categoría de otra empresa no devuelve nada por el scope.
     */
    private function pestanaListado(?int $sucursalId): string|int
    {
        $tab = $this->listadoTab;

        return match (true) {
            $tab === 'stock_bajo' && $sucursalId !== null => 'stock_bajo',
            ctype_digit($tab) && (int) $tab > 0 => (int) $tab,
            default => 'todos',
        };
    }

    /**
     * Productos que ofrece el panel, con el stock y el mínimo de la sucursal de la pantalla (sin sucursal, como en
     * las recetas, el join no encuentra filas). Solo controlan stock: el panel no existe en las pantallas de venta.
     *
     * @return Builder<Producto>
     */
    private function baseListado(?int $sucursalId): Builder
    {
        return Producto::query()
            ->where('productos.activo', true)
            ->whereIn('productos.clase', $this->clasesCaptura())
            ->where('productos.controla_stock', true)
            ->leftJoin('unidades_medida as um', 'um.id', '=', 'productos.unidad_medida_id')
            // Índice único (empresa_id, sucursal_id, producto_id).
            ->leftJoin('stock_niveles as sn', fn (JoinClause $j) => $j->on('sn.producto_id', '=', 'productos.id')
                ->on('sn.empresa_id', '=', 'productos.empresa_id')
                ->where('sn.sucursal_id', '=', $sucursalId ?? 0));
    }

    /** Sin stock, o en o bajo su mínimo (el de la sucursal si lo tiene, si no el del producto). */
    private const STOCK_BAJO = '(COALESCE(sn.cantidad, 0) <= 0 OR (COALESCE(sn.stock_minimo, productos.stock_minimo) > 0 AND COALESCE(sn.cantidad, 0) <= COALESCE(sn.stock_minimo, productos.stock_minimo)))';

    /**
     * Hasta 18 productos del panel: los de la pestaña elegida que coinciden con lo escrito en el buscador, por nombre.
     * Una consulta (más la de la sucursal); `mas` dice si quedaron otros fuera para pedir que se escriba más.
     *
     * @return array{productos: Collection<int, Producto>, mas: bool, conStock: bool, tab: string|int}
     */
    #[Computed]
    public function listado(): array
    {
        if (! $this->listadoAbierto || ! $this->ofreceListadoMasivo()) {
            return ['productos' => collect(), 'mas' => false, 'conStock' => false, 'tab' => 'todos'];
        }

        $sucursalId = $this->sucursalSelector();
        $tab = $this->pestanaListado($sucursalId);
        $termino = trim($this->busqueda);
        $productos = $this->baseListado($sucursalId)
            ->when($termino !== '', fn (Builder $q) => Producto::filtrarPorTexto($q, $termino))
            ->when(is_int($tab), fn (Builder $q) => $q->where('productos.categoria_id', $tab))
            ->when($tab === 'stock_bajo', fn (Builder $q) => $q->whereRaw(self::STOCK_BAJO))
            ->select(['productos.id', 'productos.nombre', 'productos.marca', 'productos.contenido', 'productos.sku', 'productos.unidad_medida_id'])
            ->selectRaw('COALESCE(sn.cantidad, 0) as stock')
            ->selectRaw('COALESCE(sn.stock_minimo, productos.stock_minimo) as minimo')
            ->with('unidad:id,abreviatura')
            ->orderBy('productos.nombre')
            ->limit(self::MAX_LISTADO + 1)
            ->get();

        return [
            'productos' => $productos->take(self::MAX_LISTADO),
            'mas' => $productos->count() > self::MAX_LISTADO,
            'conStock' => $sucursalId !== null,
            'tab' => $tab,
        ];
    }

    /**
     * Pestañas del panel con su cantidad de productos (sin el filtro de texto): Todos, Stock bajo (si la pantalla
     * tiene sucursal) y cada categoría que tiene productos. Tres consultas agrupadas como máximo.
     *
     * @return array{total: int, stockBajo: int|null, categorias: list<array{id: int, nombre: string, total: int}>}
     */
    #[Computed]
    public function pestanasListado(): array
    {
        if (! $this->listadoAbierto || ! $this->ofreceListadoMasivo()) {
            return ['total' => 0, 'stockBajo' => null, 'categorias' => []];
        }

        $sucursalId = $this->sucursalSelector();
        $porCategoria = $this->baseListado($sucursalId)->toBase()
            ->selectRaw('productos.categoria_id as categoria, COUNT(*) as total')
            ->groupBy('productos.categoria_id')
            ->pluck('total', 'categoria');
        $ids = $porCategoria->keys()->filter()->map(fn ($id): int => (int) $id)->values()->all();
        $categorias = $ids === [] ? [] : Categoria::query()->whereIn('id', $ids)->orderBy('nombre')->get(['id', 'nombre'])
            ->map(fn (Categoria $c): array => ['id' => $c->id, 'nombre' => $c->nombre, 'total' => (int) $porCategoria[$c->id]])
            ->all();

        return [
            'total' => (int) $porCategoria->sum(),
            'stockBajo' => $sucursalId === null ? null : $this->baseListado($sucursalId)->whereRaw(self::STOCK_BAJO)->count(),
            'categorias' => array_values($categorias),
        ];
    }

    /**
     * «Agregar marcados a la tabla»: una consulta para todos los marcados (de la empresa por el scope, activos, de
     * las clases de la pantalla y con control de stock); cada uno suma una unidad, como un escaneo. Los que ya no
     * están disponibles se omiten y se avisa. Cierra el panel. Devuelve cuántos se agregaron.
     *
     * @param  array<int|string, mixed>  $ids
     */
    public function agregarMarcados(array $ids): int
    {
        if (! $this->ofreceListadoMasivo()) {
            return 0;
        }

        $ids = array_values(array_unique(array_filter(
            array_map(fn ($id): int => is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : 0, $ids),
            fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            $this->mensajeCaptura = __('Marca al menos un producto.');

            return 0;
        }

        if (count($ids) > self::MAX_MARCADOS) {
            $this->mensajeCaptura = __('Marca hasta :max productos por vez.', ['max' => self::MAX_MARCADOS]);

            return 0;
        }

        $disponibles = Producto::query()->where('activo', true)->whereIn('clase', $this->clasesCaptura())
            ->where('controla_stock', true)->whereIn('id', $ids)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $agregados = 0;

        foreach ($ids as $id) {
            if (in_array($id, $disponibles, true)) {
                $this->sumarUnidad($id);
                $agregados++;
            }
        }

        $omitidos = count($ids) - $agregados;
        $this->cerrarListado();
        $this->limpiarBusqueda(trim(trans_choice(':count producto agregado a la tabla.|:count productos agregados a la tabla.', $agregados)
            .($omitidos > 0 ? ' '.trans_choice(':count ya no está disponible.|:count ya no están disponibles.', $omitidos) : '')));

        return $agregados;
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
     * Clases de producto que ofrece el buscador (ESPECIFICACION_PRODUCCION.md § 3): las pantallas de venta (las que
     * admiten productos sin control de stock) nunca ofrecen materias primas; compras, ajustes y traspasos ofrecen las
     * dos, y «Ingreso de materia prima» (compra filtrada) solo materias primas. Solo filtra el buscador: las acciones
     * de venta rechazan la materia prima en el servidor.
     *
     * @return list<string>
     */
    protected function clasesCaptura(): array
    {
        return $this->admiteSinControlDeStock()
            ? [Producto::CLASE_VENTA]
            : [Producto::CLASE_VENTA, Producto::CLASE_MATERIA_PRIMA];
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
        $this->cerrarListado();
    }

    private function limpiarBusqueda(string $mensaje): void
    {
        $this->busqueda = '';
        $this->mensajeCaptura = $mensaje;
    }
}
