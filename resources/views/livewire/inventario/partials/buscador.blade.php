@use('App\Domain\Catalogo\Consultas\ListadoProductos')
@use('App\Support\Decimal')
{{-- Buscador/escáner compartido (compras, ajustes, traspasos, pedidos y recetas). Variables: $sugerencias y, opcionales,
     $placeholder, $vacioTitulo, $vacioAyuda y $botonAgregar (textos para otra pantalla, como las recetas).
     El texto lo controla Alpine: Enter lo vacía al instante para el siguiente escaneo. Las
     lecturas se envían en cola, una tras otra: Livewire agrupa llamadas idénticas simultáneas
     y un lector rápido perdería unidades. Si nada se agregó, el texto se restaura para elegir
     entre las sugerencias.

     Panel «Agregar productos» (compras, ajustes, traspasos y recetas; no en ventas): al hacer clic en el buscador o al
     escribir (updatedBusqueda) se abre debajo con pestañas Todos / Stock bajo / categorías y hasta 18 productos que se
     filtran con lo que se escribe. En esas pantallas la lista desplegable antigua está inhabilitada (se conserva abajo;
     se restaura con muestraListaDesplegable() en CapturaProductos).
     Los marcados viven en Alpine (se conservan al cambiar de pestaña o de búsqueda) y se envían juntos al servidor,
     que vuelve a validar empresa, clase y control de stock en agregarMarcados. --}}
@php
    $conListado = $this->ofreceListadoMasivo();
    $listadoAbierto = $conListado && $this->listadoAbierto;
    // Lista desplegable antigua: solo donde no hay panel (Pedidos) o si se restaura, y nunca encima del panel abierto.
    $listaDesplegable = $this->muestraListaDesplegable() && ! $listadoAbierto;
@endphp
<div class="buscador-captura"
    x-data="{
        texto: '',
        cola: [],
        ocupado: false,
        marcados: [],
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
        abrirListado() {
            if (@js($conListado) && ! $wire.listadoAbierto) $wire.abrirListado();
        },
        cerrarListado() {
            this.marcados = [];
            if ($wire.listadoAbierto) $wire.cerrarListado();
        },
        alternar(id) {
            this.marcados = this.marcados.includes(id) ? this.marcados.filter((m) => m !== id) : [...this.marcados, id];
        },
        async agregarMarcados() {
            if (this.marcados.length === 0) return;
            const ids = [...this.marcados];
            this.marcados = [];
            this.texto = '';
            await $wire.agregarMarcados(ids);
            this.$nextTick(() => this.$refs.buscador?.focus());
        },
    }"
    x-init="texto = $refs.buscador.value"
    @mozistock-scan.window="agregar($event.detail.codigo)"
    @keydown.f2.window.prevent="$refs.buscador.focus()">
    <div class="scanner-wrap">
        <span class="scan-symbol" aria-hidden="true"><x-icon name="scan" /></span>
        <input x-ref="buscador" class="input" type="search" autocomplete="off" spellcheck="false" autofocus
            placeholder="{{ __($placeholder ?? __('Buscar nombre o escanear código de barras')) }}" aria-label="{{ __('Buscar o escanear producto') }}"
            @if ($conListado) aria-controls="listado-masivo" aria-expanded="{{ $listadoAbierto ? 'true' : 'false' }}" @click="abrirListado()" @keydown.escape="cerrarListado()" @endif
            x-model="texto"
            @input.debounce.250ms="$wire.set('busqueda', texto)"
            @keydown.enter.prevent="agregar()">
        @php
            // Tallas y colores (V2): los modelos van primero; elegir uno abre el selector de talla y color.
            $modelosSugeridos = $this->sugerenciasModelos;
            // Catálogo de un proveedor externo (Cyclone): lo que aún no se vende, solo en compras y ajustes de ingreso.
            $externas = $this->sugerenciasExternas;
        @endphp
        {{-- Lista desplegable antigua. Inhabilitada donde está el panel (lo escrito filtra el panel); se conserva para
             poder restaurarla. --}}
        @if (! $listaDesplegable)
        @elseif ($sugerencias->isNotEmpty() || $modelosSugeridos->isNotEmpty() || $externas->isNotEmpty())
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
                @if ($externas->isNotEmpty())
                    @include('livewire.inventario.partials.buscador-externas', ['externas' => $externas])
                @endif
            </div>
        @elseif (mb_strlen(trim($this->busqueda)) >= 2)
            {{-- Se buscó y no hay coincidencias: se dice en vez de no mostrar nada. Alpine lo oculta si el texto ya se borró. --}}
            {{-- Si coincide un producto que no controla stock (compras, ajustes y traspasos no lo ofrecen), se dice por qué no aparece. --}}
            @php $sinControl = $this->coincidenciasSinControl; @endphp
            <div class="suggestions" role="status" x-show="texto.trim().length >= 2" data-test="sin-productos">
                @if ($sinControl->isNotEmpty())
                    <div class="suggestion suggestion-vacia"><span class="suggestion-product"><b>{{ __(':producto no controla stock', ['producto' => $sinControl->join(', ')]) }}</b><small>{{ __('Para comprarlo, ajustarlo o traspasarlo, marca «Controla stock» en Productos.') }}</small></span></div>
                @else
                    <div class="suggestion suggestion-vacia"><span class="suggestion-product"><b>{{ __($vacioTitulo ?? 'Producto no encontrado') }}</b><small>{{ __($vacioAyuda ?? 'Revisa el nombre o el código, o créalo en Productos.') }}</small></span></div>
                @endif
            </div>
        @endif
        <button class="btn primary" type="button" @click="agregar(); $refs.buscador.focus()"><x-icon name="plus" /> {{ __($botonAgregar ?? 'Agregar producto') }}</button>
    </div>

    @if ($listadoAbierto)
        @php
            $listado = $this->listado;
            $pestanas = $this->pestanasListado;
            $tab = $listado['tab'];
            $termino = trim($this->busqueda);
            $enTabla = array_flip(array_column($this->lineas, 'id'));
            $nombreTab = match (true) {
                $tab === 'stock_bajo' => __('Stock bajo'),
                is_int($tab) => collect($pestanas['categorias'])->firstWhere('id', $tab)['nombre'] ?? __('esta categoría'),
                default => null,
            };
        @endphp
        <div class="multipick-panel" id="listado-masivo" data-test="listado-masivo" wire:key="listado-masivo">
            <div class="multipick-head">
                <div class="multipick-tabs" role="tablist" aria-label="{{ __('Filtrar productos') }}">
                    <button type="button" role="tab" @class(['multipick-tab', 'active' => $tab === 'todos']) aria-selected="{{ $tab === 'todos' ? 'true' : 'false' }}"
                        wire:click="$set('listadoTab', 'todos')">{{ __('Todos') }}<small>{{ $pestanas['total'] }}</small></button>
                    @if ($pestanas['stockBajo'] !== null)
                        <button type="button" role="tab" @class(['multipick-tab', 'active' => $tab === 'stock_bajo']) aria-selected="{{ $tab === 'stock_bajo' ? 'true' : 'false' }}"
                            title="{{ __('Sin stock o en su mínimo en esta sucursal') }}" data-test="tab-stock-bajo"
                            wire:click="$set('listadoTab', 'stock_bajo')">{{ __('Stock bajo') }}<small>{{ $pestanas['stockBajo'] }}</small></button>
                    @endif
                    @foreach ($pestanas['categorias'] as $categoria)
                        <button type="button" role="tab" @class(['multipick-tab', 'active' => $tab === $categoria['id']]) aria-selected="{{ $tab === $categoria['id'] ? 'true' : 'false' }}"
                            wire:key="tab-categoria-{{ $categoria['id'] }}"
                            wire:click="$set('listadoTab', '{{ $categoria['id'] }}')">{{ $categoria['nombre'] }}<small>{{ $categoria['total'] }}</small></button>
                    @endforeach
                </div>
                <button type="button" class="btn ghost sm" @click="cerrarListado(); $refs.buscador.focus()">{{ __('Cerrar') }}</button>
            </div>

            @if ($modelosSugeridos->isNotEmpty())
                <div class="multipick-modelos">
                    @foreach ($modelosSugeridos as $modelo)
                        <button type="button" class="suggestion" wire:key="listado-modelo-{{ $modelo->id }}" @click="$wire.abrirSelector({{ $modelo->id }})">
                            <span class="suggestion-product"><b>{{ $modelo->nombre }}</b><small>{{ trans_choice(':count combinación|:count combinaciones', $modelo->combinaciones) }} · {{ $modelo->unidad->abreviatura ?? '' }}</small></span>
                            <span class="kbd-quiet">{{ __('Elegir combinación') }}</span>
                        </button>
                    @endforeach
                </div>
            @endif

            @if ($listado['productos']->isNotEmpty())
                <div class="multipick-grid" role="group" aria-label="{{ __('Productos para marcar') }}">
                    @foreach ($listado['productos'] as $producto)
                        @php
                            $stock = (string) $producto->stock;
                            $alerta = $listado['conStock'] ? ListadoProductos::alerta($stock, (string) $producto->minimo) : 'normal';
                        @endphp
                        <label class="multipick-item" wire:key="listado-{{ $producto->id }}" :class="{ 'is-checked': marcados.includes({{ $producto->id }}) }">
                            <input type="checkbox" value="{{ $producto->id }}" :checked="marcados.includes({{ $producto->id }})" @change="alternar({{ $producto->id }})"
                                aria-label="{{ __('Marcar :nombre', ['nombre' => $producto->nombre]) }}">
                            <span class="multipick-info">
                                <b title="{{ $producto->nombre }}">{{ $producto->nombre }}</b>
                                <small>
                                    {{ implode(' · ', array_filter([$producto->marca, $producto->sku])) }}@if ($producto->sku || $producto->marca) · @endif
                                    @if ($listado['conStock'])
                                        <span @class(['multipick-bajo' => $alerta !== 'normal'])>{{ __('Stock :cantidad', ['cantidad' => Decimal::formatear($stock)]) }} {{ $producto->unidad->abreviatura ?? '' }}@if ($alerta === 'bajo') · {{ __('mín. :minimo', ['minimo' => Decimal::formatear((string) $producto->minimo)]) }}@endif</span>
                                    @else
                                        {{ $producto->unidad->abreviatura ?? '' }}
                                    @endif
                                    @isset($enTabla[$producto->id])
                                        · <span class="multipick-en-tabla">{{ __('en la tabla') }}</span>
                                    @endisset
                                </small>
                            </span>
                        </label>
                    @endforeach
                </div>
                @if ($listado['mas'])
                    <p class="multipick-nota">{{ __('Se muestran :max productos. Escribe en el buscador para encontrar otros.', ['max' => $this::MAX_LISTADO]) }}</p>
                @endif
            @elseif ($modelosSugeridos->isEmpty() && ($termino === '' || $externas->isEmpty()))
                @php $sinControl = $termino === '' ? collect() : $this->coincidenciasSinControl; @endphp
                <div class="multipick-vacio" data-test="listado-vacio">
                    @if ($sinControl->isNotEmpty())
                        <b>{{ __(':producto no controla stock', ['producto' => $sinControl->join(', ')]) }}</b>
                        <span>{{ __('Para comprarlo, ajustarlo o traspasarlo, marca «Controla stock» en Productos.') }}</span>
                    @elseif ($termino !== '' && $nombreTab !== null)
                        <b>{{ __('Nada coincide con «:termino» en :pestana.', ['termino' => $termino, 'pestana' => $nombreTab]) }}</b>
                        <span><button type="button" class="btn-enlace" wire:click="$set('listadoTab', 'todos')">{{ __('Buscar en Todos') }}</button></span>
                    @elseif ($termino !== '')
                        <b>{{ __($vacioTitulo ?? 'Producto no encontrado') }}</b>
                        <span>{{ __($vacioAyuda ?? 'Revisa el nombre o el código, o créalo en Productos.') }}</span>
                    @elseif ($tab === 'stock_bajo')
                        <b>{{ __('Ningún producto con stock bajo en esta sucursal.') }}</b>
                        <span>{{ __('Aquí aparecen los productos sin stock o en su stock mínimo.') }}</span>
                    @else
                        <b>{{ __('No hay productos para agregar aquí.') }}</b>
                        <span>{{ __('Solo aparecen productos activos que controlan stock.') }}</span>
                    @endif
                </div>
            @endif

            @if ($termino !== '' && $externas->isNotEmpty())
                <div class="multipick-externas">
                    @include('livewire.inventario.partials.buscador-externas', ['externas' => $externas])
                </div>
            @endif

            <div class="multipick-foot">
                <span class="subtle" x-text="marcados.length + ' ' + (marcados.length === 1 ? @js(__('seleccionado')) : @js(__('seleccionados')))">0 {{ __('seleccionados') }}</span>
                <div class="row">
                    <button type="button" class="btn ghost sm" @click="marcados = []" :disabled="marcados.length === 0">{{ __('Limpiar selección') }}</button>
                    <button type="button" class="btn primary sm" data-test="agregar-marcados" @click="agregarMarcados()" :disabled="marcados.length === 0"
                        wire:loading.attr="disabled" wire:target="agregarMarcados">{{ __('Agregar marcados a la tabla') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
