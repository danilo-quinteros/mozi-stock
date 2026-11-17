{{-- Productos del catálogo de un proveedor externo (Cyclone) que la empresa aún no vende: en la lista desplegable del
     buscador y en el panel «Agregar productos». Variable: $externas (no vacía). Usa el x-data del buscador. --}}
<div class="suggestion-grupo" data-test="sugerencias-externas">{{ __('Del catálogo :proveedor · aún no lo vendes', ['proveedor' => $externas->first()->proveedor]) }}</div>
@foreach ($externas as $externa)
    <button type="button" class="suggestion" role="option" wire:key="sugerencia-externa-{{ $externa->id }}"
        wire:loading.attr="disabled" wire:target="agregarDesdeCatalogoExterno"
        @click="$wire.agregarDesdeCatalogoExterno({{ $externa->id }}).then((ok) => { if (ok) texto = '' }); $refs.buscador.focus()">
        <span class="suggestion-product"><b>{{ $externa->nombre }}</b><small>{{ implode(' · ', array_filter([$externa->linea, $externa->codigo, $externa->inactivo ? __('Lo dejaste de vender') : null])) }}</small></span>
        <span class="kbd-quiet">{{ __('Agregar a mi catálogo') }}</span>
    </button>
@endforeach
