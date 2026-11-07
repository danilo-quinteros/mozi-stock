@use('App\Support\Decimal')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Cuentas por cobrar') }}</div>
            <h1>{{ __('Registrar cobro') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Un solo pago del cliente repartido entre sus deudas. Entra a caja una sola vez.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('creditos.index') }}"><x-icon name="arrow-left" /> {{ __('Volver a Cuentas por cobrar') }}</a>
        </div>
    </div>

    <div class="credit-grid">
        <section class="card card-pad">
            <x-campo etiqueta="Cliente" campo="cliente_id">
                <select id="cliente_id" class="select" wire:model.live="cliente">
                    <option value="">{{ __('Selecciona un cliente con saldo') }}</option>
                    @foreach ($clientes as $opcion)
                        <option value="{{ $opcion->id }}">{{ $opcion->nombre }}{{ $opcion->documento ? ' · '.$opcion->documento : '' }} · {{ Decimal::bs((string) $opcion->saldo) }}</option>
                    @endforeach
                </select>
            </x-campo>
            <div class="divider"></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Documento') }}</th><th>{{ __('Detalle') }}</th><th>{{ __('Vencimiento') }}</th><th>{{ __('Saldo') }}</th>@if ($modo === 'manual')<th>{{ __('Aplicar (Bs)') }}</th>@endif</tr></thead>
                    <tbody>
                        @forelse ($items as $item)
                            <tr wire:key="item-{{ $item['clave'] }}">
                                <td><b>{{ $item['documento'] }}</b></td>
                                <td>{{ $item['detalle'] }}</td>
                                <td>{{ $item['vencimiento'] ? \Illuminate\Support\Carbon::parse($item['vencimiento'])->format('d/m/Y') : '—' }}</td>
                                <td><b>{{ Decimal::bs($item['saldo']) }}</b></td>
                                @if ($modo === 'manual')
                                    <td><input class="input" type="text" inputmode="decimal" wire:model="asignaciones.{{ $item['clave'] }}" placeholder="0,00" aria-label="{{ __('Importe para :documento', ['documento' => $item['documento']]) }}"></td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="5"><div class="empty">{{ $elegido ? __('El cliente no tiene saldo pendiente.') : __('Elige un cliente para ver sus deudas.') }}</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @error('asignaciones')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
            @if ($items !== [])
                <div class="summary-money" style="margin-top:14px"><span>{{ __('Saldo total del cliente') }}</span><b>{{ Decimal::bs($saldoTotal) }}</b></div>
            @endif
        </section>

        <aside class="card card-pad credit-payment-card">
            <div class="heading">{{ __('Datos del cobro') }}</div>
            <form wire:submit="registrar" style="margin-top:16px">
                <x-campo etiqueta="Aplicación" campo="modo">
                    <select id="modo" class="select" wire:model.live="modo">
                        <option value="automatico">{{ __('Automática · vencimientos más antiguos primero') }}</option>
                        <option value="manual">{{ __('Manual · elijo cuánto a cada deuda') }}</option>
                    </select>
                </x-campo>
                @include('livewire.partials.pago', ['etiquetaMonto' => 'Monto cobrado (Bs)'])
                <button class="btn primary" type="submit" style="width:100%" wire:loading.attr="disabled" wire:target="registrar,comprobante" @disabled($items === [])>{{ __('Registrar cobro') }}</button>
            </form>
        </aside>
    </div>

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
