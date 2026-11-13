{{-- Despachos § 4 y cocina § 4.1: el mismo control en Confirmar venta y en el POS. Desmarcado (por defecto) = Por despachar o En cocina. --}}
<label class="entrega-check">
    <input type="checkbox" wire:model.live="entregado" data-casilla-entregado>
    <span>
        <b>{{ __('Entregado') }}</b>
        <small>{{ $flujoEntrega === \App\Enums\Capacidad::Cocina
            ? __('Márcalo si el cliente recibe todo en este momento. Si no, el pedido queda En cocina hasta que lo entregues.')
            : __('Márcalo si el cliente recibe los productos en este momento. Si no, la venta queda Por despachar y el almacén debe autorizar la salida.') }}</small>
    </span>
</label>
