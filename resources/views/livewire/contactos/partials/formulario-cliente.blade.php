{{-- Formulario de cliente compartido por el directorio y la ficha. Variables: $titulo, $accion. --}}
<x-modal :titulo="$titulo" descripcion="Datos privados de tu empresa. El CI/NIT no puede repetirse dentro de ella." cerrar="cerrar">
    <form wire:submit="{{ $accion }}">
        <div class="fields">
            <x-campo etiqueta="Nombre o razón social" campo="clienteNombre" style="grid-column:1/-1">
                <input id="clienteNombre" class="input" type="text" maxlength="150" wire:model="clienteNombre" required autofocus>
            </x-campo>
            <x-campo etiqueta="CI / NIT (opcional)" campo="clienteDocumento">
                <input id="clienteDocumento" class="input" type="text" maxlength="30" wire:model="clienteDocumento">
            </x-campo>
            <x-campo etiqueta="Teléfono (opcional)" campo="clienteTelefono">
                <input id="clienteTelefono" class="input" type="text" maxlength="40" wire:model="clienteTelefono">
            </x-campo>
            <x-campo etiqueta="Correo (opcional)" campo="clienteCorreo" style="grid-column:1/-1">
                <input id="clienteCorreo" class="input" type="email" maxlength="150" wire:model="clienteCorreo">
            </x-campo>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn ghost" wire:click="cerrar">{{ __('Cancelar') }}</button>
            <button class="btn primary" type="submit" wire:loading.attr="disabled"><x-icon name="check" /> {{ __('Guardar') }}</button>
        </div>
    </form>
</x-modal>
