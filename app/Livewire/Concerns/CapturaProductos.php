<?php

namespace App\Livewire\Concerns;

use App\Models\Producto;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

/**
 * Captura consecutiva de productos (V3): buscar o escanear y Enter agrega; repetir el
 * código suma 1; cantidades editables y quitar por fila o selección. Solo prepara datos:
 * el stock y las cantidades se validan de nuevo en la acción de dominio.
 */
trait CapturaProductos
{
    public string $busqueda = '';

    /**
     * Líneas capturadas; los pedidos agregan lista de precio y descuento, y las compras el costo.
     *
     * @var list<array{id: int, cantidad: string, tipo_precio?: string, descuento_tipo?: string, descuento_valor?: string, costo?: string}>
     */
    public array $lineas = [];

    /**
     * @var list<int|string>
     */
    public array $seleccionadas = [];

    public ?string $mensajeCaptura = null;

    /**
     * @return Collection<int, Producto>
     */
    #[Computed]
    public function sugerencias(): Collection
    {
        return $this->buscarSugerencias($this->busqueda);
    }

    /**
     * @return Collection<int, Producto>
     */
    private function buscarSugerencias(string $texto): Collection
    {
        $termino = trim($texto);

        if (mb_strlen($termino) < 2) {
            return collect();
        }

        $patron = '%'.addcslashes($termino, '\\%_').'%';

        return Producto::query()
            ->where('productos.activo', true)
            ->leftJoin('unidades_medida as um', 'um.id', '=', 'productos.unidad_medida_id')
            ->where(fn (Builder $q) => $q->where('productos.nombre', 'like', $patron)
                ->orWhere('productos.sku', 'like', $patron)
                ->orWhere('productos.codigo_barras', $termino)
                ->orWhere('productos.contenido', 'like', $patron)
                ->orWhereRaw("CONCAT_WS(' ', productos.nombre, productos.contenido, um.abreviatura) LIKE ?", [$patron]))
            ->with('unidad:id,abreviatura')
            ->orderBy('productos.nombre')
            ->limit(8)
            ->get(['productos.id', 'productos.nombre', 'productos.contenido', 'productos.sku', 'productos.unidad_medida_id']);
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
        $sugerencias = $this->buscarSugerencias($termino);

        if ($sugerencias->count() === 1) {
            return $this->agregarProducto($sugerencias->first()->id);
        }

        $this->mensajeCaptura = $sugerencias->isEmpty()
            ? __('No se encontró «:termino». Revisa el código o crea el producto en Productos.', ['termino' => $termino])
            : __('Hay varias coincidencias: elige una de la lista.');

        return false;
    }

    public function agregarProducto(int $id): bool
    {
        // El scope de empresa impide agregar productos de otra empresa.
        $producto = Producto::query()->where('activo', true)->with('unidad:id,abreviatura')->find($id, ['id', 'nombre', 'contenido', 'unidad_medida_id']);

        if ($producto === null) {
            $this->mensajeCaptura = __('El producto no está disponible.');

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
     * Línea que se crea al agregar un producto. Cada pantalla puede añadir sus columnas.
     *
     * @return array{id: int, cantidad: string, tipo_precio?: string, descuento_tipo?: string, descuento_valor?: string, costo?: string}
     */
    protected function nuevaLinea(int $productoId): array
    {
        return ['id' => $productoId, 'cantidad' => '1'];
    }

    public function quitarLinea(int $id): void
    {
        $this->lineas = array_values(array_filter($this->lineas, fn (array $linea) => $linea['id'] !== $id));
        $this->seleccionadas = array_values(array_filter($this->seleccionadas, fn ($sel) => (int) $sel !== $id));
    }

    public function quitarSeleccionadas(): void
    {
        $ids = array_map('intval', $this->seleccionadas);
        $this->lineas = array_values(array_filter($this->lineas, fn (array $linea) => ! in_array($linea['id'], $ids, true)));
        $this->seleccionadas = [];
    }

    public function seleccionarTodas(): void
    {
        $this->seleccionadas = count($this->seleccionadas) === count($this->lineas) ? [] : array_column($this->lineas, 'id');
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
     * @return array{productos: Collection<int, Producto>, stock: array<int, string>}
     */
    protected function datosDeLineas(?int $sucursalId): array
    {
        $ids = array_column($this->lineas, 'id');
        $productos = Producto::query()->whereIn('id', $ids)
            ->with(['unidad:id,abreviatura,admite_decimales', 'categoria:id,nombre'])
            ->get()->keyBy('id');
        $stock = $sucursalId === null || $ids === [] ? [] : DB::table('stock_niveles')
            ->where('empresa_id', auth()->user()?->empresa_id)
            ->where('sucursal_id', $sucursalId)
            ->whereIn('producto_id', $ids)
            ->pluck('cantidad', 'producto_id')
            ->map(fn ($cantidad) => (string) $cantidad)
            ->all();

        return ['productos' => $productos, 'stock' => $stock];
    }

    protected function reiniciarCaptura(): void
    {
        $this->lineas = [];
        $this->seleccionadas = [];
        $this->busqueda = '';
        $this->mensajeCaptura = null;
    }

    private function limpiarBusqueda(string $mensaje): void
    {
        $this->busqueda = '';
        $this->mensajeCaptura = $mensaje;
    }
}
