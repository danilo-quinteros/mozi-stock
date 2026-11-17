<?php

namespace App\Livewire\Produccion;

use App\Domain\Produccion\Actions\GuardarReceta;
use App\Livewire\Concerns\CapturaProductos;
use App\Models\Producto;
use App\Models\Receta;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Nueva receta o versión nueva de una receta (ESPECIFICACION_PRODUCCION.md § 5): producto fabricado, cantidad base,
 * nota y materias primas con su cantidad. El buscador de materias primas es la captura rápida de siempre (buscar o
 * escanear + Enter), limitada a materias primas. Toda la regla vive en GuardarReceta.
 */
#[Title('Receta')]
class RecetaFormulario extends Component
{
    use CapturaProductos;

    /** Producto fabricado: fijo al editar; al crear, el que se elige en el buscador. */
    #[Locked]
    public ?int $productoId = null;

    /** Versión vigente que se está editando; null al crear. GuardarReceta rechaza si otra persona guardó antes. */
    #[Locked]
    public ?int $versionEditada = null;

    /**
     * La captura rápida (EligeVariantes) lee la sucursal de la pantalla para mostrar stock; una receta no tiene
     * sucursal, así que queda vacía y fija.
     */
    #[Locked]
    public ?string $sucursal_id = null;

    public string $buscarProducto = '';

    public string $cantidad_base = '';

    public string $nota = '';

    public function mount(?Producto $producto = null): void
    {
        $this->authorize('usar-produccion');

        if ($producto === null || ! $producto->exists) {
            return;
        }

        $vigente = Receta::query()->where('producto_id', $producto->id)->where('vigente', true)->with('items')->first();
        abort_if($vigente === null, 404);

        $this->productoId = $producto->id;
        $this->versionEditada = $vigente->version;
        $this->cantidad_base = Decimal::simple((string) $vigente->cantidad_base);
        $this->nota = (string) ($vigente->nota ?? '');
        $this->lineas = array_values($vigente->items->map(fn ($item): array => ['id' => $item->producto_id, 'cantidad' => Decimal::simple((string) $item->cantidad)])->all());
    }

    /**
     * Productos que se pueden fabricar y todavía no tienen receta (§ 5.1): de venta, activos, con control de stock y
     * que no son combinaciones de tallas y colores. Hasta 8.
     *
     * @return Collection<int, Producto>
     */
    #[Computed]
    public function productosSugeridos(): Collection
    {
        $termino = trim($this->buscarProducto);

        if ($this->productoId !== null || mb_strlen($termino) < 2) {
            return collect();
        }

        return Producto::filtrarPorTexto($this->fabricables(), $termino)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('recetas')->whereColumn('recetas.empresa_id', 'productos.empresa_id')->whereColumn('recetas.producto_id', 'productos.id')->whereNotNull('recetas.producto_vigente'))
            ->with('unidad:id,abreviatura')
            ->orderBy('productos.nombre')
            ->limit(8)
            ->get(['productos.id', 'productos.nombre', 'productos.contenido', 'productos.sku', 'productos.marca', 'productos.unidad_medida_id']);
    }

    public function elegirProducto(int $id): void
    {
        if ($this->versionEditada !== null) {
            return;
        }

        $producto = $this->fabricables()->find($id, ['productos.id']);

        if ($producto === null) {
            $this->addError('producto_id', __('Ese producto no se puede fabricar: debe ser de venta, estar activo y controlar stock.'));

            return;
        }

        // Ya tiene receta: se edita (versión nueva) en vez de crear otra.
        if (Receta::query()->where('producto_id', $producto->id)->where('vigente', true)->exists()) {
            $this->redirectRoute('produccion.recetas.editar', ['producto' => $producto->id], navigate: false);

            return;
        }

        $this->productoId = $producto->id;
        $this->buscarProducto = '';
        $this->resetErrorBag('producto_id');
    }

    public function cambiarProducto(): void
    {
        if ($this->versionEditada === null) {
            $this->productoId = null;
        }
    }

    public function guardar(GuardarReceta $accion): void
    {
        $this->resetErrorBag();

        if ($this->productoId === null) {
            $this->addError('producto_id', __('Elige el producto que se fabrica con esta receta.'));

            return;
        }

        $receta = $accion->ejecutar($this->actor(), [
            'producto_id' => $this->productoId,
            'cantidad_base' => $this->cantidad_base,
            'nota' => $this->nota,
            'lineas' => array_map(fn (array $linea): array => ['producto_id' => $linea['id'], 'cantidad' => $linea['cantidad']], $this->lineas),
            'version' => $this->versionEditada,
        ]);

        $nombre = Producto::query()->whereKey($this->productoId)->value('nombre');
        session()->flash('aviso', $receta->version === $this->versionEditada
            ? __('Sin cambios: la receta de :producto sigue en la versión :version.', ['producto' => $nombre, 'version' => $receta->version])
            : __('Receta de :producto guardada: versión :version.', ['producto' => $nombre, 'version' => $receta->version]));
        $this->redirectRoute('produccion.recetas.ver', ['producto' => $this->productoId], navigate: false);
    }

    public function render(): View
    {
        $datos = $this->datosDeLineas(null);
        $filas = [];

        foreach ($this->lineas as $indice => $linea) {
            $materia = $datos['productos']->get($linea['id']);

            if ($materia !== null) {
                $filas[] = ['indice' => $indice, 'materia' => $materia];
            }
        }

        return view('livewire.produccion.receta-formulario', [
            'producto' => $this->productoId === null ? null : Producto::query()->with('unidad:id,abreviatura')->find($this->productoId),
            'filas' => $filas,
            'sugerencias' => $this->sugerencias(),
        ])->title($this->versionEditada === null ? __('Nueva receta') : __('Editar receta'));
    }

    /**
     * El buscador de la receta solo ofrece materias primas (§ 5.3).
     *
     * @return list<string>
     */
    protected function clasesCaptura(): array
    {
        return [Producto::CLASE_MATERIA_PRIMA];
    }

    /**
     * @return Builder<Producto>
     */
    private function fabricables(): Builder
    {
        return Producto::query()
            ->leftJoin('unidades_medida as um', 'um.id', '=', 'productos.unidad_medida_id')
            ->where('productos.clase', Producto::CLASE_VENTA)
            ->where('productos.activo', true)
            ->where('productos.controla_stock', true)
            ->whereNull('productos.modelo_id');
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
