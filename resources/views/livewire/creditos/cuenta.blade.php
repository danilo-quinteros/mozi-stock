@use('App\Support\Decimal')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Cuentas por cobrar') }}</div>
            <h1>{{ __('Cuenta manual') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Deuda registrada a mano: no es una venta ni movió stock.') }}</p>
        </div>
        <div class="page-actions">
            @if ($administra && ! $cuenta->estaAnulado())
                <button class="btn ghost" type="button" wire:click="$dispatch('anular', { tipo: 'cuenta', id: {{ $cuenta->id }} })"><x-icon name="x" /> {{ __('Anular cuenta') }}</button>
            @endif
            <a class="btn ghost" href="{{ route('creditos.index') }}"><x-icon name="arrow-left" /> {{ __('Volver a Cuentas por cobrar') }}</a>
        </div>
    </div>

    <div class="credit-heading">
        <div class="credit-tag">{{ __('CUENTA ·') }} <strong>{{ $cuenta->codigo() }}</strong></div>
        <div><b>{{ $cuenta->cliente->nombre }}</b><span class="subtle"> · {{ $cuenta->concepto }} · {{ $cuenta->sucursal->nombre }}</span>@if ($cuenta->estaAnulado()) <span class="badge">{{ __('Anulada') }}</span>@endif</div>
    </div>
    @if ($cuenta->estaAnulado())
        <div class="notice" style="margin-bottom:18px"><x-icon name="info" /><div><b>{{ __('Cuenta anulada') }}</b><p>{{ $cuenta->descripcionAnulacion() }}. {{ __('No cuenta en saldos ni admite cobros.') }}</p></div></div>
    @endif

    <div class="grid three credit-kpis" style="margin-bottom:18px">
        <article class="card stat"><span class="stat-label">{{ __('Importe') }}</span><div class="stat-value">{{ Decimal::bs($cuenta->importe) }}</div><div class="stat-foot">{{ __('Registrada el :fecha por :name', ['fecha' => $cuenta->fecha->format('d/m/Y'), 'name' => $cuenta->usuario->name]) }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Total cobrado') }}</span><div class="stat-value">{{ Decimal::bs($cobrado) }}</div><div class="stat-foot">{{ __('Solo cobros efectivamente recibidos') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Saldo pendiente') }}</span><div class="stat-value accent-text">{{ Decimal::bs($cuenta->saldo_pendiente) }}</div><div class="stat-foot">{{ $cuenta->vencimiento ? __('Vence el :fecha', ['fecha' => $cuenta->vencimiento->format('d/m/Y')]) : __('Sin vencimiento') }}</div></article>
    </div>

    <div class="credit-grid">
        <section class="card card-pad">
            <div class="card-head"><div><div class="heading">{{ __('Historial de cobros') }}</div>@if ($cuenta->nota)<p class="subtle" style="margin-top:8px">{{ $cuenta->nota }}</p>@endif</div></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Fecha') }}</th><th>{{ __('Concepto') }}</th><th>{{ __('Efectivo') }}</th><th>{{ __('Electrónico') }}</th><th>{{ __('Total') }}</th></tr></thead>
                    <tbody>
                        @forelse ($cuenta->aplicaciones->sortBy(fn ($a) => $a->cobro->fecha_cobro) as $aplicacion)
                            @php($cobro = $aplicacion->cobro)
                            <tr wire:key="aplicacion-{{ $aplicacion->id }}" @if ($cobro->estaAnulado()) style="opacity:.6" @endif>
                                <td>{{ $cobro->fecha_cobro->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i') }}</td>
                                <td>
                                    <b>{{ __('Cobro :codigo', ['codigo' => $cobro->codigo()]) }}</b> · {{ $cobro->metodo->etiqueta() }}@if ($cobro->estaAnulado()) <span class="badge">{{ __('Anulado') }}</span>@endif
                                    <small style="display:block;color:var(--faint);margin-top:4px">{{ $cobro->sucursal->nombre }} · {{ $cobro->receptor->name }}@if ($cobro->externo) · {{ __('fuera de caja') }}@endif @if ($cobro->referencia) · {{ $cobro->referencia }}@endif @if ($cobro->comprobante_path) · <a class="link" href="{{ route('cobros.comprobante', $cobro) }}" target="_blank">{{ __('Comprobante') }}</a>@endif</small>
                                    @if ($cobro->estaAnulado())<small style="display:block;color:var(--faint);margin-top:4px">{{ $cobro->descripcionAnulacion() }}</small>@endif
                                    @if ($administra && ! $cobro->estaAnulado())<button type="button" class="btn ghost sm" style="margin-top:6px" wire:click="$dispatch('anular', { tipo: 'cobro', id: {{ $cobro->id }} })">{{ __('Anular cobro') }}</button>@endif
                                </td>
                                <td @if ($cobro->estaAnulado()) style="text-decoration:line-through" @endif>{{ Decimal::bs($aplicacion->monto_efectivo) }}</td>
                                <td @if ($cobro->estaAnulado()) style="text-decoration:line-through" @endif>{{ Decimal::bs($aplicacion->monto_electronico) }}</td>
                                <td @if ($cobro->estaAnulado()) style="text-decoration:line-through" @endif><b>{{ Decimal::bs($aplicacion->monto) }}</b></td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><div class="empty">{{ __('Aún no hay cobros.') }}</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <aside class="card card-pad credit-payment-card">
            <div class="heading">{{ __('Registrar cobro') }}</div>
            @if ($pendiente)
                <p class="subtle" style="margin:8px 0 20px">{{ __('Registra únicamente el importe recibido. El saldo se actualiza al confirmar.') }}</p>
                <form wire:submit="registrar">
                    @include('livewire.partials.pago', ['etiquetaMonto' => 'Monto cobrado (Bs)'])
                    <div class="credit-payment-hint">{{ __('Saldo actual :saldo. El cobro se registra con la fecha y hora reales, una sola vez.', ['saldo' => Decimal::bs($cuenta->saldo_pendiente)]) }}</div>
                    <button class="btn primary" type="submit" style="width:100%" wire:loading.attr="disabled" wire:target="registrar,comprobante">{{ __('Registrar cobro') }}</button>
                </form>
            @elseif ($cuenta->estaAnulado())
                <div class="notice" style="margin-top:14px"><x-icon name="info" /><div><b>{{ __('Cuenta anulada') }}</b><p>{{ __('No se admiten cobros.') }}</p></div></div>
            @else
                <div class="notice" style="margin-top:14px"><x-icon name="check" /><div><b>{{ __('Cuenta pagada') }}</b><p>{{ __('El saldo es Bs 0,00. No se admiten más cobros.') }}</p></div></div>
            @endif
        </aside>
    </div>

    @if ($administra)
        <livewire:cuentas.anular :volver="route('creditos.cuenta', $cuenta)" />
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
