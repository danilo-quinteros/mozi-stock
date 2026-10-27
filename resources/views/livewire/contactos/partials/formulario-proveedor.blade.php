{{-- Formulario de proveedor compartido por el directorio y la ficha. Variables: $titulo, $accion. --}}
<x-modal :titulo="$titulo" descripcion="Datos privados de tu empresa. El NIT/CI no puede repetirse dentro de ella." cerrar="cerrar">
    <form wire:submit="{{ $accion }}">
        <div class="fields">
            <x-campo etiqueta="Nombre o razón social" campo="nombre" style="grid-column:1/-1">
                <input id="nombre" class="input" type="text" maxlength="150" wire:model="nombre" required autofocus>
            </x-campo>
            <x-campo etiqueta="NIT / CI (opcional)" campo="documento">
                <input id="documento" class="input" type="text" maxlength="30" wire:model="documento">
            </x-campo>
            <x-campo etiqueta="Ciudad (opcional)" campo="ciudad">
                <input id="ciudad" class="input" type="text" maxlength="80" wire:model="ciudad">
            </x-campo>
            <x-campo etiqueta="Teléfono (opcional)" campo="telefono">
                <input id="telefono" class="input" type="text" maxlength="40" wire:model="telefono">
            </x-campo>
            <x-campo etiqueta="Correo (opcional)" campo="correo">
                <input id="correo" class="input" type="email" maxlength="150" wire:model="correo">
            </x-campo>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn ghost" wire:click="cerrar">{{ __('Cancelar') }}</button>
            <button class="btn primary" type="submit" wire:loading.attr="disabled"><x-icon name="check" /> {{ __('Guardar') }}</button>
        </div>
    </form>
</x-modal>
