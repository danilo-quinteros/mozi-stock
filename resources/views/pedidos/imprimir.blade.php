@use('App\Support\Decimal')
@php($documento = $pedido->venta ? 'Nota de venta' : 'Cotización')
<x-hoja-impresion :titulo="__($documento).' '.$pedido->codigo()" :empresa="$pedido->empresa" :documento="$documento"
    :detalle="($pedido->venta ? $pedido->venta->codigo().' · ' : '').$pedido->codigo().' · '.$pedido->created_at?->timezone(config('mozistock.business_timezone'))->format('d/m/Y')"
    :volver="route('pedidos.editar', $pedido)" volver-etiqueta="Volver al pedido">
    <p><b>{{ __('Cliente:') }}</b> {{ $pedido->cliente->nombre ?? __('Consumidor final') }}@if ($pedido->cliente?->documento) · {{ __('CI/NIT :documento', ['documento' => $pedido->cliente->documento]) }}@endif<br>
        <b>{{ __('Sucursal:') }}</b> {{ $pedido->sucursal->nombre }} · <b>{{ __('Vendedor:') }}</b> {{ $pedido->vendedor->name }}</p>
    <table>
        <thead><tr><th>{{ __('Producto') }}</th><th class="der">{{ __('Cant.') }}</th><th class="der">{{ __('Precio') }}</th><th class="der">{{ __('Desc.') }}</th><th class="der">{{ __('Importe') }}</th></tr></thead>
        <tbody>
            @foreach ($pedido->items as $item)
                <tr>
                    <td>{{ $item->producto->nombre }} <span class="muted">({{ $item->tipo_precio->etiqueta() }})</span></td>
                    <td class="der">{{ Decimal::formatear($item->cantidad) }} {{ $item->producto->unidad->abreviatura }}</td>
                    <td class="der">{{ Decimal::bs($item->precio_unitario) }}</td>
                    <td class="der">{{ Decimal::comparar($item->descuento, '0', 2) > 0 ? '− '.Decimal::bs($item->descuento) : '—' }}</td>
                    <td class="der">{{ Decimal::bs($item->total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="totales">
        <div><span>{{ __('Subtotal') }}</span><span>{{ Decimal::bs($pedido->subtotal) }}</span></div>
        <div><span>{{ __('Descuentos') }}</span><span>− {{ Decimal::bs(Decimal::sumar($pedido->descuento_lineas, $pedido->descuento_global, 2)) }}</span></div>
        <div class="total"><span>{{ __('Total') }}</span><span>{{ Decimal::bs($pedido->total) }}</span></div>
    </div>
    @if ($pedido->nota)<p><b>{{ __('Nota:') }}</b> {{ $pedido->nota }}</p>@endif
    <p class="aviso">{{ __('Documento informativo: no es factura ni comprobante fiscal. Precios sujetos a disponibilidad de stock al momento de la venta.') }}</p>
</x-hoja-impresion>
