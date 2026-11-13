@use('App\Support\Decimal')
@use('App\Enums\EstadoPedido')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Gestión comercial') }}</div>
            <h1>{{ __('Pedidos') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Prepara cotizaciones y conviértelas en ventas.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn soft" href="{{ route('pos') }}"><x-icon name="pos" /> {{ __('Venta rápida') }}</a>
            <a class="btn primary" href="{{ route('pedidos.crear') }}"><x-icon name="plus" /> {{ __('Nuevo pedido') }}</a>
        </div>
    </div>

    <div class="grid three" style="margin-bottom:18px">
        <article class="card stat"><span class="stat-label">{{ __('Cotizaciones pendientes') }}</span><div class="stat-value">{{ (int) $conteo?->pendientes }}</div><div class="stat-foot">{{ __('No afectan inventario') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Convertidos en venta') }}</span><div class="stat-value">{{ (int) $conteo?->vendidos }}</div><div class="stat-foot">{{ __('Confirmados') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Cancelados') }}</span><div class="stat-value">{{ (int) $conteo?->cancelados }}</div><div class="stat-foot">{{ __('Sin movimientos de caja') }}</div></article>
    </div>

    <section class="card card-pad">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="store" /></span>{{ __('Lista de pedidos') }}</div></div>
        <div class="tabs" role="tablist">
            @foreach (['todos' => __('Todos'), 'cotizaciones' => __('Cotizaciones'), 'ventas' => __('Ventas'), 'credito' => __('A crédito')] + ($despachos ? ['despachos' => __('Despachos')] : []) as $clave => $etiqueta)
                <button type="button" role="tab" class="tab {{ $pestana === $clave ? 'active' : '' }}" aria-selected="{{ $pestana === $clave ? 'true' : 'false' }}" wire:click="$set('pestana', '{{ $clave }}')">{{ $etiqueta }}</button>
            @endforeach
        </div>
        @if ($pedidos === null)
            <livewire:pedidos.despachos wire:key="pestana-despachos" />
        @else
        <div class="tool-row">
            <div class="search">
                <x-icon name="search" />
                <input class="input" type="search" wire:model.live.debounce.350ms="buscar" placeholder="{{ __('Buscar número, cliente o CI/NIT...') }}" aria-label="{{ __('Buscar pedidos') }}">
            </div>
            @if ($pestana === 'todos')
                <select class="select" wire:model.live="estado" aria-label="{{ __('Filtrar por estado') }}">
                    <option value="">{{ __('Todos los estados') }}</option>
                    @foreach (EstadoPedido::cases() as $opcion)
                        <option value="{{ $opcion->value }}">{{ $opcion->etiqueta() }}</option>
                    @endforeach
                </select>
            @endif
            <a class="btn ghost sm" href="{{ route('pedidos.exportar', array_filter(['pestana' => $pestana === 'todos' ? null : $pestana, 'q' => trim($buscar), 'estado' => $estado])) }}"><x-icon name="download" size="sm" /> {{ __('Exportar CSV') }}</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Número') }}</th><th>{{ __('Fecha') }}</th><th>{{ __('Cliente') }}</th><th>{{ __('Vendedor') }}</th><th>{{ __('Total') }}</th><th>{{ __('Estado') }}</th><th>{{ __('Pago / crédito') }}</th><th>{{ __('Acción') }}</th></tr></thead>
                <tbody>
                    @forelse ($pedidos as $pedido)
                        @php($venta = $pedido->venta)
                        <tr wire:key="pedido-{{ $pedido->id }}">
                            <td><b>{{ $venta?->codigo() ?? $pedido->codigo() }}</b>@if ($venta)<small style="display:block;color:var(--faint);margin-top:4px">{{ $pedido->codigo() }}</small>@endif</td>
                            <td>{{ $pedido->created_at?->timezone(config('mozistock.business_timezone'))->format('d/m/Y') }}</td>
                            <td>{{ $pedido->cliente->nombre ?? __('Consumidor final') }}</td>
                            <td>{{ $pedido->vendedor->name }}</td>
                            <td>{{ Decimal::bs($pedido->total) }}</td>
                            <td><span class="badge {{ $pedido->estado->insignia() }}">{{ $pedido->estado->etiqueta() }}</span></td>
                            <td>
                                @if ($venta && Decimal::comparar($venta->saldo_pendiente, '0', 2) > 0)
                                    <div class="credit-inline"><span class="badge warning">{{ __('Crédito · Saldo :saldo', ['saldo' => Decimal::bs($venta->saldo_pendiente)]) }}</span><a class="link" href="{{ route('creditos.historial', $venta) }}">{{ __('Historial de pagos') }} <x-icon name="arrow" size="sm" /></a></div>
                                @elseif ($venta)
                                    <div class="credit-inline"><span class="badge success">{{ __('Pagado') }}</span><span class="subtle">{{ __('Sin saldo') }}</span></div>
                                @else
                                    <span class="subtle">{{ __('Sin cobro · cotización') }}</span>
                                @endif
                            </td>
                            <td><span class="row" style="gap:6px;flex-wrap:nowrap"><a class="btn ghost sm" href="{{ $venta ? route('ventas.ver', $venta) : route('pedidos.editar', $pedido) }}">{{ __('Abrir') }} <x-icon name="arrow" size="sm" /></a><a class="btn ghost sm" href="{{ route('pedidos.imprimir', $pedido) }}" target="_blank" title="{{ __('Imprimir') }}" aria-label="{{ __('Imprimir :codigo', ['codigo' => $venta?->codigo() ?? $pedido->codigo()]) }}"><x-icon name="print" size="sm" /></a>@if ($venta && isset($facturas[$venta->id]))<a class="btn ghost sm" href="{{ route('facturas.imprimir', $facturas[$venta->id]) }}" target="_blank" title="{{ __('Imprimir factura') }}" aria-label="{{ __('Imprimir factura de :codigo', ['codigo' => $venta->codigo()]) }}" data-test="imprimir-factura"><x-icon name="receipt" size="sm" /></a>@endif</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><div class="empty">{{ __('No hay pedidos que coincidan con el filtro.') }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($pedidos->hasPages())
            <div class="table-footer">
                <span>{{ __('Página :actual de :total', ['actual' => $pedidos->currentPage(), 'total' => $pedidos->lastPage()]) }}</span>
                <span class="row">
                    @unless ($pedidos->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                    @if ($pedidos->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                </span>
            </div>
        @endif
        <div class="divider"></div>
        <div class="notice"><x-icon name="info" /><div><b>{{ __('Una cotización no es una venta') }}</b><p>{{ __('Crear o guardar cotizaciones no descuenta inventario ni registra cobros. Solo «Confirmar venta» lo hace, y en ventas a crédito solo cuenta lo efectivamente pagado.') }}</p></div></div>
        @endif
    </section>

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
