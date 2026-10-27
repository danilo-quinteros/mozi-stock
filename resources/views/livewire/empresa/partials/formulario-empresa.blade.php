{{-- Datos generales de la empresa: lo usan la página Mi empresa y el popup de la guía de configuración. --}}
<form id="datos-empresa" wire:submit="guardar">
    <div class="fields">
        <x-campo etiqueta="Nombre comercial" campo="nombre">
            <input id="nombre" class="input" type="text" wire:model="nombre" maxlength="150" required>
        </x-campo>
        <x-campo etiqueta="Razón social" campo="razon_social">
            <input id="razon_social" class="input" type="text" wire:model="razon_social" maxlength="200">
        </x-campo>
        <x-campo etiqueta="Correo" campo="email">
            <input id="email" class="input" type="email" wire:model="email" maxlength="255">
        </x-campo>
        <x-campo etiqueta="Teléfono" campo="telefono">
            <input id="telefono" class="input" type="tel" wire:model="telefono" maxlength="40">
        </x-campo>
        <x-campo etiqueta="NIT (opcional)" campo="nit">
            <input id="nit" class="input" type="text" wire:model="nit" maxlength="30">
        </x-campo>
        <div class="field">
            <label class="field-label" for="moneda">{{ __('Moneda') }}</label>
            <select id="moneda" class="select" disabled><option>{{ __('Bolivianos (Bs)') }}</option></select>
        </div>
    </div>
</form>
