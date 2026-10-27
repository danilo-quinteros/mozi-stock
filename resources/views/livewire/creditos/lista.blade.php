@use('App\Support\Decimal')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Cuentas por cobrar') }}</div>
            <h1>{{ __('Créditos') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Saldos pendientes y abonos realmente cobrados.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('pedidos.index') }}"><x-icon name="arrow-left" /> {{ __('Pedidos y ventas') }}</a>
        </div>
    </div>

    <div class="grid three" style="margin-bottom:18px">
        <article class="card stat"><span class="stat-label">{{ __('Saldo total pendiente') }}</span><div class="stat-value">{{ Decimal::bs($resumen['saldo']) }}</div><div class="stat-foot">{{ $resumen['abiertos'] }} {{ $resumen['abiertos'] === 1 ? __('crédito abierto') : __('créditos abiertos') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Cobrado en cuotas') }}</span><div class="stat-value">{{ Decimal::bs($resumen['cobrado']) }}</div><div class="stat-foot">{{ __('Solo pagos efectivamente recibidos') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Créditos por cobrar') }}</span><div class="stat-value">{{ $resumen['abiertos'] }}</div><div class="stat-foot">{{ __('Los saldos se actualizan con cada abono') }}</div></article>
    </div>

    <section class="card card-pad">
        <div class="card-head">
            <div class="heading"><span class="heading-icon"><x-icon name="wallet" /></span>{{ __('Créditos registrados') }}</div>
            <a class="btn ghost sm" href="{{ route('creditos.exportar', array_filter(['q' => trim($buscar), 'estado' => $estado === 'todos' ? null : $estado])) }}"><x-icon name="download" size="sm" /> {{ __('Exportar CSV') }}</a>
        </div>
        <div class="tabs" role="tablist" aria-label="{{ __('Filtrar créditos por estado') }}">
            @foreach (['todos' => 'Todos', 'pagados' => 'Completados', 'pendientes' => 'Pendientes'] as $clave => $etiqueta)
                <button type="button" role="tab" class="tab {{ $estado === $clave ? 'active' : '' }}" aria-selected="{{ $estado === $clave ? 'true' : 'false' }}" wire:click="$set('estado', '{{ $clave }}')">{{ __($etiqueta) }}</button>
            @endforeach
        </div>
        <div class="tool-row">
            <div class="search">
                <x-icon name="search" />
                <input class="input" type="search" wire:model.live.debounce.350ms="buscar" placeholder="{{ __('Buscar crédito, cliente, CI/NIT o pedido') }}" aria-label="{{ __('Buscar créditos') }}">
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Pedido / venta') }}</th><th>{{ __('Cliente') }}</th><th>{{ __('Sucursal') }}</th><th>{{ __('Vendido') }}</th><th>{{ __('Cobrado') }}</th><th>{{ __('Saldo') }}</th><th>{{ __('Estado') }}</th><th>{{ __('Acción') }}</th></tr></thead>
                <tbody>
                    @forelse ($creditos as $credito)
                        @php($pendiente = Decimal::comparar((string) $credito->saldo_pendiente, '0', 2) > 0)
                        <tr wire:key="credito-{{ $credito->id }}">
                            <td><b>V-{{ str_pad((string) $credito->numero, 5, '0', STR_PAD_LEFT) }}</b><small style="display:block;color:var(--faint);margin-top:4px">PED-{{ str_pad((string) $credito->pedido_numero, 5, '0', STR_PAD_LEFT) }}</small></td>
                            <td>{{ $credito->cliente ?? __('Sin cliente') }}@if ($credito->documento)<small style="display:block;color:var(--faint);margin-top:4px">{{ __('CI/NIT :documento', ['documento' => $credito->documento]) }}</small>@endif</td>
                            <td>{{ $credito->sucursal }}</td>
                            <td>{{ Decimal::bs((string) $credito->total) }}</td>
                            <td>{{ Decimal::bs((string) $credito->cobrado) }}</td>
                            <td><b @class(['accent-text' => $pendiente])>{{ Decimal::bs((string) $credito->saldo_pendiente) }}</b></td>
                            <td><span class="badge {{ $pendiente ? 'warning' : 'success' }}">{{ $pendiente ? __('Pendiente') : __('Pagado') }}</span></td>
                            <td><span class="row" style="gap:6px;flex-wrap:nowrap"><a class="btn soft sm" href="{{ route('creditos.historial', $credito->id) }}">{{ $pendiente ? __('Ver historial y abonar') : __('Ver historial') }} <x-icon name="arrow" size="sm" /></a><a class="btn ghost sm" href="{{ route('creditos.imprimir', $credito->id) }}" target="_blank" title="{{ __('Imprimir con desglose') }}" aria-label="{{ __('Imprimir crédito V-:numero', ['numero' => str_pad((string) $credito->numero, 5, '0', STR_PAD_LEFT)]) }}"><x-icon name="print" size="sm" /></a></span></td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><div class="empty">{{ __('No hay créditos que coincidan con el filtro.') }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($creditos->hasPages())
            <div class="table-footer">
                <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $creditos->currentPage(), 'lastpage' => $creditos->lastPage()]) }}</span>
                <span class="row">
                    @unless ($creditos->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                    @if ($creditos->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                </span>
            </div>
        @endif
    </section>
    <div class="notice" style="margin-top:18px"><x-icon name="info" /><div><b>{{ __('Regla de caja') }}</b><p>{{ __('En el reporte diario solo entra el dinero cobrado ese día. Una venta a crédito no suma su total como ingreso.') }}</p></div></div>
</div>
