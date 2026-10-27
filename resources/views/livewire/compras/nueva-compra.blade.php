@use('App\Support\Decimal')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Abastecimiento') }}</div>
            <h1>{{ __('Nueva compra') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Selecciona el proveedor, añade productos y confirma la compra.') }}</p>
        </div>
    </div>

    @if ($sucursales->isEmpty())
        <div class="notice" style="margin-bottom:17px"><x-icon name="info" /><div><b>{{ __('Primero registra una sucursal') }}</b><p>{{ __('El stock comprado siempre ingresa a una sucursal.') }} <a class="link" href="{{ route('sucursales.index') }}">{{ __('Ir a Sucursales') }} <x-icon name="arrow" size="sm" /></a></p></div></div>
    @endif

    <section class="card card-pad rapid-header">
        <div class="card-head"><div class="heading">{{ __('Datos de la operación') }}</div></div>
        <div class="rapid-form-fields">
            <x-campo etiqueta="Proveedor" campo="proveedor_id">
                <select id="proveedor_id" class="select" wire:model="proveedor_id">
                    <option value="">{{ __('Selecciona proveedor (opcional)') }}</option>
                    @foreach ($proveedores as $proveedor)
                        <option value="{{ $proveedor->id }}">{{ $proveedor->nombre }}</option>
                    @endforeach
                </select>
            </x-campo>
            <x-campo etiqueta="Sucursal" campo="sucursal_id">
                <select id="sucursal_id" class="select" wire:model="sucursal_id">
                    @foreach ($sucursales as $sucursal)
                        <option value="{{ $sucursal->id }}">{{ $sucursal->nombre }}</option>
                    @endforeach
                </select>
            </x-campo>
            <x-campo etiqueta="Referencia / nota (opcional)" campo="referencia" class="rapid-note-field">
                <input id="referencia" class="input" type="text" maxlength="150" wire:model.blur="referencia" placeholder="{{ __('Ej. Factura, descripción breve') }}">
            </x-campo>
        </div>
    </section>

    <div class="rapid-layout">
        @include('livewire.inventario.partials.captura', ['sugerencias' => $this->sugerencias, 'modo' => 'compra'])
        <aside class="rapid-aside">
            <section class="card card-pad summary-sticky">
                <div class="heading">{{ __('Resumen') }}</div>
                <p class="subtle" style="margin-top:10px">{{ __('Productos comprados ·') }} <b>{{ count($filas) }}</b></p>
                <div class="summary-count"><span>{{ __('Unidades') }}</span><b>{{ Decimal::formatear($unidades) }}</b></div>
                <div class="summary-money"><span>{{ __('Total pagado') }}</span><b>{{ Decimal::bs($total) }}</b></div>
                <div class="divider"></div>
                <x-campo etiqueta="Medio de pago" campo="metodo">
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
                    <p @class(['subtle', 'danger-text' => Decimal::comparar($mixto, $total, 2) !== 0])>{{ __('Suma :mixto de :total', ['mixto' => Decimal::bs($mixto), 'total' => Decimal::bs($total)]) }}</p>
                @endif
                <p class="subtle">{{ __('Una compra pagada actualiza stock y registra una sola salida de dinero. No dupliques el egreso.') }}</p>
                <div class="rapid-final-actions">
                    <button class="btn primary" type="button" wire:click="revisar" @disabled($sucursales->isEmpty())>{{ __('Revisar y confirmar') }} <x-icon name="arrow" size="sm" /></button>
                    <a class="btn ghost" href="{{ route('compras.index') }}">{{ __('Volver sin guardar') }}</a>
                </div>
            </section>
        </aside>
    </div>

    @if ($confirmando)
        <x-modal titulo="Confirmar compra" descripcion="Al confirmar se ingresa el stock en la sucursal y se registra el pago en caja, en una sola operación." cerrar="$set('confirmando', false)">
            <div class="confirm-grid">
                <div><span class="subtle">{{ __('Sucursal') }}</span><b>{{ $sucursales->firstWhere('id', (int) $sucursal_id)?->nombre }}</b></div>
                <div><span class="subtle">{{ __('Proveedor') }}</span><b>{{ $proveedores->firstWhere('id', (int) $proveedor_id)?->nombre ?? __('Sin proveedor') }}</b></div>
                <div><span class="subtle">{{ __('Productos') }}</span><b>{{ count($filas) }}</b></div>
                <div><span class="subtle">{{ __('Total pagado') }}</span><b>{{ Decimal::bs($total) }}</b></div>
            </div>
            <div class="table-wrap" style="max-height:260px">
                <table>
                    <thead><tr><th>{{ __('Producto') }}</th><th>{{ __('Cantidad') }}</th><th>{{ __('Costo') }}</th><th>{{ __('Subtotal') }}</th></tr></thead>
                    <tbody>
                        @foreach ($filas as $fila)
                            <tr wire:key="confirmar-{{ $fila['producto']->id }}">
                                <td>{{ $fila['producto']->nombre }}</td>
                                <td>{{ $fila['cantidad'] === null ? '—' : Decimal::formatear($fila['cantidad']) }}</td>
                                <td>{{ $fila['costo'] === null ? '—' : Decimal::bs($fila['costo']) }}</td>
                                <td>{{ $fila['subtotal'] === null ? '—' : Decimal::bs($fila['subtotal']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @unless ($valido)
                <div class="notice" role="alert"><x-icon name="info" /><div><b>{{ __('Revisa la tabla') }}</b><p>{{ __('Hay productos con cantidad o costo inválido; el servidor rechazará la compra.') }}</p></div></div>
            @endunless
            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="$set('confirmando', false)">{{ __('Volver a editar') }}</button>
                <button type="button" class="btn primary" wire:click="confirmar" wire:loading.attr="disabled" wire:target="confirmar"><x-icon name="check" /> {{ __('Confirmar compra') }}</button>
            </div>
        </x-modal>
    @endif
</div>
