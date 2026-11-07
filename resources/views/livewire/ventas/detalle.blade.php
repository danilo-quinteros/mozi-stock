@use('App\Support\Decimal')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Venta confirmada') }}</div>
            <h1>{{ __('Venta :codigo', ['codigo' => $venta->codigo()]) }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Del pedido :codigo', ['codigo' => $venta->pedido->codigo()]) }} · {{ $venta->fecha->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i') }} · {{ $venta->sucursal->nombre }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('pedidos.index', ['pestana' => 'ventas']) }}"><x-icon name="arrow-left" /> {{ __('Pedidos y ventas') }}</a>
            <a class="btn ghost" href="{{ route('pedidos.imprimir', $venta->pedido_id) }}" target="_blank"><x-icon name="print" /> {{ __('Imprimir') }}</a>
            @if ($puedeDevolver)
                <a class="btn soft" href="{{ route('ventas.devolucion', $venta) }}">{{ __('Registrar devolución') }}</a>
            @endif
        </div>
    </div>

    <div class="grid four" style="margin-bottom:18px">
        <article class="card stat"><span class="stat-label">{{ __('Valor vendido') }}</span><div class="stat-value">{{ Decimal::bs($venta->total) }}</div><div class="stat-foot">{{ __('Actividad comercial, no ingreso') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Cobrado') }}</span><div class="stat-value">{{ Decimal::bs($venta->cobrado()) }}</div><div class="stat-foot">{{ __('Dinero efectivamente recibido') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Saldo pendiente') }}</span><div class="stat-value">{{ Decimal::bs($venta->saldo_pendiente) }}</div><div class="stat-foot">{{ Decimal::comparar($venta->saldo_pendiente, '0', 2) > 0 ? __('Venta a crédito') : __('Sin saldo') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Cliente') }}</span><div class="stat-value" style="font-size:clamp(17px,1.8vw,22px)">{{ $venta->cliente->nombre ?? __('Consumidor final') }}</div><div class="stat-foot">{{ $venta->cliente?->documento ? __('CI/NIT :documento', ['documento' => $venta->cliente->documento]) : __('Vendedor: :nombre', ['nombre' => $venta->vendedor->name]) }}</div></article>
    </div>

    <div class="wide-left">
        <section class="card card-pad">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="store" /></span>{{ __('Detalle de la venta') }}</div><span class="subtle">{{ __('Vendedor: :nombre', ['nombre' => $venta->vendedor->name]) }}</span></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Producto') }}</th><th>{{ __('Cant.') }}</th><th>{{ __('Lista') }}</th><th>{{ __('Precio / u.') }}</th><th>{{ __('Descuento') }}</th><th>{{ __('Subtotal') }}</th></tr></thead>
                    <tbody>
                        @foreach ($venta->items as $item)
                            <tr wire:key="item-{{ $item->id }}">
                                <td><b>{{ $item->descripcion }}</b><small style="display:block;color:var(--faint);margin-top:4px">{{ $item->sku }}</small></td>
                                <td>{{ Decimal::formatear($item->cantidad) }} {{ $item->unidad }}</td>
                                <td>{{ $item->tipo_precio->etiqueta() }}</td>
                                <td>{{ Decimal::bs($item->precio_unitario) }}</td>
                                <td>{{ Decimal::comparar($item->descuento, '0', 2) > 0 ? '− '.Decimal::bs($item->descuento).($item->descuento_tipo === 'pct' ? ' ('.Decimal::formatear($item->descuento_valor).' %)' : '') : '—' }}</td>
                                <td><b>{{ Decimal::bs($item->total) }}</b></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="divider"></div>
            <div class="list-line"><span class="subtle">{{ __('Subtotal productos') }}</span><b>{{ Decimal::bs($venta->subtotal) }}</b></div>
            <div class="list-line"><span class="subtle">{{ __('Descuentos por producto') }}</span><b>− {{ Decimal::bs($venta->descuento_lineas) }}</b></div>
            <div class="list-line"><span class="subtle">{{ __('Descuento global') }}</span><b>− {{ Decimal::bs($venta->descuento_global) }}</b></div>
            <div class="list-line"><span>{{ __('Total vendido') }}</span><b class="accent-text" style="font-size:17px">{{ Decimal::bs($venta->total) }}</b></div>
            @if (Decimal::comparar($venta->devuelto, '0', 2) > 0)
                <div class="list-line"><span class="subtle">{{ __('Devuelto (sin reescribir la venta)') }}</span><b class="danger-text">− {{ Decimal::bs($venta->devuelto) }}</b></div>
            @endif
            @if ($venta->pedido->nota)<p class="subtle" style="margin-top:12px">{{ __('Nota: :nota', ['nota' => $venta->pedido->nota]) }}</p>@endif
        </section>

        <section class="card card-pad">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="lock" /></span>{{ __('Cobros recibidos') }}</div></div>
            @forelse ($pagos as $indice => $pago)
                <div class="list-line" wire:key="pago-{{ $indice }}">
                    <div>
                        <b @if ($pago['anulado']) style="text-decoration:line-through" @endif>{{ Decimal::bs($pago['monto']) }}</b> · {{ $pago['metodo'] }}@if ($pago['anulado']) <span class="badge">{{ __('Anulado') }}</span>@endif
                        <p class="subtle" style="margin:5px 0 0">
                            {{ $pago['concepto'] }} · {{ $pago['fecha']->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i') }} · {{ $pago['receptor'] }}
                            @if ($pago['mixto'])<br>{{ __('Efectivo :efectivo · Electrónico :electronico', ['efectivo' => Decimal::bs($pago['efectivo']), 'electronico' => Decimal::bs($pago['electronico'])]) }}@endif
                        </p>
                    </div>
                </div>
            @empty
                <div class="empty">{{ __('Sin cobros todavía (cuota inicial Bs 0,00).') }}</div>
            @endforelse
            <div class="divider"></div>
            @if (Decimal::comparar($venta->saldo_pendiente, '0', 2) > 0)
                <div class="notice"><x-icon name="info" /><div><b>{{ __('Saldo :saldo', ['saldo' => Decimal::bs($venta->saldo_pendiente)]) }}</b><p>{{ __('Los cobros se registran en el historial de pagos con su fecha real.') }}</p><a class="btn soft sm" style="margin-top:10px" href="{{ route('creditos.historial', $venta) }}">{{ __('Historial de pagos y cobrar') }} <x-icon name="arrow" size="sm" /></a></div></div>
            @else
                <div class="notice"><x-icon name="check" /><div><b>{{ __('Sin saldo pendiente') }}</b><p>{{ Decimal::comparar($venta->devuelto, '0', 2) > 0 ? __('Los cobros y las devoluciones cubren el total de la venta.') : __('La venta está pagada por completo.') }}</p></div></div>
            @endif
        </section>
    </div>

    <livewire:ventas.factura :venta-id="$venta->id" />

    @if ($venta->devoluciones->isNotEmpty())
        <section class="card card-pad" style="margin-top:18px">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="arrow" /></span>{{ __('Devoluciones') }}</div><span class="subtle">{{ __('Primero reducen el saldo; el resto se reembolsa') }}</span></div>
            @foreach ($venta->devoluciones as $devolucion)
                <div class="list-line" wire:key="devolucion-{{ $devolucion->id }}">
                    <div>
                        <b>{{ $devolucion->codigo() }} · {{ Decimal::bs($devolucion->importe) }}</b>
                        <p class="subtle" style="margin:5px 0 0">
                            {{ $devolucion->fecha->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i') }} · {{ $devolucion->usuario->name }} · {{ $devolucion->motivo }}<br>
                            @foreach ($devolucion->items as $item){{ $loop->first ? '' : ' · ' }}{{ $item->ventaItem->descripcion }} × {{ Decimal::formatear($item->cantidad) }}{{ $item->reingresa_stock ? '' : ' ('.__('no vuelve al stock').')' }}@endforeach
                        </p>
                    </div>
                    <div style="text-align:right">
                        @if (Decimal::comparar($devolucion->deuda_reducida, '0', 2) > 0)<p class="subtle" style="margin:0">{{ __('Saldo') }} − {{ Decimal::bs($devolucion->deuda_reducida) }}</p>@endif
                        @if ($devolucion->reembolso_metodo)<p class="danger-text" style="margin:4px 0 0">{{ __('Reembolso :importe · :metodo', ['importe' => Decimal::bs($devolucion->reembolso), 'metodo' => $devolucion->reembolso_metodo->etiqueta()]) }}</p>@endif
                    </div>
                </div>
            @endforeach
        </section>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
