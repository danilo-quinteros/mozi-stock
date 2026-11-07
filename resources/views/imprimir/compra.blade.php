@use('App\Support\Decimal')
<x-hoja-impresion :titulo="__('Compra :codigo', ['codigo' => $compra->codigo()])" :empresa="$compra->empresa" documento="Compra"
    :detalle="$compra->codigo().' · '.$compra->fecha->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i')"
    :volver="route('compras.ver', $compra)" volver-etiqueta="Volver a la compra">
    <p><b>{{ __('Proveedor:') }}</b> {{ $compra->proveedor->nombre ?? __('Sin proveedor') }}@if ($compra->proveedor?->documento) {{ __('· NIT/CI :documento', ['documento' => $compra->proveedor->documento]) }}@endif<br>
        <b>{{ __('Sucursal:') }}</b> {{ $compra->sucursal->nombre }} · <b>{{ __('Registró:') }}</b> {{ $compra->usuario->name }} · <b>{{ __('Entrada de stock:') }}</b> {{ $compra->operacion->codigo() }}
        @if ($compra->referencia)<br><b>{{ __('Referencia:') }}</b> {{ $compra->referencia }}@endif</p>
    <table>
        <thead><tr><th>{{ __('Producto') }}</th><th class="der">{{ __('Cantidad') }}</th><th class="der">{{ __('Costo unitario') }}</th><th class="der">{{ __('Subtotal') }}</th></tr></thead>
        <tbody>
            @foreach ($compra->items as $item)
                <tr>
                    <td>{{ $item->descripcion }} <span class="muted">{{ $item->sku }}</span></td>
                    <td class="der">{{ Decimal::formatear($item->cantidad) }} {{ $item->unidad }}</td>
                    <td class="der">{{ Decimal::bs($item->costo_unitario) }}</td>
                    <td class="der">{{ Decimal::bs($item->subtotal) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="totales">
        <div><span>{{ __('Medio de pago') }}</span><span>{{ $compra->metodo?->etiqueta() ?? __('Sin pago al registrar') }}</span></div>
        <div><span>{{ __('Efectivo') }}</span><span>{{ Decimal::bs($compra->monto_efectivo) }}</span></div>
        <div><span>{{ __('Electrónico') }}</span><span>{{ Decimal::bs($compra->monto_electronico) }}</span></div>
        <div class="total"><span>{{ __('Total pagado') }}</span><span>{{ Decimal::bs($compra->total) }}</span></div>
    </div>
    <p class="aviso">{{ __('Documento interno de la empresa. No reemplaza la factura del proveedor.') }}</p>
</x-hoja-impresion>
