<?php

namespace App\Livewire\Ventas;

use App\Domain\Creditos\Consultas\HistorialPagos;
use App\Enums\EstadoFactura;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\DevolucionItem;
use App\Models\SiatFactura;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Venta confirmada: detalle inmutable, cobros recibidos, devoluciones y saldo. Los abonos se
 * registran en Créditos y las devoluciones en su propia pantalla contextual.
 */
class Detalle extends Component
{
    use MuestraAvisos;

    #[Locked]
    public int $ventaId;

    public function mount(Venta $venta): void
    {
        $this->authorize('view', $venta);
        $this->ventaId = $venta->id;

        if (is_string($mensaje = session('aviso'))) {
            $this->avisar($mensaje);
        }
    }

    public function render(): View
    {
        $venta = Venta::query()
            ->with(['pedido:id,numero,nota', 'sucursal:id,nombre', 'cliente:id,nombre,documento,telefono', 'vendedor:id,name',
                'items', 'devoluciones.usuario:id,name', 'devoluciones.items.ventaItem:id,descripcion,unidad'])
            ->findOrFail($this->ventaId);
        $devueltas = $venta->devoluciones->flatMap->items
            ->reduce(fn (string $suma, DevolucionItem $item) => Decimal::sumar($suma, $item->cantidad), '0');
        $vendidas = $venta->items->reduce(fn (string $suma, VentaItem $item) => Decimal::sumar($suma, $item->cantidad), '0');

        return view('livewire.ventas.detalle', [
            'pagos' => HistorialPagos::de($venta),
            'venta' => $venta,
            // Decisión D4: con factura que existe o puede existir en el SIN, primero se anula.
            'puedeDevolver' => Gate::allows('devolver', $venta) && Decimal::comparar($devueltas, $vendidas) < 0
                && ! SiatFactura::query()->where('venta_vigente', $venta->id)
                    ->whereIn('estado', [EstadoFactura::Pendiente, EstadoFactura::Validada, EstadoFactura::Observada])->exists(),
        ])->title($venta->codigo());
    }
}
