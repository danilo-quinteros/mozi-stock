<?php

namespace App\Livewire\Facturacion;

use App\Domain\Facturacion\DeEmpresa;
use App\Domain\Facturacion\PreparacionFacturacion;
use App\Enums\EstadoFactura;
use App\Livewire\Facturacion\Concerns\EmpresaDeFacturacion;
use App\Models\SiatFactura;
use App\Models\Venta;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Facturas SIAT emitidas por la empresa y lista de preparación para empezar a facturar. Solo lectura:
 * emitir, reintentar, imprimir y anular se hacen desde el detalle de cada venta.
 */
class Facturas extends Component
{
    use EmpresaDeFacturacion, WithPagination;

    public string $estado = '';

    public function mount(int $empresaId): void
    {
        $this->empresaId = $empresaId;
        $this->empresa();
    }

    public function updatedEstado(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $empresa = $this->empresa();
        $estado = EstadoFactura::tryFrom($this->estado);

        $facturas = DeEmpresa::consulta(SiatFactura::class, $empresa->id)
            ->when($estado !== null, fn ($q) => $q->where('estado', $estado))
            ->orderByDesc('id')
            ->paginate(25, ['id', 'venta_id', 'numero', 'estado', 'motivo', 'razon_social', 'numero_documento', 'total', 'fecha_emision', 'created_at']);
        // Número de venta en una sola consulta (sin N+1); el panel global no tiene contexto tenant.
        $ventas = DeEmpresa::consulta(Venta::class, $empresa->id)
            ->whereIn('id', $facturas->getCollection()->pluck('venta_id'))->pluck('numero', 'id');

        return view('livewire.facturacion.facturas', [
            'facturas' => $facturas,
            'ventas' => $ventas,
            'estados' => EstadoFactura::cases(),
            'preparacion' => PreparacionFacturacion::revisar($empresa),
            // Los enlaces a la venta y a la impresión son de la empresa del usuario (el superadmin no opera ventas ajenas).
            'propia' => (int) $this->actor()->empresa_id === $empresa->id,
        ]);
    }
}
