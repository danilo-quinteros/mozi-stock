@use('App\Support\Decimal')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Cuentas por cobrar') }}</div>
            <h1>{{ __('Historial de pagos') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Consulta todos los cobros y registra abonos del crédito sin salir de esta pantalla.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('creditos.index') }}"><x-icon name="arrow-left" /> {{ __('Volver a Créditos') }}</a>
            <a class="btn soft" href="{{ route('creditos.imprimir', $venta) }}" target="_blank"><x-icon name="print" /> {{ __('Imprimir con desglose') }}</a>
        </div>
    </div>

    <div class="credit-heading">
        <div class="credit-tag">{{ __('CRÉDITO ·') }} <strong>{{ $venta->codigo() }}</strong></div>
        <div><b>{{ $venta->cliente->nombre ?? __('Sin cliente') }}</b><span class="subtle"> {{ __('· :nombre · Vendedor: :name', ['nombre' => $venta->sucursal->nombre, 'name' => $venta->vendedor->name]) }}</span></div>
    </div>

    <div class="grid three credit-kpis" style="margin-bottom:18px">
        <article class="card stat"><span class="stat-label">{{ __('Total de venta') }}</span><div class="stat-value">{{ Decimal::bs($venta->total) }}</div><div class="stat-foot">{{ __('No equivale a dinero ingresado') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Total abonado') }}</span><div class="stat-value">{{ Decimal::bs($cobrado) }}</div><div class="stat-foot">{{ __('Cuota inicial + abonos posteriores') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Saldo pendiente') }}</span><div class="stat-value accent-text">{{ Decimal::bs($venta->saldo_pendiente) }}</div><div class="stat-foot">{{ $pendiente ? __('Crédito en curso') : __('Crédito pagado') }}</div></article>
    </div>

    @if (Decimal::comparar($venta->devuelto, '0', 2) > 0)
        <div class="notice" style="margin-bottom:18px"><x-icon name="info" /><div><b>{{ __('Devoluciones por :devuelto', ['devuelto' => Decimal::bs($venta->devuelto)]) }}</b><p>Redujeron primero el saldo{{ Decimal::comparar($venta->reembolsado, '0', 2) > 0 ? __(' y se reembolsaron ').Decimal::bs($venta->reembolsado) : '' }}. No son abonos ni cobros.@can('view', $venta) <a class="link" href="{{ route('ventas.ver', $venta) }}">{{ __('Ver la venta') }} <x-icon name="arrow" size="sm" /></a>@endcan</p></div></div>
    @endif

    <div class="credit-grid">
        <section class="card card-pad">
            <div class="card-head">
                <div><div class="heading">{{ __('Historial de pagos') }}</div><p class="subtle" style="margin-top:8px">{{ __('Cada abono aparece en su fecha real y con su método de pago.') }}</p></div>
                <a class="btn ghost sm" href="{{ route('creditos.historial.exportar', $venta) }}"><x-icon name="download" size="sm" /> CSV</a>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Fecha') }}</th><th>{{ __('Concepto') }}</th><th>{{ __('Efectivo') }}</th><th>{{ __('Electrónico') }}</th><th>{{ __('Total abonado') }}</th></tr></thead>
                    <tbody>
                        @forelse ($venta->pagos as $pago)
                            <tr wire:key="pago-{{ $pago->id }}">
                                <td>{{ $pago->fecha_cobro->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i') }}</td>
                                <td>
                                    <b>{{ $pago->tipo === 'inicial' ? __('Cuota inicial') : __('Abono') }}</b> · {{ $pago->metodo->etiqueta() }}
                                    <small style="display:block;color:var(--faint);margin-top:4px">{{ $pago->sucursal->nombre }} · {{ $pago->receptor->name }}@if ($pago->nota) · {{ $pago->nota }}@endif</small>
                                </td>
                                <td>{{ Decimal::bs($pago->monto_efectivo) }}</td>
                                <td>{{ Decimal::bs($pago->monto_electronico) }}</td>
                                <td><b>{{ Decimal::bs($pago->monto) }}</b></td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><div class="empty">{{ __('No hay pagos registrados (cuota inicial Bs 0,00).') }}</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <aside class="card card-pad credit-payment-card">
            <div class="heading">{{ __('Registrar abono') }}</div>
            @if ($pendiente)
                <p class="subtle" style="margin:8px 0 20px">{{ __('Registra únicamente el importe recibido. El saldo se actualiza al confirmar.') }}</p>
                <form wire:submit="registrar">
                    <div class="field">
                        <span class="field-label">{{ __('Fecha de pago') }}</span>
                        <input class="input" type="text" readonly value="Hoy, {{ now()->timezone(config('mozistock.business_timezone'))->format('d/m/Y') }} (al registrar)">
                    </div>
                    <x-campo etiqueta="Sucursal que recibe" campo="sucursal_id">
                        <select id="sucursal_id" class="select" wire:model="sucursal_id" @disabled(! $administra)>
                            @foreach ($sucursales as $opcion)
                                <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                            @endforeach
                        </select>
                    </x-campo>
                    <x-campo etiqueta="Método de pago" campo="metodo">
                        <select id="metodo" class="select" wire:model.live="metodo">
                            <option value="efectivo">{{ __('Efectivo') }}</option>
                            <option value="electronico">{{ __('Electrónico') }}</option>
                            <option value="mixto">{{ __('Mixto · efectivo + electrónico') }}</option>
                        </select>
                    </x-campo>
                    @if ($metodo === 'mixto')
                        <div>
                            <div class="credit-split">
                                <x-campo etiqueta="Efectivo (Bs)" campo="efectivo">
                                    <input id="efectivo" class="input" type="text" inputmode="decimal" wire:model.live.debounce.300ms="efectivo" placeholder="0,00">
                                </x-campo>
                                <x-campo etiqueta="Electrónico (Bs)" campo="electronico">
                                    <input id="electronico" class="input" type="text" inputmode="decimal" wire:model.live.debounce.300ms="electronico" placeholder="0,00">
                                </x-campo>
                            </div>
                            <div class="summary-count"><span>{{ __('Abono mixto') }}</span><b>{{ Decimal::bs($abonoMixto) }}</b></div>
                        </div>
                    @else
                        <x-campo etiqueta="Monto del abono (Bs)" campo="monto">
                            <input id="monto" class="input" type="text" inputmode="decimal" wire:model="monto" placeholder="0,00" required>
                        </x-campo>
                    @endif
                    <x-campo etiqueta="Nota (opcional)" campo="nota">
                        <input id="nota" class="input" type="text" maxlength="150" wire:model="nota" placeholder="{{ __('Referencia del pago') }}">
                    </x-campo>
                    <div class="credit-payment-hint">{{ __('Saldo actual :saldo_pendiente. El abono se registra con la fecha y hora reales, una sola vez, y cuenta en los cobros de hoy.', ['saldo_pendiente' => Decimal::bs($venta->saldo_pendiente)]) }}</div>
                    <button class="btn primary" type="submit" style="width:100%" wire:loading.attr="disabled" wire:target="registrar">{{ __('Registrar abono') }}</button>
                </form>
            @else
                <div class="notice" style="margin-top:14px"><x-icon name="check" /><div><b>{{ __('Crédito pagado') }}</b><p>{{ __('El saldo es Bs 0,00. No se admiten más abonos.') }}</p></div></div>
            @endif
        </aside>
    </div>

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
