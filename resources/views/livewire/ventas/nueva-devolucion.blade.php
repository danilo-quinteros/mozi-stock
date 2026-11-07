@use('App\Support\Decimal')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Ventas · devolución') }}</div>
            <h1>{{ __('Devolución de :codigo', ['codigo' => $venta->codigo()]) }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ $venta->cliente->nombre ?? __('Consumidor final') }} · {{ $venta->sucursal->nombre }} · {{ __('vendido :total', ['total' => Decimal::bs($venta->total)]) }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('ventas.ver', $venta) }}"><x-icon name="arrow-left" /> {{ __('Volver a la venta') }}</a>
        </div>
    </div>

    <div class="grid three" style="margin-bottom:18px">
        <article class="card stat"><span class="stat-label">{{ __('Saldo pendiente') }}</span><div class="stat-value">{{ Decimal::bs($venta->saldo_pendiente) }}</div><div class="stat-foot">{{ __('La devolución lo reduce primero') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Cobrado') }}</span><div class="stat-value">{{ Decimal::bs($venta->cobrado()) }}</div><div class="stat-foot">{{ __('Reembolsado antes :importe', ['importe' => Decimal::bs($venta->reembolsado)]) }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Ya devuelto') }}</span><div class="stat-value">{{ Decimal::bs($venta->devuelto) }}</div><div class="stat-foot">{{ __('La venta original no se modifica') }}</div></article>
    </div>

    <div class="rapid-layout">
        <section class="card rapid-main">
            <div class="rapid-title"><h2>{{ __('Productos devueltos') }}</h2><button class="btn ghost sm" type="button" wire:click="devolverTodo">{{ __('Devolver todo lo disponible') }}</button></div>
            <div class="table-wrap rapid-table-wrap">
                <table class="rapid-table">
                    <thead><tr><th>{{ __('Producto') }}</th><th>{{ __('Vendido') }}</th><th>{{ __('Ya devuelto') }}</th><th>{{ __('Cantidad a devolver') }}</th><th>{{ __('Vuelve al stock') }}</th><th>{{ __('Importe') }}</th></tr></thead>
                    <tbody>
                        @foreach ($filas as $fila)
                            @php($item = $fila['item'])
                            <tr wire:key="linea-{{ $item->id }}">
                                <td data-label="{{ __('Producto') }}"><div class="rapid-product"><span class="product-dot" aria-hidden="true"><x-icon name="box" size="sm" /></span><div><b>{{ $item->descripcion }}</b><small>{{ $item->sku }} · {{ __(':importe en la venta', ['importe' => Decimal::bs($item->total)]) }}</small></div></div></td>
                                <td data-label="{{ __('Vendido') }}">{{ Decimal::formatear($item->cantidad) }} {{ $item->unidad }}</td>
                                <td data-label="{{ __('Ya devuelto') }}">{{ Decimal::formatear($fila['devueltas']) }}</td>
                                <td data-label="{{ __('Cantidad a devolver') }}">
                                    @if (Decimal::comparar($fila['disponible'], '0') > 0)
                                        <input class="input rapid-numeric" type="text" inputmode="decimal" placeholder="0" aria-label="{{ __('Cantidad a devolver de :producto', ['producto' => $item->descripcion]) }}"
                                            wire:model.live.debounce.400ms="lineas.{{ $item->id }}.cantidad">
                                    @else
                                        <span class="subtle">{{ __('Devuelto por completo') }}</span>
                                    @endif
                                </td>
                                <td data-label="{{ __('Vuelve al stock') }}">
                                    @if (Decimal::comparar($fila['disponible'], '0') > 0)
                                        <label class="row"><input type="checkbox" wire:model.live="lineas.{{ $item->id }}.reingresa" aria-label="{{ __(':producto vuelve al stock', ['producto' => $item->descripcion]) }}"><span class="subtle">{{ __('Sí') }}</span></label>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td data-label="{{ __('Importe') }}" @class(['danger-text' => $fila['importe'] === null])>{{ $fila['importe'] === null ? __('Hasta :cantidad', ['cantidad' => Decimal::formatear($fila['disponible'])]) : Decimal::bs($fila['importe']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="rapid-message" role="status" aria-live="polite">
                @if ($errors->has('lineas'))
                    @foreach ($errors->get('lineas') as $mensaje)<p class="danger-text" style="margin:0 0 4px">{{ $mensaje }}</p>@endforeach
                @else
                    {{ __('Cada unidad vale lo que se cobró por ella (con sus descuentos). Desmarca «Vuelve al stock» si el producto está dañado.') }}
                @endif
            </div>
        </section>
        <aside class="rapid-aside">
            <section class="card card-pad summary-sticky">
                <div class="heading">{{ __('Resumen') }}</div>
                <div class="summary-count"><span>{{ __('Valor devuelto') }}</span><b>{{ Decimal::bs($importe) }}</b></div>
                <div class="summary-count"><span>{{ __('Reduce saldo del crédito') }}</span><b>{{ Decimal::bs($deuda) }}</b></div>
                <div class="summary-money"><span>{{ __('Reembolso al cliente') }}</span><b>{{ Decimal::bs($reembolso) }}</b></div>
                <div class="divider"></div>
                <x-campo etiqueta="Motivo *" campo="motivo">
                    <input id="motivo" class="input" type="text" maxlength="250" wire:model.blur="motivo" placeholder="{{ __('Ej. Producto defectuoso') }}" required>
                </x-campo>
                @if (Decimal::comparar($reembolso, '0', 2) > 0)
                    <x-campo etiqueta="Medio del reembolso" campo="metodo">
                        <select id="metodo" class="select" wire:model.live="metodo">
                            <option value="efectivo">{{ __('Efectivo') }}</option>
                            <option value="electronico">{{ __('Electrónico') }}</option>
                            <option value="mixto">{{ __('Mixto') }}</option>
                        </select>
                    </x-campo>
                    @if ($metodo === 'mixto')
                        <div class="mix-fields">
                            <x-campo etiqueta="Efectivo (Bs)" campo="efectivo">
                                <input id="efectivo" class="input" type="text" inputmode="decimal" placeholder="0,00" wire:model.live.debounce.400ms="efectivo">
                            </x-campo>
                            <x-campo etiqueta="Electrónico (Bs)" campo="electronico">
                                <input id="electronico" class="input" type="text" inputmode="decimal" placeholder="0,00" wire:model.live.debounce.400ms="electronico">
                            </x-campo>
                        </div>
                        <p @class(['subtle', 'danger-text' => Decimal::comparar($mixto, $reembolso, 2) !== 0])>{{ __('Suma :suma de :total', ['suma' => Decimal::bs($mixto), 'total' => Decimal::bs($reembolso)]) }}</p>
                    @endif
                    @include('livewire.partials.caja')
                    <p class="subtle">{{ __('El reembolso se registra hoy en :sucursal, una sola vez.', ['sucursal' => $venta->sucursal->nombre]) }}</p>
                @else
                    <p class="subtle">{{ __('No se devuelve dinero: el valor se descuenta del saldo pendiente.') }}</p>
                @endif
                <div class="rapid-final-actions">
                    <button class="btn primary" type="button" wire:click="revisar" @disabled(! $hayLineas)>{{ __('Revisar y confirmar') }} <x-icon name="arrow" size="sm" /></button>
                    <a class="btn ghost" href="{{ route('ventas.ver', $venta) }}">{{ __('Volver sin guardar') }}</a>
                </div>
            </section>
        </aside>
    </div>

    @if ($confirmando)
        <x-modal titulo="Confirmar devolución" descripcion="La venta original no se modifica. Se registra la devolución, el reingreso de stock marcado y, si corresponde, el reembolso." cerrar="$set('confirmando', false)">
            <div class="confirm-grid">
                <div><span class="subtle">{{ __('Valor devuelto') }}</span><b>{{ Decimal::bs($importe) }}</b></div>
                <div><span class="subtle">{{ __('Reduce saldo') }}</span><b>{{ Decimal::bs($deuda) }}</b></div>
                <div><span class="subtle">{{ __('Reembolso') }}</span><b>{{ Decimal::bs($reembolso) }}</b></div>
                <div><span class="subtle">{{ __('Medio') }}</span><b>{{ Decimal::comparar($reembolso, '0', 2) > 0 ? (\App\Enums\MetodoPago::tryFrom($metodo)?->etiqueta() ?? '—') : '—' }}</b></div>
                @if (Decimal::comparar($reembolso, '0', 2) > 0 && $metodo !== 'electronico')
                    <div><span class="subtle">{{ __('Caja del efectivo') }}</span><b>{{ $this->opcionesCaja[$caja] ?? '—' }}</b></div>
                @endif
            </div>
            <p class="subtle">{{ __('Motivo: :motivo', ['motivo' => $motivo]) }}</p>
            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="$set('confirmando', false)">{{ __('Volver a editar') }}</button>
                <button type="button" class="btn primary" wire:click="confirmar" wire:loading.attr="disabled" wire:target="confirmar"><x-icon name="check" /> {{ __('Confirmar devolución') }}</button>
            </div>
        </x-modal>
    @endif
</div>
