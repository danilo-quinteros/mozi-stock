{{-- Alta y edición de una sucursal: lo usan la página Sucursales y cajas y el popup de la guía de configuración. --}}
<form wire:submit="guardar">
    <div class="fields">
        <x-campo etiqueta="Nombre" campo="nombre">
            <input id="nombre" class="input" type="text" wire:model="nombre" maxlength="120" placeholder="{{ __('Ej. Casa Matriz') }}" required autofocus>
        </x-campo>
        <x-campo etiqueta="Código interno" campo="codigo">
            <input id="codigo" class="input" type="text" wire:model="codigo" maxlength="20" placeholder="{{ __('Ej. MATRIZ') }}" required>
        </x-campo>
        <x-campo etiqueta="Dirección (opcional)" campo="direccion" style="grid-column:1/-1">
            <input id="direccion" class="input" type="text" wire:model="direccion" maxlength="255" placeholder="{{ __('Zona, calle, ciudad') }}">
        </x-campo>
    </div>
    @if (! $embebido && $editandoId && ($editada = $sucursales->firstWhere('id', $editandoId)))
        <div class="sc-peligro">
            <div>
                <b>{{ $editada->activa ? __('Desactivar sucursal') : __('Activar sucursal') }}</b>
                <p>{{ $editada->activa
                    ? __('No se borra nada. Antes cierra sus turnos abiertos y reasigna o desactiva a sus vendedores.')
                    : __('Volverá a estar disponible para operar y asignar vendedores.') }}</p>
            </div>
            <button type="button" class="btn ghost sm" wire:click="confirmarEstado({{ $editada->id }})">{{ $editada->activa ? __('Desactivar') : __('Activar') }}</button>
        </div>
    @endif
    <div class="modal-actions">
        @unless ($embebido)
            <button type="button" class="btn ghost" wire:click="cerrarFormulario">{{ __('Cancelar') }}</button>
        @endunless
        <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="guardar"><x-icon name="check" /> {{ __('Guardar') }}</button>
    </div>
</form>
