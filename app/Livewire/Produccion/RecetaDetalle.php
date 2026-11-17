<?php

namespace App\Livewire\Produccion;

use App\Domain\Produccion\Actions\GuardarReceta;
use App\Domain\Produccion\Consultas\ListadoRecetas;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Producto;
use App\Models\Receta;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Receta de un producto fabricado (ESPECIFICACION_PRODUCCION.md § 5): la versión vigente y el historial de versiones,
 * de solo lectura. Desde aquí se edita (versión nueva) y se desactiva o reactiva. El producto se resuelve con el scope
 * de empresa: el de otra empresa responde 404.
 */
#[Title('Receta')]
class RecetaDetalle extends Component
{
    use MuestraAvisos;

    #[Locked]
    public int $productoId;

    /** Versión que se mira; vacía = la vigente. */
    #[Url(except: '')]
    public string $version = '';

    public function mount(Producto $producto): void
    {
        $this->authorize('usar-produccion');
        abort_unless(Receta::query()->where('producto_id', $producto->id)->exists(), 404);
        $this->productoId = $producto->id;

        if (is_string($mensaje = session('aviso'))) {
            $this->avisar($mensaje);
        }
    }

    public function desactivar(GuardarReceta $accion): void
    {
        $accion->cambiarEstado($this->actor(), $this->producto(), false);
        $this->avisar(__('Receta desactivada: no se usará en órdenes nuevas.'));
    }

    public function activar(GuardarReceta $accion): void
    {
        $accion->cambiarEstado($this->actor(), $this->producto(), true);
        $this->avisar(__('Receta activada.'));
    }

    public function render(): View
    {
        $producto = $this->producto()->load('unidad:id,abreviatura');
        $versiones = Receta::query()->where('producto_id', $producto->id)
            ->with('usuario:id,name')->withCount('items')
            ->orderByDesc('version')->get();
        $vigente = $versiones->firstWhere('vigente', true);
        $mirada = ctype_digit($this->version) ? $versiones->firstWhere('version', (int) $this->version) : null;
        $mirada ??= $vigente ?? $versiones->first();
        $mirada?->load(['items.producto' => fn ($q) => $q->select(['id', 'empresa_id', 'nombre', 'sku', 'unidad_medida_id', 'activo'])->with('unidad:id,abreviatura')]);

        return view('livewire.produccion.receta-detalle', [
            'producto' => $producto,
            'versiones' => $versiones,
            'vigente' => $vigente,
            'mirada' => $mirada,
            'avisos' => ListadoRecetas::avisos($producto),
        ])->title(__('Receta de :producto', ['producto' => $producto->nombre]));
    }

    private function producto(): Producto
    {
        return Producto::query()->findOrFail($this->productoId);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
