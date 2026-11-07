@use('App\Support\Decimal')
@use('App\Domain\Creditos\Consultas\CuentasPorCobrar')
@php
    $etiquetas = ['anulada' => ['', 'Anulada'], 'pagada' => ['success', 'Pagada'], 'vencida' => ['danger', 'Vencida'], 'parcial' => ['warning', 'Parcial'], 'pendiente' => ['info', 'Pendiente']];
@endphp
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Ventas y finanzas') }}</div>
            <h1>{{ __('Cuentas por cobrar') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Ventas a crédito y cuentas manuales: lo pendiente, lo vencido y lo realmente cobrado.') }}</p>
        </div>
        <div class="page-actions">
            @if ($administra)
                <button class="btn ghost" type="button" wire:click="nuevaCuenta"><x-icon name="plus" /> {{ __('Cuenta manual') }}</button>
            @endif
            <a class="btn primary" href="{{ route('creditos.cobrar') }}"><x-icon name="wallet" /> {{ __('Registrar cobro') }}</a>
        </div>
    </div>

    <div class="grid four" style="margin-bottom:18px">
        <article class="card stat"><span class="stat-label">{{ __('Total por cobrar') }}</span><div class="stat-value">{{ Decimal::bs($resumen['pendiente']) }}</div><div class="stat-foot">{{ __('Saldo pendiente de todas las cuentas') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Vencido') }}</span><div class="stat-value accent-text">{{ Decimal::bs($resumen['vencido']) }}</div><div class="stat-foot">{{ __('Saldo con vencimiento pasado') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Por vencer') }}</span><div class="stat-value">{{ Decimal::bs($resumen['por_vencer']) }}</div><div class="stat-foot">{{ __('Incluye cuentas sin vencimiento') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Clientes con saldo') }}</span><div class="stat-value">{{ $resumen['clientes'] }}</div><div class="stat-foot">{{ __('Con al menos una cuenta abierta') }}</div></article>
    </div>

    <section class="card card-pad">
        <div class="card-head">
            <div class="heading"><span class="heading-icon"><x-icon name="wallet" /></span>{{ __('Cuentas registradas') }}</div>
            <a class="btn ghost sm" href="{{ route('creditos.exportar', array_filter(['q' => trim($buscar), 'estado' => $estado === 'todas' ? null : $estado, 'sucursal' => $sucursal, 'desde' => $desde, 'hasta' => $hasta])) }}"><x-icon name="download" size="sm" /> {{ __('Exportar CSV') }}</a>
        </div>
        <div class="tabs" role="tablist" aria-label="{{ __('Filtrar cuentas por estado') }}">
            @foreach (['todas' => 'Todas', 'pendientes' => 'Pendientes', 'parciales' => 'Parciales', 'vencidas' => 'Vencidas', 'pagadas' => 'Pagadas', 'anuladas' => 'Anuladas'] as $clave => $etiqueta)
                <button type="button" role="tab" class="tab {{ $estado === $clave ? 'active' : '' }}" aria-selected="{{ $estado === $clave ? 'true' : 'false' }}" wire:click="$set('estado', '{{ $clave }}')">{{ __($etiqueta) }}</button>
            @endforeach
        </div>
        <div class="tool-row">
            <div class="search">
                <x-icon name="search" />
                <input class="input" type="search" wire:model.live.debounce.350ms="buscar" placeholder="{{ __('Buscar cliente, CI/NIT, concepto o número') }}" aria-label="{{ __('Buscar cuentas por cobrar') }}">
            </div>
            <select class="select" wire:model.live="sucursal" aria-label="{{ __('Filtrar sucursal') }}">
                <option value="">{{ __('Todas las sucursales') }}</option>
                @foreach ($sucursales as $opcion)
                    <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                @endforeach
            </select>
            <label class="row" style="gap:6px"><span class="subtle">{{ __('Vence desde') }}</span><input class="input" type="date" wire:model.live="desde" aria-label="{{ __('Vencimiento desde') }}"></label>
            <label class="row" style="gap:6px"><span class="subtle">{{ __('hasta') }}</span><input class="input" type="date" wire:model.live="hasta" aria-label="{{ __('Vencimiento hasta') }}"></label>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Documento') }}</th><th>{{ __('Cliente') }}</th><th>{{ __('Sucursal') }}</th><th>{{ __('Vencimiento') }}</th><th>{{ __('Importe') }}</th><th>{{ __('Cobrado') }}</th><th>{{ __('Saldo') }}</th><th>{{ __('Estado') }}</th><th>{{ __('Acción') }}</th></tr></thead>
                <tbody>
                    @forelse ($cuentas as $cuenta)
                        @php
                            [$tono, $texto] = $etiquetas[CuentasPorCobrar::estado($cuenta)];
                            $codigo = ($cuenta->tipo === 'venta' ? 'V-' : 'CXC-').str_pad((string) $cuenta->numero, 5, '0', STR_PAD_LEFT);
                            $abierta = ! in_array($texto, ['Pagada', 'Anulada'], true);
                        @endphp
                        <tr wire:key="cuenta-{{ $cuenta->tipo }}-{{ $cuenta->id }}">
                            <td><b>{{ $codigo }}</b><small style="display:block;color:var(--faint);margin-top:4px">{{ $cuenta->tipo === 'venta' ? __('Venta a crédito') : $cuenta->concepto }} · {{ \Illuminate\Support\Carbon::parse($cuenta->fecha)->format('d/m/Y') }}</small></td>
                            <td>{{ $cuenta->cliente ?? __('Sin cliente') }}@if ($cuenta->anulada)<small style="display:block;color:var(--faint);margin-top:4px">{{ $cuenta->motivo_anulacion }}</small>@endif @if ($cuenta->documento_cliente)<small style="display:block;color:var(--faint);margin-top:4px">{{ __('CI/NIT :documento', ['documento' => $cuenta->documento_cliente]) }}</small>@endif</td>
                            <td>{{ $cuenta->sucursal }}</td>
                            <td>{{ $cuenta->vencimiento ? \Illuminate\Support\Carbon::parse($cuenta->vencimiento)->format('d/m/Y') : '—' }}@if ($cuenta->cuotas_pendientes > 0)<small style="display:block;color:var(--faint);margin-top:4px">{{ trans_choice(':n cuota pendiente|:n cuotas pendientes', $cuenta->cuotas_pendientes, ['n' => $cuenta->cuotas_pendientes]) }}</small>@endif</td>
                            <td>{{ Decimal::bs((string) $cuenta->importe) }}</td>
                            <td>{{ Decimal::bs((string) $cuenta->cobrado) }}</td>
                            <td><b @class(['accent-text' => $abierta])>{{ Decimal::bs((string) $cuenta->saldo) }}</b></td>
                            <td><span class="badge {{ $tono }}">{{ __($texto) }}</span></td>
                            <td><a class="btn soft sm" href="{{ $cuenta->tipo === 'venta' ? route('creditos.historial', $cuenta->id) : route('creditos.cuenta', $cuenta->id) }}">{{ $abierta ? __('Ver y cobrar') : __('Ver historial') }} <x-icon name="arrow" size="sm" /></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="9"><div class="empty">{{ __('No hay cuentas que coincidan con el filtro.') }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($cuentas->hasPages())
            <div class="table-footer">
                <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $cuentas->currentPage(), 'lastpage' => $cuentas->lastPage()]) }}</span>
                <span class="row">
                    @unless ($cuentas->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                    @if ($cuentas->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                </span>
            </div>
        @endif
    </section>
    <div class="notice" style="margin-top:18px"><x-icon name="info" /><div><b>{{ __('Regla de caja') }}</b><p>{{ __('En el reporte diario solo entra el dinero cobrado ese día. Una venta a crédito o una cuenta manual no suman como ingreso.') }}</p></div></div>

    @if ($formCuenta)
        <x-modal titulo="Nueva cuenta por cobrar" descripcion="Deuda de un cliente que no nace de una venta en MoziStock (saldo anterior, préstamo, servicio). No mueve stock ni dinero." cerrar="$set('formCuenta', false)">
            <form wire:submit="registrarCuenta">
                <div class="fields">
                    <x-campo etiqueta="Cliente" campo="cliente_id">
                        <select id="cliente_id" class="select" wire:model="cliente_id" required>
                            <option value="">{{ __('Selecciona un cliente') }}</option>
                            @foreach ($clientes as $cliente)
                                <option value="{{ $cliente->id }}">{{ $cliente->nombre }}{{ $cliente->documento ? ' · '.$cliente->documento : '' }}</option>
                            @endforeach
                        </select>
                    </x-campo>
                    <x-campo etiqueta="Sucursal" campo="sucursal_id">
                        <select id="sucursal_id" class="select" wire:model="sucursal_id">
                            @foreach ($sucursales->where('activa', true) as $opcion)
                                <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                            @endforeach
                        </select>
                    </x-campo>
                    <x-campo etiqueta="Concepto" campo="concepto" style="grid-column:1/-1">
                        <input id="concepto" class="input" type="text" maxlength="150" wire:model="concepto" placeholder="{{ __('Ej. Saldo anterior a MoziStock') }}" required>
                    </x-campo>
                    <x-campo etiqueta="Fecha de registro" campo="fecha">
                        <input id="fecha" class="input" type="date" wire:model="fecha" required>
                    </x-campo>
                    <x-campo etiqueta="Vencimiento (opcional)" campo="vencimiento">
                        <input id="vencimiento" class="input" type="date" wire:model="vencimiento">
                    </x-campo>
                    <x-campo etiqueta="Importe (Bs)" campo="importe">
                        <input id="importe" class="input" type="text" inputmode="decimal" wire:model="importe" placeholder="0,00" required>
                    </x-campo>
                    <x-campo etiqueta="Nota (opcional)" campo="nota">
                        <input id="nota" class="input" type="text" maxlength="250" wire:model="nota">
                    </x-campo>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn ghost" wire:click="$set('formCuenta', false)">{{ __('Cancelar') }}</button>
                    <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="registrarCuenta"><x-icon name="check" /> {{ __('Registrar cuenta') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
