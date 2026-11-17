<?php

namespace App\Livewire\Produccion;

use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Envasado;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Detalle de un envasado (ESPECIFICACION_PRODUCCION.md § 7): granel y lote, presentaciones con su costo, y lo necesario,
 * lo usado y la merma de granel y envases. No se edita ni se anula. El envasado se resuelve con el scope de empresa: el
 * de otra empresa da 404.
 */
#[Title('Envasado')]
class EnvasadoDetalle extends Component
{
    use MuestraAvisos;

    #[Locked]
    public int $envasadoId;

    public function mount(Envasado $envasado): void
    {
        $this->authorize('usar-produccion');
        $this->envasadoId = $envasado->id;

        if (is_string($mensaje = session('aviso'))) {
            $this->avisar($mensaje);
        }
    }

    public function render(): View
    {
        $producto = fn ($q) => $q->select(['id', 'empresa_id', 'nombre', 'contenido', 'sku', 'unidad_medida_id'])->with('unidad:id,abreviatura');
        $envasado = Envasado::query()->with([
            'granel' => $producto, 'sucursal:id,nombre', 'lote:id,numero,vencimiento,es_regularizacion', 'usuario:id,name',
            'items.producto' => $producto, 'consumos.producto' => $producto,
        ])->findOrFail($this->envasadoId);

        return view('livewire.produccion.envasado-detalle', ['envasado' => $envasado])
            ->title(__('Envasado :codigo', ['codigo' => $envasado->codigo()]));
    }
}
