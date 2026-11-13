<?php

namespace App\Livewire\Ventas;

use App\Domain\Despachos\Actions\CancelarEntregaDevuelta;
use App\Domain\Tenancy\CapacidadesEmpresa;
use App\Enums\EstadoEntrega;
use App\Livewire\Concerns\GestionaEntregas;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Venta;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Entrega en el detalle de venta (ESPECIFICACION_DESPACHOS.md § 4): insignia del estado, quién despachó y
 * entregó, la línea de tiempo con cada corrección y las acciones que el usuario puede hacer. Los pagos
 * siguen aparte, en «Cobros recibidos».
 *
 * Con cocina (ESPECIFICACION_COCINA.md) un pedido En cocina también se entrega aquí con un clic, con el
 * aviso «Deshacer» de 10 segundos, igual que desde el panel En cocina.
 */
class Entrega extends Component
{
    use GestionaEntregas, MuestraAvisos;

    #[Locked]
    public int $ventaId;

    public function mount(int $ventaId): void
    {
        $venta = Venta::query()->findOrFail($ventaId);
        $this->authorize('view', $venta);
        $this->ventaId = $venta->id;
    }

    public function abrir(string $dialogo): void
    {
        $this->abrirEntrega($this->ventaId, $dialogo);
    }

    public function entregar(): void
    {
        $this->entregarPedido($this->ventaId);
    }

    public function render(): View
    {
        $venta = Venta::query()
            ->with(['eventosEntrega.usuario:id,name', 'despachador:id,name', 'entregador:id,name'])
            ->findOrFail($this->ventaId);
        $estado = $venta->estado_entrega;

        return view('livewire.ventas.entrega', [
            'venta' => $venta,
            'acciones' => array_keys(array_filter([
                'despachar' => $estado === EstadoEntrega::PorDespachar && Gate::allows('despachar', $venta),
                'despachar_entregar' => $estado === EstadoEntrega::PorDespachar && Gate::allows('despachar', $venta),
                'entregar' => $estado === EstadoEntrega::Despachado && Gate::allows('marcarEntregada', $venta),
                // Cocina § 4.3: un solo clic, sin diálogo.
                'entregar_pedido' => $estado === EstadoEntrega::EnCocina && Gate::allows('marcarEntregada', $venta),
                'corregir' => Gate::allows('corregirEntrega', $venta) && ($estado === EstadoEntrega::Despachado
                    || ($estado === EstadoEntrega::Entregado && $venta->entregado_por !== null)),
                'cancelar' => $estado === EstadoEntrega::Despachado && Gate::allows('corregirEntrega', $venta)
                    && CancelarEntregaDevuelta::devueltaPorCompleto($venta),
            ])),
            'dialogo' => $this->dialogoEntrega(),
            'deshacer' => $this->avisoDeshacer(),
            'flujoEntrega' => app(CapacidadesEmpresa::class)->flujoEntrega($venta->empresa_id),
        ]);
    }
}
