@use('App\Support\Decimal')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Control de existencias') }}</div>
            <h1>{{ __('Ajuste rápido de inventario') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Registra entradas, salidas y correcciones de inventario.') }}</p>
        </div>
    </div>

    @if ($sucursales->isEmpty())
        <div class="notice" style="margin-bottom:17px"><x-icon name="info" /><div><b>{{ __('Primero registra una sucursal') }}</b><p>{{ __('El stock siempre pertenece a una sucursal.') }} <a class="link" href="{{ route('sucursales.index') }}">{{ __('Ir a Sucursales') }} <x-icon name="arrow" size="sm" /></a></p></div></div>
    @endif

    <section class="card card-pad rapid-header">
        <div class="card-head"><div class="heading">{{ __('Datos de la operación') }}</div></div>
        <div class="rapid-form-fields">
            <x-campo etiqueta="Tipo de movimiento" campo="tipo">
                <select id="tipo" class="select" wire:model.live="tipo">
                    <option value="entrada">{{ __('Entrada · agregar stock') }}</option>
                    <option value="salida">{{ __('Salida · disminuir stock') }}</option>
                    <option value="ajuste">{{ __('Ajuste · corrección') }}</option>
                </select>
            </x-campo>
            @if ($tipoActual === \App\Enums\TipoOperacionInventario::Ajuste)
                <x-campo etiqueta="Dirección" campo="direccion">
                    <select id="direccion" class="select" wire:model.live="direccion">
                        <option value="sumar">{{ __('Sumar existencias') }}</option>
                        <option value="restar">{{ __('Restar existencias') }}</option>
                    </select>
                </x-campo>
            @else
                <x-campo etiqueta="Motivo" campo="motivo">
                    <select id="motivo" class="select" wire:model="motivo">
                        <option value="">{{ __('Selecciona un motivo') }}</option>
                        @foreach ($motivos as $clave => $etiqueta)
                            <option value="{{ $clave }}">{{ __($etiqueta) }}</option>
                        @endforeach
                    </select>
                </x-campo>
            @endif
            <x-campo etiqueta="Sucursal" campo="sucursal_id">
                <select id="sucursal_id" class="select" wire:model.live="sucursal_id">
                    @foreach ($sucursales as $sucursal)
                        <option value="{{ $sucursal->id }}">{{ $sucursal->nombre }}</option>
                    @endforeach
                </select>
            </x-campo>
            <x-campo etiqueta="Nota *" campo="nota" class="rapid-note-field">
                <input id="nota" class="input" type="text" maxlength="250" wire:model.blur="nota" placeholder="{{ __('Describe brevemente por qué se realiza el movimiento') }}" required>
            </x-campo>
        </div>
    </section>

    <div class="rapid-layout">
        @include('livewire.inventario.partials.captura', [
            'sugerencias' => $this->sugerencias,
            'etiquetaStock' => 'Stock actual',
            'etiquetaDespues' => 'Stock después',
        ])
        <aside class="rapid-aside">
            <section class="card card-pad summary-sticky">
                <div class="heading">{{ __('Resumen') }}</div>
                <p class="subtle" style="margin-top:10px">{{ __('Productos del movimiento ·') }} <b>{{ count($filas) }}</b></p>
                <div class="summary-count"><span>{{ __('Unidades') }}</span><b>{{ Decimal::formatear($unidades) }}</b></div>
                <div class="summary-count"><span>{{ __('Variación de stock') }}</span><b>{{ Decimal::comparar($variacion, '0') > 0 ? '+' : '' }}{{ Decimal::formatear($variacion) }}</b></div>
                <div class="divider"></div>
                <div class="notice mini-note">{{ __('Un ajuste no es un cobro ni un egreso. Queda en el historial con tu usuario, la sucursal y la nota.') }}</div>
                <div class="rapid-final-actions">
                    <button class="btn primary" type="button" wire:click="revisar" @disabled($sucursales->isEmpty())>{{ __('Revisar y confirmar') }} <x-icon name="arrow" size="sm" /></button>
                    <a class="btn ghost" href="{{ route('inventario.movimientos') }}">{{ __('Volver sin guardar') }}</a>
                </div>
            </section>
        </aside>
    </div>

    @if ($confirmando)
        <x-modal titulo="Confirmar movimiento" descripcion="Revisa los datos. Al confirmar se actualiza el stock de la sucursal en una sola operación." cerrar="$set('confirmando', false)">
            <div class="confirm-grid">
                <div><span class="subtle">{{ __('Operación') }}</span><b>{{ $tipoActual === \App\Enums\TipoOperacionInventario::Ajuste ? __('Corrección · ').($direccion === 'restar' ? 'restar' : 'sumar') : $tipoActual->etiqueta() }}</b></div>
                <div><span class="subtle">{{ __('Sucursal') }}</span><b>{{ $sucursales->firstWhere('id', (int) $sucursal_id)?->nombre }}</b></div>
                <div><span class="subtle">{{ __('Productos') }}</span><b>{{ count($filas) }}</b></div>
                <div><span class="subtle">{{ __('Unidades') }}</span><b>{{ Decimal::formatear($unidades) }}</b></div>
            </div>
            <div class="table-wrap" style="max-height:260px">
                <table>
                    <thead><tr><th>{{ __('Producto') }}</th><th>{{ __('Cantidad') }}</th><th>{{ __('Stock después') }}</th></tr></thead>
                    <tbody>
                        @foreach ($filas as $fila)
                            <tr wire:key="confirmar-{{ $fila['producto']->id }}"><td>{{ $fila['producto']->nombre }}</td><td>{{ $fila['cantidad'] === null ? '—' : Decimal::formatear($fila['cantidad']) }}</td><td>{{ $fila['despues'] === null ? '—' : Decimal::formatear($fila['despues']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="$set('confirmando', false)">{{ __('Volver a editar') }}</button>
                <button type="button" class="btn primary" wire:click="confirmar" wire:loading.attr="disabled" wire:target="confirmar"><x-icon name="check" /> {{ __('Confirmar') }}</button>
            </div>
        </x-modal>
    @endif
</div>
