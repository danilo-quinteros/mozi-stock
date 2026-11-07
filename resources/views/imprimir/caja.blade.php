@use('App\Support\Decimal')
@use('App\Domain\Reportes\Consultas\Salidas')
@php($zona = config('mozistock.business_timezone'))
@php($fechas = $dia->esUnDia() ? $dia->desde->format('d/m/Y') : $dia->desde->format('d/m/Y').' – '.$dia->hasta->format('d/m/Y'))
@php($formatoHora = $dia->esUnDia() ? 'H:i' : 'd/m H:i')
<x-hoja-impresion :titulo="__('Caja :fecha', ['fecha' => $fechas])" :empresa="$empresa" documento="Detalle de caja"
    :detalle="$fechas.' · '.($sucursal->nombre ?? __('Todas las sucursales')).($caja ? ' · '.$caja->nombre : ($fueraDeCaja ? ' · '.__('Fuera de caja') : '')).($metodo === '' ? '' : ' · '.($metodo === 'efectivo' ? __('Efectivo') : __('Electrónico')))"
    :volver="route('caja', $filtros)" volver-etiqueta="Volver a caja">
    <div class="totales" style="width:100%">
        <div><span>{{ __('Cobros en efectivo') }}</span><span>+ {{ Decimal::bs($resumen['efectivo']) }}</span></div>
        <div><span>{{ __('Cobros electrónicos') }}</span><span>+ {{ Decimal::bs($resumen['electronico']) }}</span></div>
        <div><span>{{ __('Salidas en efectivo') }}</span><span>− {{ Decimal::bs($resumen['egresos_efectivo']) }}</span></div>
        <div><span>{{ __('Salidas electrónicas') }}</span><span>− {{ Decimal::bs($resumen['egresos_electronico']) }}</span></div>
        <div><span>{{ __('Caja efectivo neto') }}</span><span>{{ Decimal::bs($resumen['neto_efectivo']) }}</span></div>
        <div><span>{{ __('Movimiento electrónico neto') }}</span><span>{{ Decimal::bs($resumen['neto_electronico']) }}</span></div>
        <div class="total"><span>{{ __('Movimiento neto de caja') }}</span><span>{{ Decimal::bs($resumen['neto']) }}</span></div>
    </div>

    <h2>{{ $dia->esUnDia() ? __('Cobros del día') : __('Cobros del período') }}</h2>
    <table>
        <thead><tr><th>{{ __('Hora') }}</th><th>{{ __('Origen') }}</th><th>{{ __('Cliente') }}</th><th>{{ __('Sucursal') }}</th><th>{{ __('Recibió') }}</th><th class="der">{{ __('Efectivo') }}</th><th class="der">{{ __('Electrónico') }}</th><th class="der">{{ __('Cobrado') }}</th></tr></thead>
        <tbody>
            @forelse ($cobros as $cobro)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($cobro->fecha_cobro, 'UTC')->timezone($zona)->format($formatoHora) }}</td>
                    <td>{{ \App\Domain\Reportes\Consultas\Cobros::concepto($cobro) }} · {{ \App\Domain\Reportes\Consultas\Cobros::referencia($cobro) }}</td>
                    <td>{{ $cobro->cliente ?? __('Consumidor final') }}</td>
                    <td>{{ $cobro->sucursal }}{{ $cobro->caja ? ' · '.$cobro->caja : '' }}</td>
                    <td>{{ $cobro->recibido_por }}</td>
                    <td class="der">{{ Decimal::bs((string) $cobro->efectivo) }}</td>
                    <td class="der">{{ Decimal::bs((string) $cobro->electronico) }}</td>
                    <td class="der">{{ Decimal::bs((string) $cobro->total) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">{{ __('No hay cobros en esta fecha.') }}</td></tr>
            @endforelse
        </tbody>
        <tfoot><tr><td colspan="5">{{ __('Total cobrado') }}</td><td class="der">{{ Decimal::bs($resumen['efectivo']) }}</td><td class="der">{{ Decimal::bs($resumen['electronico']) }}</td><td class="der">{{ Decimal::bs($resumen['total']) }}</td></tr></tfoot>
    </table>

    <h2>{{ $dia->esUnDia() ? __('Salidas del día') : __('Salidas del período') }}</h2>
    <table>
        <thead><tr><th>{{ __('Hora') }}</th><th>{{ __('Concepto') }}</th><th>{{ __('Detalle') }}</th><th>{{ __('Sucursal') }}</th><th>{{ __('Registró') }}</th><th class="der">{{ __('Efectivo') }}</th><th class="der">{{ __('Electrónico') }}</th><th class="der">{{ __('Pagado') }}</th></tr></thead>
        <tbody>
            @forelse ($salidas as $salida)
                @php($descripcion = Salidas::describir($salida))
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($salida->fecha_operacion, 'UTC')->timezone($zona)->format($formatoHora) }}</td>
                    <td>{{ $descripcion['concepto'] }} · {{ $descripcion['referencia'] }}</td>
                    <td>{{ $descripcion['detalle'] }}</td>
                    <td>{{ $salida->sucursal }}{{ $salida->caja ? ' · '.$salida->caja : '' }}</td>
                    <td>{{ $salida->usuario }}</td>
                    <td class="der">{{ Decimal::bs((string) $salida->efectivo) }}</td>
                    <td class="der">{{ Decimal::bs((string) $salida->electronico) }}</td>
                    <td class="der">− {{ Decimal::bs((string) $salida->total) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">{{ __('No hay salidas de dinero en esta fecha.') }}</td></tr>
            @endforelse
        </tbody>
        <tfoot><tr><td colspan="5">{{ __('Total salidas') }}</td><td class="der">{{ Decimal::bs($resumen['egresos_efectivo']) }}</td><td class="der">{{ Decimal::bs($resumen['egresos_electronico']) }}</td><td class="der">− {{ Decimal::bs($resumen['egresos']) }}</td></tr></tfoot>
    </table>
    <p class="aviso">{{ __('El neto de caja representa entradas reales menos salidas reales. No es margen comercial ni ganancia.') }}</p>
</x-hoja-impresion>
