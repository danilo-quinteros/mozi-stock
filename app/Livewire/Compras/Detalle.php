<?php

namespace App\Livewire\Compras;

use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Compra;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Compra registrada: detalle inmutable, pago y operación de inventario que generó.
 */
class Detalle extends Component
{
    use MuestraAvisos;

    #[Locked]
    public int $compraId;

    public function mount(Compra $compra): void
    {
        $this->authorize('view', $compra);
        $this->compraId = $compra->id;

        if (is_string($mensaje = session('aviso'))) {
            $this->avisar($mensaje);
        }
    }

    public function render(): View
    {
        $compra = Compra::query()
            ->with(['items', 'sucursal:id,nombre', 'proveedor:id,nombre,documento,telefono', 'usuario:id,name', 'operacion', 'cuentaPagar'])
            ->findOrFail($this->compraId);

        return view('livewire.compras.detalle', ['compra' => $compra])->title($compra->codigo());
    }
}
