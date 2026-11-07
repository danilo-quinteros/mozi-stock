@use('App\Support\Decimal')
@use('App\Models\CuentaPagar')
@php([$tono, $texto] = ['anulada' => ['', 'Anulada'], 'pagada' => ['success', 'Pagada'], 'vencida' => ['danger', 'Vencida'], 'parcial' => ['warning', 'Parcial'], 'pendiente' => ['info', 'Pendiente']][$estado])
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Cuentas por pagar') }}</div>
            <h1>{{ $cuenta->codigo() }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ $cuenta->concepto }}</p>
        </div>
        <div class="page-actions">
            @if ($cuenta->origen === 'manual' && ! $cuenta->estaAnulado())
                <button class="btn ghost" type="button" wire:click="$dispatch('anular', { tipo: 'obligacion', id: {{ $cuenta->id }} })"><x-icon name="x" /> {{ __('Anular obligación') }}</button>
            @endif
            <a class="btn ghost" href="{{ route('cuentas-pagar.index') }}"><x-icon name="arrow-left" /> {{ __('Volver a Cuentas por pagar') }}</a>
        </div>
    </div>

    <div class="credit-heading">
        <div class="credit-tag">{{ __(CuentaPagar::ORIGENES[$cuenta->origen]) }} · <strong>{{ $cuenta->compra?->codigo() ?? $cuenta->egreso?->codigo() ?? __('Manual') }}</strong></div>
        <div><b>{{ $cuenta->proveedor->nombre }}</b><span class="subtle"> · {{ $cuenta->sucursal->nombre }} · <span class="badge {{ $tono }}">{{ __($texto) }}</span></span></div>
    </div>

    @if ($cuenta->estaAnulado())
        <div class="notice" style="margin-bottom:18px"><x-icon name="info" /><div><b>{{ __('Obligación anulada') }}</b><p>{{ $cuenta->descripcionAnulacion() }}. {{ __('No cuenta en saldos ni admite pagos.') }}</p></div></div>
    @endif

    <div class="grid three credit-kpis" style="margin-bottom:18px">
        <article class="card stat"><span class="stat-label">{{ __('Importe del documento') }}</span><div class="stat-value">{{ Decimal::bs($cuenta->total_documento) }}</div><div class="stat-foot">{{ __('Pagado al registrar: :importe', ['importe' => Decimal::bs($cuenta->pagado_origen)]) }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Total pagado') }}</span><div class="stat-value">{{ Decimal::bs($cuenta->totalPagado()) }}</div><div class="stat-foot">{{ __('Incluye lo pagado al registrar') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Saldo pendiente') }}</span><div class="stat-value accent-text">{{ Decimal::bs($cuenta->saldo_pendiente) }}</div><div class="stat-foot">{{ __('Vence el :fecha', ['fecha' => $cuenta->vencimiento->format('d/m/Y')]) }}</div></article>
    </div>

    <div class="credit-grid">
        <div style="display:grid;gap:18px;align-content:start">
            <section class="card card-pad">
                <div class="heading">{{ __('Datos de la cuenta') }}</div>
                <div class="list-line"><span class="subtle">{{ __('Fecha') }}</span><b>{{ $cuenta->fecha->format('d/m/Y') }}</b></div>
                <div class="list-line"><span class="subtle">{{ __('Origen') }}</span><b>
                    @if ($cuenta->compra)
                        <a class="link" href="{{ route('compras.ver', $cuenta->compra) }}">{{ $cuenta->compra->codigo() }}</a>
                    @elseif ($cuenta->egreso)
                        <a class="link" href="{{ route('egresos.index', ['egreso' => $cuenta->egreso->id]) }}">{{ $cuenta->egreso->codigo() }}</a>
                    @else
                        {{ __('Obligación manual') }}
                    @endif
                </b></div>
                <div class="list-line"><span class="subtle">{{ __('Registró') }}</span><b>{{ $cuenta->usuario->name }}</b></div>
                @if ($cuenta->nota)<div class="list-line"><span class="subtle">{{ __('Nota') }}</span><b>{{ $cuenta->nota }}</b></div>@endif
                @if ($cuenta->respaldo_path)<div class="list-line"><span class="subtle">{{ __('Respaldo') }}</span><b><a class="link" href="{{ route('cuentas-pagar.respaldo', $cuenta) }}" target="_blank">{{ __('Ver archivo') }}</a></b></div>@endif
            </section>

            <section class="card card-pad">
                <div class="card-head"><div><div class="heading">{{ __('Pagos realizados') }}</div><p class="subtle" style="margin-top:8px">{{ __('Cada pago figura como salida de dinero en su fecha real.') }}</p></div></div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>{{ __('Fecha') }}</th><th>{{ __('Detalle') }}</th><th>{{ __('Efectivo') }}</th><th>{{ __('Electrónico') }}</th><th>{{ __('Total') }}</th></tr></thead>
                        <tbody>
                            @if (Decimal::comparar($cuenta->pagado_origen, '0', 2) > 0)
                                <tr><td>{{ $cuenta->fecha->format('d/m/Y') }}</td><td><b>{{ __('Pagado al registrar') }}</b><small style="display:block;color:var(--faint);margin-top:4px">{{ $cuenta->origen === 'manual' ? __('Antes de MoziStock: no es una salida de caja') : __('Registrado con el documento de origen') }}</small></td><td>—</td><td>—</td><td><b>{{ Decimal::bs($cuenta->pagado_origen) }}</b></td></tr>
                            @endif
                            @forelse ($cuenta->pagos as $pago)
                                <tr wire:key="pago-{{ $pago->id }}" @if ($pago->estaAnulado()) style="opacity:.6" @endif>
                                    <td>{{ $pago->fecha_pago->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i') }}</td>
                                    <td>
                                        <b>{{ __('Pago') }}</b> · {{ $pago->metodo->etiqueta() }}@if ($pago->estaAnulado()) <span class="badge">{{ __('Anulado') }}</span>@endif
                                        <small style="display:block;color:var(--faint);margin-top:4px">{{ $pago->sucursal->nombre }} · {{ $pago->pagador->name }}@if ($pago->externo) · {{ __('fuera de caja') }}@endif @if ($pago->referencia) · {{ $pago->referencia }}@endif @if ($pago->nota) · {{ $pago->nota }}@endif @if ($pago->comprobante_path) · <a class="link" href="{{ route('cuentas-pagar.comprobante', $pago) }}" target="_blank">{{ __('Comprobante') }}</a>@endif</small>
                                        @if ($pago->estaAnulado())<small style="display:block;color:var(--faint);margin-top:4px">{{ $pago->descripcionAnulacion() }}</small>
                                        @else<button type="button" class="btn ghost sm" style="margin-top:6px" wire:click="$dispatch('anular', { tipo: 'pago', id: {{ $pago->id }} })">{{ __('Anular pago') }}</button>@endif
                                    </td>
                                    <td @if ($pago->estaAnulado()) style="text-decoration:line-through" @endif>{{ Decimal::bs($pago->monto_efectivo) }}</td>
                                    <td @if ($pago->estaAnulado()) style="text-decoration:line-through" @endif>{{ Decimal::bs($pago->monto_electronico) }}</td>
                                    <td @if ($pago->estaAnulado()) style="text-decoration:line-through" @endif><b>{{ Decimal::bs($pago->monto) }}</b></td>
                                </tr>
                            @empty
                                <tr><td colspan="5"><div class="empty">{{ __('Aún no hay pagos posteriores.') }}</div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card card-pad">
                <div class="heading">{{ __('Historial de cambios') }}</div>
                @forelse ($cambios as $cambio)
                    <div class="list-line"><span><b>{{ $cambio['accion'] }}</b><small style="display:block;color:var(--faint);margin-top:4px">{{ $cambio['detalle'] }}</small></span><span class="subtle" style="text-align:right">{{ $cambio['fecha']->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i') }}<small style="display:block;margin-top:4px">{{ $cambio['usuario'] }}</small></span></div>
                @empty
                    <p class="subtle" style="margin-top:8px">{{ __('Sin cambios registrados.') }}</p>
                @endforelse
            </section>
        </div>

        <aside class="card card-pad credit-payment-card">
            <div class="heading">{{ __('Registrar pago') }}</div>
            @if ($pendiente)
                <p class="subtle" style="margin:8px 0 20px">{{ __('Registra solo lo que pagas ahora. No crea otro egreso ni otra compra.') }}</p>
                <form wire:submit="registrar">
                    @include('livewire.partials.pago', ['etiquetaMonto' => 'Monto pagado (Bs)'])
                    <div class="credit-payment-hint">{{ __('Saldo actual :saldo. El pago se registra con la fecha y hora reales, una sola vez.', ['saldo' => Decimal::bs($cuenta->saldo_pendiente)]) }}</div>
                    <button class="btn primary" type="submit" style="width:100%" wire:loading.attr="disabled" wire:target="registrar,comprobante">{{ __('Registrar pago') }}</button>
                </form>
            @elseif ($cuenta->estaAnulado())
                <div class="notice" style="margin-top:14px"><x-icon name="info" /><div><b>{{ __('Obligación anulada') }}</b><p>{{ __('No se admiten pagos.') }}</p></div></div>
            @else
                <div class="notice" style="margin-top:14px"><x-icon name="check" /><div><b>{{ __('Cuenta pagada') }}</b><p>{{ __('El saldo es Bs 0,00. No se admiten más pagos.') }}</p></div></div>
            @endif
        </aside>
    </div>

    <livewire:cuentas.anular :volver="route('cuentas-pagar.ver', $cuenta)" />

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
