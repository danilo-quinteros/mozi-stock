@use('App\Support\Decimal')
@php($documento = $pedido->venta ? 'Nota de venta' : 'Cotización')
<x-hoja-impresion :titulo="__($documento).' '.$pedido->codigo()" :empresa="$pedido->empresa" :documento="$documento"
    :detalle="($pedido->venta ? $pedido->venta->codigo().' · ' : '').$pedido->codigo().' · '.$pedido->created_at?->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i')"
    :volver="route('pedidos.editar', $pedido)" volver-etiqueta="Volver al pedido"
    :rollo="$rollo ?? (request('formato') === 'rollo')">
    <p><b>{{ __('Cliente:') }}</b> {{ $pedido->cliente->nombre ?? __('Consumidor final') }}@if ($pedido->cliente?->documento) · {{ __('CI/NIT :documento', ['documento' => $pedido->cliente->documento]) }}@endif<br>
        <b>{{ __('Sucursal:') }}</b> {{ $pedido->sucursal->nombre }} · <b>{{ __('Vendedor:') }}</b> {{ $pedido->vendedor->name }}@if ($pedido->venta?->nombre_mesa)<br>
        {{-- Cocina § 4.1: «Nombre o mesa» se imprime en el ticket. --}}
        <b>{{ __('Nombre o mesa:') }}</b> {{ $pedido->venta->nombre_mesa }}@endif</p>
    <table>
        <thead>
            <tr>
                @if ($rollo ?? (request('formato') === 'rollo'))
                    <th class="der">{{ __('C.') }}</th>
                    <th>{{ __('DESCRIPCIÓN') }}</th>
                    <th class="der">{{ __('PRECIO') }}</th>
                    <th class="der">{{ __('DESC.') }}</th>
                    <th class="der">{{ __('SUBTOTAL') }}</th>
                @else
                    <th class="der">{{ __('CANTIDAD') }}</th>
                    <th>{{ __('DESCRIPCIÓN') }}</th>
                    <th>{{ __('CÓDIGO') }}</th>
                    <th class="der">{{ __('PRECIO') }}</th>
                    <th class="der">{{ __('DESCUENTO') }}</th>
                    <th class="der">{{ __('SUBTOTAL') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($pedido->items as $item)
                <tr>
                    <td class="der">{{ Decimal::formatear($item->cantidad) }}@unless ($rollo ?? (request('formato') === 'rollo')) {{ $item->producto->unidad->abreviatura }}@endunless</td>
                    <td>{{ \App\Models\Producto::descripcionConMarca($item->producto->nombre, $item->producto->marca) }}@unless ($rollo ?? (request('formato') === 'rollo')) <span class="muted">({{ $item->tipo_precio->etiqueta() }})</span>@endunless</td>
                    @unless ($rollo ?? (request('formato') === 'rollo'))
                        <td>{{ $item->producto->codigo_barras ?? $item->producto->sku ?? '—' }}</td>
                    @endunless
                    <td class="der">{{ Decimal::bs($item->precio_unitario) }}</td>
                    <td class="der">{{ Decimal::comparar($item->descuento, '0', 2) > 0 ? Decimal::bs($item->descuento) : '—' }}</td>
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
