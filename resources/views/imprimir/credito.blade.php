@use('App\Support\Decimal')
@php($zona = config('mozistock.business_timezone'))
<x-hoja-impresion :titulo="__('Crédito :codigo', ['codigo' => $venta->codigo()])" :empresa="$venta->empresa" documento="Estado de crédito"
    :detalle="$venta->codigo().' · '.__('Impreso :fecha', ['fecha' => now()->timezone($zona)->format('d/m/Y H:i')])"
    :volver="route('creditos.historial', $venta)" volver-etiqueta="Volver al historial">
    <p><b>{{ __('Cliente:') }}</b> {{ $venta->cliente->nombre ?? __('Sin cliente') }}@if ($venta->cliente?->documento) {{ __('· CI/NIT :documento', ['documento' => $venta->cliente->documento]) }}@endif<br>
        <b>{{ __('Venta:') }}</b> {{ __(':codigo del pedido :codigo2 · :format', ['codigo' => $venta->codigo(), 'codigo2' => $venta->pedido->codigo(), 'format' => $venta->fecha->timezone($zona)->format('d/m/Y H:i')]) }}<br>
        <b>{{ __('Sucursal:') }}</b> {{ $venta->sucursal->nombre }} · <b>{{ __('Vendedor:') }}</b> {{ $venta->vendedor->name }}</p>

    <h2>{{ __('Desglose de pagos') }}</h2>
    <table>
        <thead><tr><th>{{ __('Fecha') }}</th><th>{{ __('Concepto') }}</th><th>{{ __('Método') }}</th><th>{{ __('Recibió') }}</th><th class="der">{{ __('Efectivo') }}</th><th class="der">{{ __('Electrónico') }}</th><th class="der">{{ __('Total') }}</th></tr></thead>
        <tbody>
            @forelse ($venta->pagos as $pago)
                <tr>
                    <td>{{ $pago->fecha_cobro->timezone($zona)->format('d/m/Y H:i') }}</td>
                    <td>{{ $pago->tipo === 'inicial' ? __('Cuota inicial') : __('Abono') }}@if ($pago->nota)<br><span class="muted">{{ $pago->nota }}</span>@endif</td>
                    <td>{{ $pago->metodo->etiqueta() }}</td>
                    <td>{{ $pago->receptor->name }}<br><span class="muted">{{ $pago->sucursal->nombre }}</span></td>
                    <td class="der">{{ Decimal::bs($pago->monto_efectivo) }}</td>
                    <td class="der">{{ Decimal::bs($pago->monto_electronico) }}</td>
                    <td class="der">{{ Decimal::bs($pago->monto) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">{{ __('Sin pagos registrados (cuota inicial Bs 0,00).') }}</td></tr>
            @endforelse
        </tbody>
        @if ($venta->pagos->isNotEmpty())
            <tfoot><tr>
                <td colspan="4">{{ __('Total abonado') }}</td>
                <td class="der">{{ Decimal::bs($venta->pagos->reduce(fn ($s, $p) => Decimal::sumar($s, $p->monto_efectivo, 2), '0')) }}</td>
                <td class="der">{{ Decimal::bs($venta->pagos->reduce(fn ($s, $p) => Decimal::sumar($s, $p->monto_electronico, 2), '0')) }}</td>
                <td class="der">{{ Decimal::bs($venta->cobrado()) }}</td>
            </tr></tfoot>
        @endif
    </table>

    <div class="totales">
        <div><span>{{ __('Total de venta') }}</span><span>{{ Decimal::bs($venta->total) }}</span></div>
        @if (Decimal::comparar($venta->devuelto, '0', 2) > 0)
            <div><span>{{ __('Devoluciones') }}</span><span>− {{ Decimal::bs($venta->devuelto) }}</span></div>
        @endif
        <div><span>{{ __('Total abonado') }}</span><span>{{ Decimal::bs($venta->cobrado()) }}</span></div>
        <div class="total"><span>{{ __('Saldo pendiente') }}</span><span>{{ Decimal::bs($venta->saldo_pendiente) }}</span></div>
    </div>
    <p class="aviso">{{ __('Documento informativo: no es factura ni comprobante fiscal. Cada abono figura en la fecha real en que se recibió.') }}</p>
</x-hoja-impresion>
