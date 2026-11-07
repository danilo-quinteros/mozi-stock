@use('App\Support\Decimal')
@use('App\Models\CuentaPagar')
@use('App\Domain\CuentasPagar\Consultas\CuentasPorPagar')
@php($etiquetas = ['anulada' => ['', 'Anulada'], 'pagada' => ['success', 'Pagada'], 'vencida' => ['danger', 'Vencida'], 'parcial' => ['warning', 'Parcial'], 'pendiente' => ['info', 'Pendiente']])
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Ventas y finanzas') }}</div>
            <h1>{{ __('Cuentas por pagar') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Deudas con proveedores y acreedores: compras y egresos con saldo y obligaciones manuales.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn primary" href="{{ route('cuentas-pagar.crear') }}"><x-icon name="plus" /> {{ __('Nueva obligación') }}</a>
        </div>
    </div>

    <div class="grid four" style="margin-bottom:18px">
        <article class="card stat"><span class="stat-label">{{ __('Total por pagar') }}</span><div class="stat-value">{{ Decimal::bs($resumen['pendiente']) }}</div><div class="stat-foot">{{ __('Saldo pendiente de todas las cuentas') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Vencido') }}</span><div class="stat-value accent-text">{{ Decimal::bs($resumen['vencido']) }}</div><div class="stat-foot">{{ __('Saldo con vencimiento pasado') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Por vencer') }}</span><div class="stat-value">{{ Decimal::bs($resumen['por_vencer']) }}</div><div class="stat-foot">{{ __('Vence hoy o más adelante') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Cuentas abiertas') }}</span><div class="stat-value">{{ $resumen['abiertas'] }}</div><div class="stat-foot">{{ __('Con saldo pendiente') }}</div></article>
    </div>

    <section class="card card-pad">
        <div class="card-head">
            <div class="heading"><span class="heading-icon"><x-icon name="receipt" /></span>{{ __('Obligaciones') }}</div>
            <a class="btn ghost sm" href="{{ route('cuentas-pagar.exportar', array_filter(['q' => trim($buscar), 'estado' => $estado === 'todas' ? null : $estado, 'sucursal' => $sucursal, 'proveedor' => $proveedor, 'origen' => $origen, 'desde' => $desde, 'hasta' => $hasta])) }}"><x-icon name="download" size="sm" /> {{ __('Exportar CSV') }}</a>
        </div>
        <div class="tabs" role="tablist" aria-label="{{ __('Filtrar cuentas por estado') }}">
            @foreach (['todas' => 'Todas', 'pendientes' => 'Pendientes', 'parciales' => 'Parciales', 'vencidas' => 'Vencidas', 'pagadas' => 'Pagadas', 'anuladas' => 'Anuladas'] as $clave => $etiqueta)
                <button type="button" role="tab" class="tab {{ $estado === $clave ? 'active' : '' }}" aria-selected="{{ $estado === $clave ? 'true' : 'false' }}" wire:click="$set('estado', '{{ $clave }}')">{{ __($etiqueta) }}</button>
            @endforeach
        </div>
        <div class="tool-row">
            <div class="search">
                <x-icon name="search" />
                <input class="input" type="search" wire:model.live.debounce.350ms="buscar" placeholder="{{ __('Buscar acreedor, concepto o número') }}" aria-label="{{ __('Buscar cuentas por pagar') }}">
            </div>
            <select class="select" wire:model.live="proveedor" aria-label="{{ __('Filtrar acreedor') }}">
                <option value="">{{ __('Todos los acreedores') }}</option>
                @foreach ($proveedores as $opcion)
                    <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                @endforeach
            </select>
            <select class="select" wire:model.live="origen" aria-label="{{ __('Filtrar origen') }}">
                <option value="">{{ __('Todo origen') }}</option>
                <option value="compra">{{ __('Compras') }}</option>
                <option value="egreso">{{ __('Egresos') }}</option>
                <option value="manual">{{ __('Manuales') }}</option>
            </select>
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
                <thead><tr><th>{{ __('Cuenta') }}</th><th>{{ __('Acreedor') }}</th><th>{{ __('Origen') }}</th><th>{{ __('Vencimiento') }}</th><th>{{ __('Importe') }}</th><th>{{ __('Pagado') }}</th><th>{{ __('Saldo') }}</th><th>{{ __('Estado') }}</th><th>{{ __('Acción') }}</th></tr></thead>
                <tbody>
                    @forelse ($cuentas as $cuenta)
                        @php([$tono, $texto] = $etiquetas[$cuenta->anulado_at !== null ? 'anulada' : CuentaPagar::estadoDe((string) $cuenta->saldo_pendiente, (string) $cuenta->pagado, $cuenta->vencimiento)])
                        <tr wire:key="cxp-{{ $cuenta->id }}">
                            <td><b>CXP-{{ str_pad((string) $cuenta->numero, 5, '0', STR_PAD_LEFT) }}</b><small style="display:block;color:var(--faint);margin-top:4px">{{ $cuenta->concepto }}</small></td>
                            <td>{{ $cuenta->proveedor }}<small style="display:block;color:var(--faint);margin-top:4px">{{ $cuenta->sucursal }}</small></td>
                            <td>{{ CuentasPorPagar::documento($cuenta) }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($cuenta->vencimiento)->format('d/m/Y') }}</td>
                            <td>{{ Decimal::bs((string) $cuenta->total_documento) }}</td>
                            <td>{{ Decimal::bs((string) $cuenta->pagado) }}</td>
                            <td><b @class(['accent-text' => ! in_array($texto, ['Pagada', 'Anulada'], true)])>{{ Decimal::bs((string) $cuenta->saldo_pendiente) }}</b></td>
                            <td><span class="badge {{ $tono }}">{{ __($texto) }}</span></td>
                            <td><a class="btn soft sm" href="{{ route('cuentas-pagar.ver', $cuenta->id) }}">{{ in_array($texto, ['Pagada', 'Anulada'], true) ? __('Ver historial') : __('Ver y pagar') }} <x-icon name="arrow" size="sm" /></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="9"><div class="empty">{{ __('No hay cuentas por pagar que coincidan con el filtro.') }}</div></td></tr>
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
    <div class="notice" style="margin-top:18px"><x-icon name="info" /><div><b>{{ __('Una deuda no es una salida de dinero') }}</b><p>{{ __('Caja y reportes cuentan solo lo pagado, en la fecha real de cada pago. Pagar una cuenta no crea otro egreso ni otra compra.') }}</p></div></div>
</div>
