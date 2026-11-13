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
    @php
        // Tallas y colores (V2): los modelos van primero; elegir uno abre el selector de talla y color.
        $modelosSugeridos = $this->sugerenciasModelos;
    @endphp
    @if ($sugerencias->isNotEmpty() || $modelosSugeridos->isNotEmpty())
        <div class="suggestions" role="listbox" aria-label="{{ __('Productos encontrados') }}">
            @foreach ($modelosSugeridos as $modelo)
                <button type="button" class="suggestion" role="option" wire:key="sugerencia-modelo-{{ $modelo->id }}"
                    @click="texto = ''; $wire.abrirSelector({{ $modelo->id }})">
                    <span class="suggestion-product"><b>{{ $modelo->nombre }}</b><small>{{ trans_choice(':count combinación|:count combinaciones', $modelo->combinaciones) }} · {{ $modelo->unidad->abreviatura ?? '' }}</small></span>
                    <span class="kbd-quiet">{{ __('Elegir combinación') }}</span>
                </button>
            @endforeach
            @foreach ($sugerencias as $sugerencia)
                <button type="button" class="suggestion" role="option" wire:key="sugerencia-{{ $sugerencia->id }}"
                    @click="texto = ''; $wire.agregarProducto({{ $sugerencia->id }}); $refs.buscador.focus()">
                    <span class="suggestion-product"><b>{{ $sugerencia->nombre }}</b><small>{{ implode(' · ', array_filter([$sugerencia->marca, $sugerencia->sku, $sugerencia->unidad->abreviatura])) }}</small></span>
                    <span class="kbd-quiet">{{ __('Agregar') }}</span>
                </button>
            @endforeach
        </div>
    @elseif (mb_strlen(trim($this->busqueda)) >= 2)
        {{-- Se buscó y no hay coincidencias: se dice en vez de no mostrar nada. Alpine lo oculta si el texto ya se borró. --}}
        {{-- Si coincide un producto que no controla stock (compras, ajustes y traspasos no lo ofrecen), se dice por qué no aparece. --}}
        @php $sinControl = $this->coincidenciasSinControl; @endphp
        <div class="suggestions" role="status" x-show="texto.trim().length >= 2" data-test="sin-productos">
            @if ($sinControl->isNotEmpty())
                <div class="suggestion suggestion-vacia"><span class="suggestion-product"><b>{{ __(':producto no controla stock', ['producto' => $sinControl->join(', ')]) }}</b><small>{{ __('Para comprarlo, ajustarlo o traspasarlo, marca «Controla stock» en Productos.') }}</small></span></div>
            @else
                <div class="suggestion suggestion-vacia"><span class="suggestion-product"><b>{{ __('Producto no encontrado') }}</b><small>{{ __('Revisa el nombre o el código, o créalo en Productos.') }}</small></span></div>
            @endif
        </div>
    @endif
    <button class="btn primary" type="button" @click="agregar(); $refs.buscador.focus()"><x-icon name="plus" /> {{ __('Agregar producto') }}</button>
</div>
