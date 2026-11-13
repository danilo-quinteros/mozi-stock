<?php

namespace App\Livewire\Inventario;

use App\Domain\Inventario\Consultas\TrazabilidadLote as Consulta;
use App\Domain\Inventario\DatosLote;
use App\Models\Lote;
use App\Models\OperacionInventario;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Trazabilidad de un lote (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 4): stock actual por sucursal y cada movimiento con
 * su documento. El lote se busca con el EmpresaScope, así que el de otra empresa responde 404 aunque se cambie el id
 * de la URL; misma Policy que el historial de inventario (§ 8).
 */
#[Title('Trazabilidad de lote')]
class TrazabilidadLote extends Component
{
    use WithPagination;

    #[Locked]
    public int $loteId;

    public function mount(Lote $lote): void
    {
        $this->authorize('viewAny', OperacionInventario::class);
        $this->loteId = $lote->id;
    }

    public function render(): View
    {
        $empresaId = (int) $this->actor()->empresa_id;
        $lote = Lote::query()->with('producto.unidad:id,abreviatura')->findOrFail($this->loteId);
        $vencimiento = $lote->vencimiento?->format('Y-m-d');

        return view('livewire.inventario.trazabilidad-lote', [
            'lote' => $lote,
            'datos' => [
                'id' => $lote->id, 'numero' => $lote->numero, 'vencimiento' => $vencimiento,
                'regularizacion' => $lote->es_regularizacion, 'cantidad' => '0', 'vencido' => DatosLote::vencido($vencimiento),
            ],
            'producto' => Producto::formatoNombreCompleto($lote->producto->nombre, $lote->producto->contenido, $lote->producto->unidad->abreviatura),
            'stock' => Consulta::stock($empresaId, $lote->id),
            'movimientos' => Consulta::movimientos($empresaId, $lote->id)->paginate(30),
        ]);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
