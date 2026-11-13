@use('App\Support\Decimal')
{{-- Formulario de producto, en el orden en que se piensa un producto nuevo (10/11/2026):
     1. Datos (foto, nombre, unidad, categoría, códigos y descripción) · 2. Precios · 3. Inventario (control de stock,
     lote, alerta mínima y stock inicial; al editar, el stock por sucursal) · 4. Tallas y colores, con su casilla en la
     misma tarjeta · un solo botón Guardar al final. --}}
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Catálogo') }}</div>
            <h1>{{ $productoId ? __('Editar producto') : __('Nuevo producto') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ $productoId ? __('Actualiza la ficha, precios y alertas del producto.') : __('Completa los datos y los precios. El stock inicial y las tallas y colores son opcionales.') }}</p>
        </div>
    </div>

    @if ($modeloVariante)
        <div class="notice" style="margin-bottom:16px" data-test="aviso-variante"><x-icon name="info" /><div>
            <b>{{ __('Combinación del modelo :modelo', ['modelo' => $modeloVariante->nombre]) }}</b>
            <p>{{ __('El nombre, la categoría, la unidad, los precios y el estado se cambian desde el modelo. Aquí editas el SKU, el código de barras, la foto, la descripción y el stock mínimo.') }}
                <a class="link" href="{{ route('productos.modelos.ver', $modeloVariante) }}">{{ __('Ir al modelo') }}</a></p>
        </div></div>
    @endif

    @php
        $unidadActual = $unidades->firstWhere('id', (int) $unidad_medida_id);
    @endphp

    <form wire:submit="guardar">
        <div class="product-form-container product-layout">
            <div class="stack">
                {{-- 1. Datos del producto --}}
                <section class="card card-pad" data-test="producto-datos">
                    <div class="card-head"><div><div class="heading">{{ __('Datos del producto') }}</div><p class="subtle" style="margin:9px 0 0">{{ __('Cómo se llama, en qué unidad se vende y cómo se identifica.') }}</p></div></div>
                    <div class="product-details" x-data="{
                        nombre: @entangle('nombre').live,
                        contenido: @entangle('contenido').live,
                        unidadId: @entangle('unidad_medida_id').live,
                        unidades: {{ json_encode($unidades->pluck('abreviatura', 'id')) }},
                        decimales: {{ json_encode($unidades->mapWithKeys(fn ($u) => [$u->id => (bool) $u->admite_decimales])) }},
                        get previewName() {
                            return (this.nombre || '').trim();
                        },
                        get previewContent() {
                            let n = (this.nombre || '').trim();
                            let c = (this.contenido || '').trim();
                            if (!n || !c) return '';
                            let u = this.unidades[this.unidadId] || '';
                            return (c + (u ? ' ' + u : '')).trim();
                        },
                        {{-- Cómo se vende se deduce (Producto::tipoVentaPara): con contenido, por unidades; sin contenido y con una unidad que admite decimales, por peso o medida. --}}
                        get previewExample() {
                            let n = (this.nombre || '').trim();
                            let c = (this.contenido || '').trim();
                            let u = this.unidades[this.unidadId] || '';
                            if (c) {
                                return @js(__('Se vende por unidades:')) + ' ' + (n || 'Cyclone') + ' ' + c + (u ? ' ' + u : '') + '.';
                            }
                            if (this.decimales[this.unidadId]) {
                                return @js(__('Se vende por peso o medida: admite cantidades como 1,5')) + (u ? ' ' + u : '') + '.';
                            }
                            return @js(__('Se vende por unidades enteras.'));
                        }
                    }"
                    @mozistock-scan-producto.window="$wire.set('codigo_barras', $event.detail.codigo)">
                        <div class="product-upload">
                            <label class="upload-box" for="imagen">
                                @if ($imagen && $imagen->isPreviewable())
                                    <img src="{{ $imagen->temporaryUrl() }}" alt="{{ __('Vista previa de la imagen') }}" class="upload-preview-img">
                                @elseif ($producto?->imagen_path && ! $quitarImagen)
                                    <img src="{{ route('productos.imagen', $producto) }}" alt="{{ __('Imagen actual del producto') }}" class="upload-preview-img">
                                @elseif ($modeloVariante?->imagen_path)
                                    {{-- Tallas y colores § 4.5: sin foto propia, la combinación muestra la del modelo. --}}
                                    <img src="{{ route('productos.modelos.imagen', $modeloVariante) }}" alt="{{ __('Foto del modelo') }}" class="upload-preview-img" data-test="foto-del-modelo">
                                @else
                                    <x-icon name="image" size="lg" style="width:32px;height:32px;color:var(--brand-bright)" />
                                @endif
                                <b>{{ __('Imagen del producto') }}</b>
                                <small wire:loading.remove wire:target="imagen">{{ __('Haz clic para elegir una imagen') }}</small>
                                <small wire:loading wire:target="imagen">{{ __('Subiendo…') }}</small>
                                <small>{{ __('JPG, PNG o WebP · máx. 3 MB') }}</small>
                                @if ($modeloVariante && ! $imagen && (! $producto?->imagen_path || $quitarImagen))
                                    <small data-test="nota-foto-modelo">{{ $modeloVariante->imagen_path ? __('Sin foto propia: se muestra la del modelo.') : __('Sin foto propia ni del modelo.') }}</small>
                                @endif
                            </label>
                            <input id="imagen" type="file" accept="image/png,image/jpeg,image/webp" wire:model="imagen" hidden>
                            @error('imagen')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                            @if ($imagen || ($producto?->imagen_path && ! $quitarImagen))
                                <button class="btn ghost sm" type="button" wire:click="quitarFoto">{{ __('Quitar imagen') }}</button>
                            @endif
                        </div>
                        <div class="fields">
                            {{-- Nombre con su vista previa, y unidad con el contenido opcional --}}
                            <div class="field product-content-box">
                                <div class="product-content-grid">
                                    <x-campo etiqueta="Nombre del producto *" campo="nombre">
                                        <input id="nombre" class="input" type="text" maxlength="{{ $con_variantes ? \App\Domain\Catalogo\Variantes::MAX_NOMBRE_MODELO : 150 }}" @disabled($modeloVariante)
                                            wire:model.live.debounce.200ms="nombre"
                                            x-model="nombre"
                                            placeholder="{{ __('Ej. Cyclone') }}" required autofocus>
                                    </x-campo>

                                    <div class="product-preview-wrap">
                                        <div class="product-preview-label">
                                            <x-icon name="tag" size="sm" style="width:14px; height:14px; color:var(--muted);" />
                                            <span class="field-label" style="margin:0; font-weight:600; color:var(--muted);">
                                                {{ __('Vista previa del nombre completo') }}
                                            </span>
                                        </div>
                                        <div class="product-preview-pill">
                                            <span class="product-preview-placeholder" x-show="!previewName">{{ __('Vista previa del nombre') }}</span>
                                            <strong class="product-preview-text" x-show="previewName">
                                                <span class="product-preview-base" x-text="previewName">
                                                    {{ $this->vistaPreviaNombreBase }}
                                                </span>
                                                <span class="product-preview-extra" x-text="previewContent" x-show="previewContent && ! {{ $con_variantes ? 'true' : 'false' }}">
                                                    {{ $this->vistaPreviaContenido }}
                                                </span>
                                            </strong>
                                        </div>
                                    </div>
                                </div>

                                {{-- Unidad de medida y contenido opcional. No se pregunta «¿cómo se vende?»: se deduce al guardar. --}}
                                <div style="display:flex; flex-direction:column; gap:8px;">
                                    <label class="field-label" for="unidad_medida_id" style="margin:0; font-weight:700; color:var(--text-strong); font-size:12px;">
                                        {{ $con_variantes ? __('Unidad de medida *') : __('Unidad de medida y contenido') }}
                                    </label>

                                    <div class="{{ $con_variantes ? '' : 'product-content-inputs-grid' }}">
                                        @unless ($con_variantes)
                                            <input id="contenido" class="input" type="text" inputmode="decimal" @disabled($modeloVariante)
                                                x-model="contenido" wire:model.live.debounce.200ms="contenido"
                                                placeholder="{{ __('Contenido (opcional)') }}"
                                                aria-label="{{ __('Contenido del envase (opcional)') }}">
                                        @endunless
                                        <select id="unidad_medida_id" class="select" @disabled($modeloVariante)
                                            x-model="unidadId" wire:model.live="unidad_medida_id"
                                            required
                                            aria-label="{{ __('Unidad de medida') }}">
                                            @foreach ($unidades as $opcion)
                                                <option value="{{ $opcion->id }}">{{ $opcion->nombre }} ({{ $opcion->abreviatura }})</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    @unless ($con_variantes)
                                        <small class="subtle" style="font-size:12px; margin-top:2px; display:block; color:var(--muted); line-height:1.4;">
                                            {{ __('Escribe el contenido solo si se vende en envase (Cyclone 350 g, Aceite 1 L).') }}
                                            <span x-text="previewExample" data-test="como-se-vende">{{ $this->textoEjemplo }}</span>
                                        </small>
                                        @error('contenido')<p class="auth-error" id="contenido-error" role="alert">{{ $message }}</p>@enderror
                                    @endunless
                                    @error('unidad_medida_id')<p class="auth-error" id="unidad_medida_id-error" role="alert">{{ $message }}</p>@enderror
                                </div>
                            </div>

                            <x-campo etiqueta="Categoría" campo="categoria_id" :class="$con_variantes ? 'producto-campo-ancho' : ''">
                                <select id="categoria_id" class="select" wire:model="categoria_id" @disabled($modeloVariante)>
                                    <option value="">{{ __('Sin categoría') }}</option>
                                    @foreach ($categorias as $opcion)
                                        <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                                    @endforeach
                                </select>
                            </x-campo>
                            {{-- Marca opcional: distingue dos marcas del mismo producto (cada una con su stock y precio) en
                                 buscadores, ticket y factura. Sugiere las marcas ya usadas para escribirlas siempre igual. --}}
                            @unless ($con_variantes)
                                <x-campo :etiqueta="$etiquetaMarca.' '.__('(opcional)')" campo="marca">
                                    <input id="marca" class="input" type="text" maxlength="{{ \App\Models\Producto::MAX_MARCA }}" list="marcas-sugeridas"
                                        autocomplete="off" wire:model="marca" placeholder="{{ __('Ej. Bayer, Genérico') }}" data-test="producto-marca">
                                    <datalist id="marcas-sugeridas">
                                        @foreach ($marcasSugeridas as $sugerida)
                                            <option value="{{ $sugerida }}"></option>
                                        @endforeach
                                    </datalist>
                                </x-campo>
                            @endunless
                            {{-- Con tallas y colores, SKU y código de barras van en cada combinación (cuadrícula de abajo). --}}
                            @unless ($con_variantes)
                                <x-campo etiqueta="Código / SKU" campo="sku">
                                    <div class="sku-campo">
                                        {{-- Se deshabilita en el navegador apenas se toca «Auto», sin esperar la respuesta del servidor. --}}
                                        <input id="sku" class="input" type="text" maxlength="40" wire:model="sku" aria-describedby="sku-ayuda"
                                            placeholder="{{ $sku_auto ? __('Automático') : __('Ej. BEB-001') }}" @disabled($sku_auto)
                                            x-bind:disabled="$wire.sku_auto"
                                            x-bind:placeholder="$wire.sku_auto ? @js(__('Automático')) : @js(__('Ej. BEB-001'))">
                                        <label class="toggle-etiqueta" title="{{ __('Generar el código al guardar') }}">
                                            <input type="checkbox" class="toggle" wire:model.live="sku_auto" data-test="producto-sku-auto">
                                            <span>{{ __('Auto') }}</span>
                                        </label>
                                    </div>
                                    <small class="subtle sku-ayuda" id="sku-ayuda">{{ $sku_auto
                                        ? __('Se genera al guardar con las 3 primeras letras del nombre y un número: POLERA → POL-001.')
                                        : __('Las facturas del SIN necesitan un código por producto.') }}</small>
                                </x-campo>
                                <x-campo etiqueta="Código de barras (opcional)" campo="codigo_barras">
                                    <div style="position:relative;">
                                        <input id="codigo_barras" class="input" type="text" maxlength="64"
                                            wire:model="codigo_barras"
                                            placeholder="{{ __('Escanéalo o ingrésalo') }}"
                                            data-scan-target="codigo-barras"
                                            wire:keydown.enter.prevent
                                            style="padding-right:38px;">
                                        <span style="position:absolute; right:12px; top:50%; transform:translateY(-50%); pointer-events:none; color:var(--muted); display:inline-flex;">
                                            <x-icon name="scan" size="sm" />
                                        </span>
                                    </div>
                                </x-campo>
                            @endunless
                            <x-campo etiqueta="Descripción (opcional)" campo="descripcion" class="producto-campo-ancho">
                                <textarea id="descripcion" class="textarea" maxlength="2000" wire:model="descripcion" placeholder="{{ __('Presentación, detalles o notas del producto') }}"></textarea>
                            </x-campo>
                        </div>
                    </div>
                    @if ($productoId)
                        <label class="row" style="margin-top:16px"><input type="checkbox" class="toggle" wire:model="activo" @disabled($modeloVariante)><span>{{ __('Producto activo (los inactivos no aparecen al agregar productos en operaciones)') }}</span></label>
                    @endif
                </section>

                {{-- 2. Precios de venta --}}
                <section class="card card-pad" data-test="producto-precios">
                    <div class="card-head"><div><div class="heading">{{ __('Precios de venta') }}</div><p class="subtle" style="margin:9px 0 0">{{ $con_variantes
                        ? __('Todas las tallas y colores usan estos precios. Después puedes darle un precio propio a una combinación.')
                        : __('En pedidos se selecciona uno de estos precios. Consumidor será el predeterminado.') }}</p></div></div>
                    <div class="fields three product-prices-grid">
                        <x-campo etiqueta="Consumidor (Bs) *" campo="precio_consumidor">
                            <input id="precio_consumidor" class="input" @disabled($modeloVariante) type="text" inputmode="decimal" wire:model="precio_consumidor" placeholder="0,00" required>
                        </x-campo>
                        <x-campo etiqueta="Minorista (Bs)" campo="precio_minorista">
                            <input id="precio_minorista" class="input" @disabled($modeloVariante) type="text" inputmode="decimal" wire:model="precio_minorista" placeholder="0,00">
                        </x-campo>
                        <x-campo etiqueta="Mayorista (Bs)" campo="precio_mayorista">
                            <input id="precio_mayorista" class="input" @disabled($modeloVariante) type="text" inputmode="decimal" wire:model="precio_mayorista" placeholder="0,00">
                        </x-campo>
                    </div>
                </section>

                {{-- 3. Inventario: control de stock (ESPECIFICACION_PRODUCTOS_SIN_STOCK.md § 3), lote y vencimiento
                     (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 4), alerta mínima y stock inicial (StockInicial). Las
                     combinaciones de tallas y colores siempre controlan stock y no llevan lote (GuardarModelo). --}}
                <section class="card card-pad" data-test="producto-inventario">
                    <div class="card-head"><div><div class="heading">{{ __('Inventario') }}</div><p class="subtle" style="margin:9px 0 0">{{ __('Cómo se controla el stock de este producto.') }}</p></div></div>

                    <div class="producto-opciones">
                        @unless ($con_variantes)
                            <label class="producto-opcion {{ $controla_stock ? 'is-activo' : '' }}">
                                <input type="checkbox" class="toggle" wire:model.live="controla_stock" data-test="producto-controla-stock"
                                    aria-describedby="controla-stock-ayuda" @error('controla_stock') aria-invalid="true" @enderror>
                                <span>
                                    <b>{{ __('Controla stock') }}</b>
                                    <small id="controla-stock-ayuda">{{ $conLotes && $controla_vencimiento
                                        ? __('Este producto controla lote y vencimiento, así que siempre controla stock.')
                                        : __('Desmárcala para platos preparados, servicios u otros productos que no se cuentan en inventario. Se venden sin límite de cantidad.') }}</small>
                                </span>
                            </label>
                            @error('controla_stock')<p class="auth-error" role="alert" data-test="controla-stock-error">{{ $message }}</p>@enderror

                            @if ($conLotes)
                                <label class="producto-opcion {{ $controla_vencimiento ? 'is-activo' : '' }}">
                                    <input type="checkbox" class="toggle" wire:model.live="controla_vencimiento" data-test="producto-controla-vencimiento"
                                        aria-describedby="controla-vencimiento-ayuda" @error('controla_vencimiento') aria-invalid="true" @enderror>
                                    <span>
                                        <b>{{ __('Controla lote y vencimiento') }}</b>
                                        <small id="controla-vencimiento-ayuda">{{ $productoId && ! $producto?->controla_vencimiento && $controla_vencimiento
                                            ? __('Al guardar, el stock actual de cada sucursal pasa a «Sin lote (regularización)»; el total no cambia.')
                                            : __('Compras y entradas piden N.º de lote y vencimiento. Lo vencido no se vende.') }}</small>
                                    </span>
                                </label>
                                @error('controla_vencimiento')<p class="auth-error" role="alert" data-test="controla-vencimiento-error">{{ $message }}</p>@enderror
                            @endif
                        @endunless

                        @if (! $productoId && ($controla_stock || $con_variantes))
                            <label class="producto-opcion {{ $con_stock_inicial ? 'is-activo' : '' }}">
                                <input type="checkbox" class="toggle" wire:model.live="con_stock_inicial" data-test="producto-stock-inicial">
                                <span>
                                    <b>{{ __('Cargar stock inicial') }}</b>
                                    <small>{{ __('Las unidades que ya tienes. Se registran como una entrada «Inventario inicial» en la sucursal que elijas; no mueven caja ni crean deudas. Lo que compres después va por Compras.') }}</small>
                                </span>
                            </label>
                        @endif
                    </div>

                    @if (! $productoId && $con_stock_inicial && ($controla_stock || $con_variantes))
                        <div class="fields producto-stock-inicial" data-test="producto-stock-inicial-campos">
                            <x-campo etiqueta="Sucursal *" campo="stock_sucursal_id">
                                <select id="stock_sucursal_id" class="select" wire:model="stock_sucursal_id">
                                    <option value="">{{ __('Elige la sucursal') }}</option>
                                    @foreach ($sucursalesStock as $sucursal)
                                        <option value="{{ $sucursal->id }}">{{ $sucursal->nombre }}</option>
                                    @endforeach
                                </select>
                            </x-campo>
                            @if ($con_variantes)
                                <p class="subtle producto-stock-inicial-nota">{{ __('Escribe la cantidad de cada talla y color en la tabla «Combinaciones», más abajo. Deja vacía la que no tenga stock.') }}</p>
                            @else
                                <x-campo etiqueta="{{ __('Cantidad *') }}{{ $unidadActual ? ' ('.$unidadActual->abreviatura.')' : '' }}" campo="stock_cantidad">
                                    <input id="stock_cantidad" class="input" type="text" inputmode="decimal" wire:model="stock_cantidad" placeholder="{{ __('Ej. 24') }}">
                                </x-campo>
                                @if ($pideLote)
                                    <x-campo etiqueta="N.º de lote *" campo="stock_lote">
                                        <input id="stock_lote" class="input" type="text" maxlength="40" wire:model="stock_lote" placeholder="{{ __('Ej. L2405') }}">
                                    </x-campo>
                                    <x-campo etiqueta="Vencimiento *" campo="stock_vencimiento">
                                        <input id="stock_vencimiento" class="input" type="text" inputmode="numeric" wire:model="stock_vencimiento" placeholder="{{ __('dd/mm/aaaa o mm/aaaa') }}">
                                    </x-campo>
                                @endif
                            @endif
                        </div>
                        @error('stock_inicial')<p class="auth-error" role="alert" data-test="stock-inicial-error">{{ $message }}</p>@enderror
                        @error('stock_lote')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                    @endif

                    @if ($controla_stock || $con_variantes)
                        <div class="fields producto-minimo">
                            <x-campo etiqueta="{{ $productoId ? __('Alerta de stock mínimo (general)') : __('Alerta de stock mínimo') }}" campo="stock_minimo">
                                <input id="stock_minimo" class="input" type="text" inputmode="decimal" wire:model="stock_minimo">
                            </x-campo>
                        </div>
                    @endif

                    {{-- Al editar: stock por sucursal. Un producto guardado sin control de stock no tiene stock que mostrar;
                         al desmarcar uno que sí lo controla, la tabla sigue visible para ver en qué sucursales queda stock. --}}
                    @if ($productoId && ($controla_stock || $producto?->controla_stock))
                        <div data-test="producto-stock-sucursales" class="producto-stock-sucursales">
                            <div class="field-label">{{ __('Stock por sucursal') }}</div>
                            <p class="subtle" style="margin:6px 0 12px">{{ $controla_stock
                                ? __('El stock cambia solo con ajustes y traspasos. Deja el mínimo vacío para usar la alerta general.')
                                : __('Para dejar de controlar el stock, debe estar en 0 en todas las sucursales.') }}</p>
                            <div class="table-wrap">
                                <table>
                                    <thead><tr><th>{{ __('Sucursal') }}</th><th>{{ __('Stock actual') }}</th>@if ($controla_stock)<th>{{ __('Mínimo propio') }}</th>@endif</tr></thead>
                                    <tbody>
                                        @forelse ($stockSucursales as $fila)
                                            <tr wire:key="minimo-{{ $fila['sucursal']->id }}">
                                                <td>{{ $fila['sucursal']->nombre }}</td>
                                                <td><b>{{ Decimal::formatear($fila['stock']) }}</b></td>
                                                @if ($controla_stock)
                                                    <td>
                                                        <input class="input rapid-numeric" type="text" inputmode="decimal" placeholder="{{ __('General') }}" aria-label="{{ __('Mínimo en :nombre', ['nombre' => $fila['sucursal']->nombre]) }}" wire:model="minimos.{{ $fila['sucursal']->id }}">
                                                        @error('minimos.'.$fila['sucursal']->id)<p class="auth-error">{{ $message }}</p>@enderror
                                                    </td>
                                                @endif
                                            </tr>
                                        @empty
                                            <tr><td colspan="3"><div class="empty">{{ __('No hay sucursales activas.') }}</div></td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                </section>

                {{-- Lotes por sucursal (§ 4): solo lectura; se mueven con compras, ajustes, traspasos y ventas. --}}
                @if ($productoId && $conLotes && $producto?->controla_vencimiento)
                    <section class="card card-pad" data-test="producto-lotes">
                        <div class="card-head"><div><div class="heading">{{ __('Lotes por sucursal') }}</div><p class="subtle" style="margin:9px 0 0">{{ __('Lotes con stock, en el orden en que salen. Toca un lote para ver su trazabilidad.') }}</p></div>
                            <a class="btn ghost sm" href="{{ route('inventario.vencimientos') }}">{{ __('Ver vencimientos') }} <x-icon name="arrow" size="sm" /></a></div>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>{{ __('Sucursal') }}</th><th>{{ __('Lote') }}</th><th>{{ __('Stock') }}</th><th>{{ __('Estado') }}</th></tr></thead>
                                <tbody>
                                    @forelse ($lotes as $lote)
                                        <tr wire:key="lote-{{ $lote['id'] }}-{{ $loop->index }}">
                                            <td data-label="{{ __('Sucursal') }}">{{ $lote['sucursal'] }}</td>
                                            <td data-label="{{ __('Lote') }}"><a class="link" href="{{ route('inventario.lote', $lote['id']) }}">{{ \App\Domain\Inventario\Consultas\StockLotes::etiqueta($lote) }}</a></td>
                                            <td data-label="{{ __('Stock') }}"><b>{{ Decimal::formatear($lote['cantidad']) }}</b></td>
                                            <td data-label="{{ __('Estado') }}">@include('livewire.inventario.partials.estado-lote', ['lote' => $lote])</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4"><div class="empty">{{ __('Sin stock en lotes.') }}</div></td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endif

                {{-- 4. Tallas y colores: la casilla está en su propia tarjeta; marcada, aparecen aquí la elección y debajo la
                     cuadrícula (al guardar se crea un modelo con una combinación por talla y color). --}}
                @if ($puedeVariantes)
                    <section class="card card-pad" data-test="producto-tallas-colores">
                        <div class="card-head"><div><div class="heading">{{ __('Tallas y colores') }}</div><p class="subtle" style="margin:9px 0 0">{{ __('Opcional, para ropa, calzado o cualquier producto que se vende en varias tallas o colores.') }}</p></div>
                            @if ($con_variantes)
                                @can('administrarListas', \App\Models\Modelo::class)
                                    <a class="btn ghost sm" href="{{ route('configuracion.tallas-colores') }}" target="_blank" rel="noopener"><x-icon name="edit" size="sm" /> {{ __('Editar listas') }}</a>
                                @endcan
                            @endif
                        </div>
                        <label class="producto-opcion {{ $con_variantes ? 'is-activo' : '' }}">
                            <input type="checkbox" class="toggle" wire:model.live="con_variantes" data-test="producto-con-variantes">
                            <span>
                                <b>{{ __('Este producto tiene tallas y colores') }}</b>
                                <small>{{ __('Por ejemplo, poleras o zapatos. Cada talla y color tendrá su propio stock, SKU y código de barras.') }}</small>
                            </span>
                        </label>
                        @if ($con_variantes)
                            @include('livewire.catalogo.partials.variantes-eleccion', ['conSkuBase' => true])
                        @endif
                    </section>

                    @if ($con_variantes)
                        @include('livewire.catalogo.partials.variantes-cuadricula', ['editando' => false, 'conAcciones' => false, 'conStockInicial' => $con_stock_inicial])
                    @endif
                @endif

                <div class="product-form-actions producto-form-final">
                    <a class="btn ghost" href="{{ route('productos.index') }}">{{ __('Cancelar') }}</a>
                    <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="guardar,imagen" data-test="{{ $con_variantes ? 'guardar-modelo' : 'guardar-producto' }}">
                        <x-icon name="check" /> {{ __('Guardar producto') }}
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>
