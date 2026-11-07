{{-- Buscador/escáner compartido (ajustes, traspasos y pedidos). Variables: $sugerencias.
     El texto lo controla Alpine: Enter lo vacía al instante para el siguiente escaneo. Las
     lecturas se envían en cola, una tras otra: Livewire agrupa llamadas idénticas simultáneas
     y un lector rápido perdería unidades. Si nada se agregó, el texto se restaura para elegir
     entre las sugerencias. --}}
<div class="scanner-wrap"
    x-data="{
        texto: '',
        cola: [],
        ocupado: false,
        agregar(forzado = null) {
            const valor = (forzado ?? this.texto).trim();
            if (valor === '') return;
            this.texto = '';
            this.cola.push(valor);
            this.procesar();
            this.$nextTick(() => this.$refs.buscador?.focus());
        },
        async procesar() {
            if (this.ocupado) return;
            this.ocupado = true;
            while (this.cola.length > 0) {
                const valor = this.cola.shift();
                const agregado = await $wire.agregarDesdeBuscador(valor);
                if (! agregado && this.texto === '') this.texto = valor;
            }
            this.ocupado = false;
            this.$nextTick(() => this.$refs.buscador?.focus());
        },
    }"
    x-init="texto = $refs.buscador.value"
    @mozistock-scan.window="agregar($event.detail.codigo)"
    @keydown.f2.window.prevent="$refs.buscador.focus()">
    <span class="scan-symbol" aria-hidden="true"><x-icon name="scan" /></span>
    <input x-ref="buscador" class="input" type="search" autocomplete="off" spellcheck="false" autofocus
        placeholder="{{ __($placeholder ?? __('Buscar nombre o escanear código de barras')) }}" aria-label="{{ __('Buscar o escanear producto') }}"
        x-model="texto"
        @input.debounce.250ms="$wire.set('busqueda', texto)"
        @keydown.enter.prevent="agregar()">
    @if ($sugerencias->isNotEmpty())
        <div class="suggestions" role="listbox" aria-label="{{ __('Productos encontrados') }}">
            @foreach ($sugerencias as $sugerencia)
                <button type="button" class="suggestion" role="option" wire:key="sugerencia-{{ $sugerencia->id }}"
                    @click="texto = ''; $wire.agregarProducto({{ $sugerencia->id }}); $refs.buscador.focus()">
                    <span class="suggestion-product"><b>{{ $sugerencia->nombre }}</b><small>{{ $sugerencia->sku }} · {{ $sugerencia->unidad->abreviatura }}</small></span>
                    <span class="kbd-quiet">{{ __('Agregar') }}</span>
                </button>
            @endforeach
        </div>
    @endif
    <button class="btn primary" type="button" @click="agregar(); $refs.buscador.focus()"><x-icon name="plus" /> {{ __('Agregar producto') }}</button>
</div>
