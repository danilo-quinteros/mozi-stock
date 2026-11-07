@use('App\Support\Decimal')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ ['contado' => __('Compra al contado'), 'credito' => __('Compra a crédito'), 'parcial' => __('Compra con pago parcial')][$compra->condicion] ?? __('Compra') }}</div>
            <h1>{{ __('Compra :codigo', ['codigo' => $compra->codigo()]) }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __(':format · :nombre · Registró :name', ['format' => $compra->fecha->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i'), 'nombre' => $compra->sucursal->nombre, 'name' => $compra->usuario->name]) }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('compras.index') }}"><x-icon name="arrow-left" /> {{ __('Compras') }}</a>
            <a class="btn soft" href="{{ route('compras.imprimir', $compra) }}" target="_blank"><x-icon name="print" /> {{ __('Imprimir') }}</a>
        </div>
    </div>

    <div class="grid three" style="margin-bottom:18px">
        <article class="card stat"><span class="stat-label">{{ __('Total de la compra') }}</span><div class="stat-value">{{ Decimal::bs($compra->total) }}</div><div class="stat-foot">{{ __('Pagado al registrar: :pagado', ['pagado' => Decimal::bs($compra->pagado)]) }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Medio de pago') }}</span><div class="stat-value" style="font-size:clamp(17px,1.8vw,22px)">{{ $compra->metodo?->etiqueta() ?? __('Sin pago al registrar') }}</div><div class="stat-foot">{{ __('Efectivo :monto_efectivo · Electrónico :monto_electronico', ['monto_efectivo' => Decimal::bs($compra->monto_efectivo), 'monto_electronico' => Decimal::bs($compra->monto_electronico)]) }}@if ($compra->pago_externo) · {{ __('fuera de caja') }}@endif</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Proveedor') }}</span><div class="stat-value" style="font-size:clamp(17px,1.8vw,22px)">{{ $compra->proveedor->nombre ?? __('Sin proveedor') }}</div><div class="stat-foot">{{ $compra->proveedor?->documento ? 'NIT/CI '.$compra->proveedor->documento : ($compra->referencia ?? __('Sin referencia')) }}</div></article>
    </div>

    <section class="card card-pad">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="store" /></span>{{ __('Productos comprados') }}</div><span class="subtle">{{ __('Entrada de stock :codigo', ['codigo' => $compra->operacion->codigo()]) }}</span></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Producto') }}</th><th>{{ __('Cantidad') }}</th><th>{{ __('Costo unitario') }}</th><th>{{ __('Subtotal') }}</th></tr></thead>
                <tbody>
                    @foreach ($compra->items as $item)
                        <tr wire:key="item-{{ $item->id }}">
                            <td><b>{{ $item->descripcion }}</b><small style="display:block;color:var(--faint);margin-top:4px">{{ $item->sku }}</small></td>
                            <td>{{ Decimal::formatear($item->cantidad) }} {{ $item->unidad }}</td>
                            <td>{{ Decimal::bs($item->costo_unitario) }}</td>
                            <td><b>{{ Decimal::bs($item->subtotal) }}</b></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="divider"></div>
        <div class="list-line"><span>{{ __('Total') }}</span><b class="accent-text" style="font-size:17px">{{ Decimal::bs($compra->total) }}</b></div>
        @if ($compra->cuentaPagar)
            <div class="list-line"><span>{{ __('Cuenta por pagar') }}</span><b><a class="link" href="{{ route('cuentas-pagar.ver', $compra->cuentaPagar) }}">{{ $compra->cuentaPagar->codigo() }} · {{ __('saldo :saldo · vence :fecha', ['saldo' => Decimal::bs($compra->cuentaPagar->saldo_pendiente), 'fecha' => $compra->cuentaPagar->vencimiento->format('d/m/Y')]) }}</a></b></div>
        @endif
        @if ($compra->referencia)<p class="subtle" style="margin-top:12px">{{ __('Referencia: :referencia', ['referencia' => $compra->referencia]) }}</p>@endif
        <div class="divider"></div>
        <div class="notice"><x-icon name="lock" /><div><b>{{ __('Compra inmutable') }}</b><p>{{ __('Para devolver mercadería al proveedor registra una salida en Ajustes con el motivo «Devolución a proveedor».') }}</p></div></div>
    </section>

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
