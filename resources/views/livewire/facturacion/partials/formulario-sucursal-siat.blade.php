{{-- Sucursal en el padrón del SIN (al registrarla se crea su punto de venta 0): lo usan Facturación → Sucursales y puntos de venta y el popup de la guía. --}}
<form wire:submit="guardarSucursal">
    <div class="fields">
        <x-campo etiqueta="Código SIN" campo="codigo">
            <input id="codigo" class="input" type="number" min="0" max="9999" wire:model="codigo" required autofocus>
        </x-campo>
        <x-campo etiqueta="Municipio" campo="municipio">
            <input id="municipio" class="input" type="text" wire:model="municipio" maxlength="100" placeholder="{{ __('Ej. Santa Cruz') }}" required>
        </x-campo>
        <x-campo etiqueta="Teléfono (opcional)" campo="telefono" style="grid-column:1/-1">
            <input id="telefono" class="input" type="tel" wire:model="telefono" maxlength="25">
        </x-campo>
    </div>
    <div class="modal-actions">
        @unless ($embebido)
            <button type="button" class="btn ghost" wire:click="cerrarSucursal">{{ __('Cancelar') }}</button>
        @endunless
        <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="guardarSucursal"><x-icon name="check" /> {{ __('Guardar') }}</button>
    </div>
</form>
