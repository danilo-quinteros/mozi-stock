{{--
    «+ Nuevo proveedor» del trait CreaProveedorRapido.
    - $parte = 'ayuda': va dentro del campo Proveedor, debajo del select (de dónde sale la lista y el enlace).
    - $parte = 'formulario': bloque a todo el ancho, después del campo, con los datos mínimos del proveedor.
    No es un <form> (vive dentro del formulario principal): Enter guarda el proveedor, no el formulario de fuera.
--}}
@if (($parte ?? 'ayuda') === 'ayuda')
    <small class="campo-ayuda">
        {{ __('De tu lista de proveedores.') }}
        @if (! $proveedorRapido && $this->puedeCrearProveedor())
            <button type="button" class="btn-enlace" wire:click="abrirProveedorRapido" data-test="nuevo-proveedor">{{ __('+ Nuevo proveedor') }}</button>
        @endif
    </small>
@elseif ($proveedorRapido)
    <div class="proveedor-rapido" style="grid-column:1/-1" wire:keydown.enter.prevent="guardarProveedorRapido">
        <div class="proveedor-rapido-titulo">{{ __('Nuevo proveedor') }}<span class="subtle">{{ __('Completa el resto de sus datos después en Contactos › Proveedores.') }}</span></div>
        <div class="proveedor-rapido-campos">
            <x-campo etiqueta="Nombre o razón social *" campo="proveedorNombre">
                <input id="proveedorNombre" class="input" type="text" maxlength="150" wire:model="proveedorNombre" autocomplete="off" autofocus>
            </x-campo>
            <x-campo etiqueta="NIT o CI (opcional)" campo="proveedorDocumento">
                <input id="proveedorDocumento" class="input" type="text" maxlength="30" wire:model="proveedorDocumento" autocomplete="off">
            </x-campo>
            <x-campo etiqueta="Teléfono (opcional)" campo="proveedorTelefono">
                <input id="proveedorTelefono" class="input" type="text" maxlength="40" inputmode="tel" wire:model="proveedorTelefono" autocomplete="off">
            </x-campo>
        </div>
        <div class="proveedor-rapido-acciones">
            <button type="button" class="btn ghost sm" wire:click="cancelarProveedorRapido">{{ __('Cancelar') }}</button>
            <button type="button" class="btn soft sm" wire:click="guardarProveedorRapido" wire:loading.attr="disabled" wire:target="guardarProveedorRapido"><x-icon name="check" /> {{ __('Guardar proveedor') }}</button>
        </div>
    </div>
@endif
