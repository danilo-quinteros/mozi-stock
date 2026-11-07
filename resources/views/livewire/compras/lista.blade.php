@use('App\Support\Decimal')
@use('App\Enums\MetodoPago')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Abastecimiento') }}</div>
            <h1>{{ __('Compras') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Registra compras pagadas y entradas de productos.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn primary" href="{{ route('compras.crear') }}"><x-icon name="plus" /> {{ __('Nueva compra') }}</a>
        </div>
    </div>

    <div class="grid three" style="margin-bottom:18px">
        <article class="card stat"><span class="stat-label">{{ __('Compras del mes') }}</span><div class="stat-value">{{ Decimal::bs($mes['total']) }}</div><div class="stat-foot">{{ __('Compras pagadas') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Compras registradas') }}</span><div class="stat-value">{{ $registradas }}</div><div class="stat-foot">{{ __('Entradas de inventario') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Proveedores activos') }}</span><div class="stat-value">{{ $proveedoresActivos }}</div><div class="stat-foot"><a class="link" href="{{ route('proveedores.index') }}">{{ __('Datos por empresa') }} <x-icon name="arrow" size="sm" /></a></div></article>
    </div>

    <section class="card card-pad">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="store" /></span>{{ __('Historial de compras') }}</div></div>
        <div class="tool-row">
            <div class="search">
                <x-icon name="search" />
                <input class="input" type="search" wire:model.live.debounce.350ms="buscar" placeholder="{{ __('Buscar proveedor, producto...') }}" aria-label="{{ __('Buscar compras') }}">
            </div>
            <select class="select" wire:model.live="sucursal" @disabled($sucursalFija) aria-label="{{ __('Filtrar sucursal') }}">
                <option value="">{{ __('Todas las sucursales') }}</option>
                @foreach ($sucursales as $opcion)
                    <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                @endforeach
            </select>
            <a class="btn ghost sm" href="{{ route('compras.exportar', array_filter(['q' => trim($buscar), 'sucursal' => $sucursal])) }}"><x-icon name="download" size="sm" /> {{ __('Exportar CSV') }}</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Compra') }}</th><th>{{ __('Fecha') }}</th><th>{{ __('Proveedor') }}</th><th>{{ __('Sucursal') }}</th><th>{{ __('Artículos') }}</th><th>{{ __('Total pagado') }}</th><th>{{ __('Estado') }}</th><th>{{ __('Acción') }}</th></tr></thead>
                <tbody>
                    @forelse ($compras as $compra)
                        <tr wire:key="compra-{{ $compra->id }}">
                            <td><b>COM-{{ str_pad((string) $compra->numero, 5, '0', STR_PAD_LEFT) }}</b></td>
                            <td>{{ \Illuminate\Support\Carbon::parse($compra->fecha, 'UTC')->timezone(config('mozistock.business_timezone'))->format('d/m/Y') }}</td>
                            <td>{{ $compra->proveedor ?? __('Sin proveedor') }}</td>
                            <td>{{ $compra->sucursal }}</td>
                            <td>{{ $compra->articulos }}</td>
                            <td>{{ Decimal::bs((string) $compra->total) }}<small style="display:block;color:var(--faint);margin-top:4px">{{ MetodoPago::tryFrom((string) $compra->metodo)?->etiqueta() ?? __('A crédito') }}</small></td>
                            <td><span class="badge success">{{ __('Pagada') }}</span></td>
                            <td><a class="btn sm ghost" href="{{ route('compras.ver', $compra->id) }}">{{ __('Ver') }} <x-icon name="arrow" size="sm" /></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><div class="empty">{{ trim($buscar) === '' && $sucursal === '' ? __('Aún no registras compras.') : __('No hay compras que coincidan con el filtro.') }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($compras->hasPages())
            <div class="table-footer">
                <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $compras->currentPage(), 'lastpage' => $compras->lastPage()]) }}</span>
                <span class="row">
                    @unless ($compras->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                    @if ($compras->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                </span>
            </div>
        @endif
        <div class="divider"></div>
        <div class="notice"><x-icon name="info" /><div><b>{{ __('Una compra, una salida de dinero') }}</b><p>{{ __('Confirmar una compra ingresa el stock y registra el pago en caja una sola vez. No la registres otra vez en Egresos ni como ajuste.') }}</p></div></div>
    </section>

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
