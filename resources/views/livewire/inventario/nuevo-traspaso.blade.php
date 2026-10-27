@use('App\Support\Decimal')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Entre sucursales') }}</div>
            <h1>{{ __('Traspaso rápido') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Mueve productos entre sucursales en una sola operación.') }}</p>
        </div>
    </div>

    @if ($sucursales->count() < 2)
        <div class="notice" style="margin-bottom:17px"><x-icon name="info" /><div><b>{{ __('Necesitas dos sucursales activas') }}</b><p>{{ __('Un traspaso mueve stock de una sucursal a otra.') }} <a class="link" href="{{ route('sucursales.index') }}">{{ __('Ir a Sucursales') }} <x-icon name="arrow" size="sm" /></a></p></div></div>
    @endif

    <section class="card card-pad rapid-header">
        <div class="card-head"><div class="heading">{{ __('Datos de la operación') }}</div></div>
        <div class="rapid-form-fields">
            <x-campo etiqueta="Sucursal origen" campo="sucursal_id">
                <select id="sucursal_id" class="select" wire:model.live="sucursal_id">
                    @foreach ($sucursales as $sucursal)
                        <option value="{{ $sucursal->id }}">{{ $sucursal->nombre }}</option>
                    @endforeach
                </select>
            </x-campo>
            <x-campo etiqueta="Sucursal destino" campo="sucursal_destino_id">
                <select id="sucursal_destino_id" class="select" wire:model.live="sucursal_destino_id">
                    <option value="">{{ __('Selecciona el destino') }}</option>
                    @foreach ($sucursales as $sucursal)
                        <option value="{{ $sucursal->id }}">{{ $sucursal->nombre }}</option>
                    @endforeach
                </select>
            </x-campo>
            <x-campo etiqueta="Referencia / nota (opcional)" campo="nota" class="rapid-note-field">
                <input id="nota" class="input" type="text" maxlength="250" wire:model.blur="nota" placeholder="{{ __('Ej. Reposición de sucursal') }}">
            </x-campo>
        </div>
    </section>

    <div class="rapid-layout">
        @include('livewire.inventario.partials.captura', [
            'sugerencias' => $this->sugerencias,
            'etiquetaStock' => 'Disponible en origen',
            'etiquetaDespues' => 'Origen después',
        ])
        <aside class="rapid-aside">
            <section class="card card-pad summary-sticky">
                <div class="heading">{{ __('Resumen') }}</div>
                <p class="subtle" style="margin-top:10px">{{ __('Productos a trasladar ·') }} <b>{{ count($filas) }}</b></p>
                <div class="summary-count"><span>{{ __('Unidades') }}</span><b>{{ Decimal::formatear($unidades) }}</b></div>
                <div class="summary-count"><span>{{ __('Diferencia neta') }}</span><b>0</b></div>
                <div class="divider"></div>
                <div class="notice mini-note">{{ __('Sale del origen y entra al destino en una sola operación. No es una venta ni un movimiento de caja.') }}</div>
                <div class="rapid-final-actions">
                    <button class="btn primary" type="button" wire:click="revisar" @disabled($sucursales->count() < 2)>{{ __('Revisar y confirmar') }} <x-icon name="arrow" size="sm" /></button>
                    <a class="btn ghost" href="{{ route('inventario.movimientos') }}">{{ __('Volver sin guardar') }}</a>
                </div>
            </section>
        </aside>
    </div>

    @if ($confirmando)
        <x-modal titulo="Confirmar traspaso" descripcion="Al confirmar se resta en origen y se suma en destino en una sola operación." cerrar="$set('confirmando', false)">
            <div class="confirm-grid">
                <div><span class="subtle">{{ __('Operación') }}</span><b>{{ __('Traspaso') }}</b></div>
                <div><span class="subtle">{{ __('Sucursales') }}</span><b>{{ $sucursales->firstWhere('id', (int) $sucursal_id)?->nombre }} → {{ $sucursales->firstWhere('id', (int) $sucursal_destino_id)?->nombre }}</b></div>
                <div><span class="subtle">{{ __('Productos') }}</span><b>{{ count($filas) }}</b></div>
                <div><span class="subtle">{{ __('Unidades') }}</span><b>{{ Decimal::formatear($unidades) }}</b></div>
            </div>
            <div class="table-wrap" style="max-height:260px">
                <table>
                    <thead><tr><th>{{ __('Producto') }}</th><th>{{ __('Cantidad') }}</th><th>{{ __('Origen después') }}</th></tr></thead>
                    <tbody>
                        @foreach ($filas as $fila)
                            <tr wire:key="confirmar-{{ $fila['producto']->id }}"><td>{{ $fila['producto']->nombre }}</td><td>{{ $fila['cantidad'] === null ? '—' : Decimal::formatear($fila['cantidad']) }}</td><td>{{ $fila['despues'] === null ? '—' : Decimal::formatear($fila['despues']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="$set('confirmando', false)">{{ __('Volver a editar') }}</button>
                <button type="button" class="btn primary" wire:click="confirmar" wire:loading.attr="disabled" wire:target="confirmar"><x-icon name="check" /> {{ __('Confirmar traspaso') }}</button>
            </div>
        </x-modal>
    @endif
</div>
