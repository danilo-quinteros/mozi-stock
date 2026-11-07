@use('App\Support\Decimal')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Catálogo') }}</div>
            <h1>{{ $productoId ? __('Editar producto') : __('Nuevo producto') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ $productoId ? __('Actualiza la ficha, precios y alertas del producto.') : __('Crea un producto con fotografía y tres precios de venta.') }}</p>
        </div>
    </div>

    <form wire:submit="guardar">
        <div class="rapid-layout product-layout">
            <div class="stack">
                <section class="card card-pad">
                    <div class="card-head"><div><div class="heading">{{ __('Datos del producto') }}</div><p class="subtle" style="margin:9px 0 0">{{ __('Solo los campos imprescindibles; el stock se controla por sucursal.') }}</p></div></div>
                    <div class="product-details" x-data="{
                        nombre: @entangle('nombre').live,
                        tipoVenta: @entangle('tipo_venta').live,
                        contenido: @entangle('contenido').live,
                        unidadId: @entangle('unidad_medida_id').live,
                        unidades: {{ json_encode($unidades->pluck('abreviatura', 'id')) }},
                        init() {
                            this.$watch('tipoVenta', val => {
                                if (val === 'medida') {
                                    this.contenido = '';
                                }
                            });
                        },
                        get previewName() {
                            let n = (this.nombre || '').trim();
                            if (!n) return '';
                            return n;
                        },
                        get previewContent() {
                            if (this.tipoVenta === 'medida') return '';
                            let n = (this.nombre || '').trim();
                            let c = (this.contenido || '').trim();
                            if (!n || !c) return '';
                            let u = this.unidades[this.unidadId] || '';
                            return (c + (u ? ' ' + u : '')).trim();
                        },
                        get previewExample() {
                            let n = (this.nombre || '').trim();
                            if (this.tipoVenta === 'medida') {
                                return '{{ __('Ejemplo:') }} ' + (n || 'Arroz') + ' — {{ __('se vende por peso o medida.') }}';
                            }
                            let c = (this.contenido || '').trim();
                            let u = this.unidades[this.unidadId] || '';
                            let descriptor = n || 'Cyclone';
                            if (c) {
                                descriptor += ' ' + c + (u ? ' ' + u : '');
                            } else if (!n) {
                                descriptor += ' 350 g';
                            }
                            return '{{ __('Ejemplo:') }} ' + descriptor + ' — {{ __('se vende por unidades.') }}';
                        }
                    }"
                    @mozistock-scan-producto.window="$wire.set('codigo_barras', $event.detail.codigo)">
                        <div class="product-upload">
                            <label class="upload-box" for="imagen">
                                @if ($imagen && $imagen->isPreviewable())
                                    <img src="{{ $imagen->temporaryUrl() }}" alt="{{ __('Vista previa de la imagen') }}" style="max-width:100%;max-height:140px;object-fit:contain;border-radius:9px">
                                @elseif ($producto?->imagen_path && ! $quitarImagen)
                                    <img src="{{ route('productos.imagen', $producto) }}" alt="{{ __('Imagen actual del producto') }}" style="max-width:100%;max-height:140px;object-fit:contain;border-radius:9px">
                                @else
                                    <x-icon name="image" size="lg" style="width:32px;height:32px;color:var(--brand-bright)" />
                                @endif
                                <b>{{ __('Imagen del producto') }}</b>
                                <small wire:loading.remove wire:target="imagen">{{ __('Haz clic para elegir una imagen') }}</small>
                                <small wire:loading wire:target="imagen">{{ __('Subiendo…') }}</small>
                                <small>{{ __('JPG, PNG o WebP · máx. 3 MB') }}</small>
                            </label>
                            <input id="imagen" type="file" accept="image/png,image/jpeg,image/webp" wire:model="imagen" hidden>
                            @error('imagen')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                            @if ($imagen || ($producto?->imagen_path && ! $quitarImagen))
                                <button class="btn ghost sm" type="button" wire:click="quitarFoto">{{ __('Quitar imagen') }}</button>
                            @endif
                        </div>
                        <div class="fields">
                            {{-- 1. Cuadro naranja superior con Nombre, Vista previa, ¿Cómo se vende? y Contenido / Presentación --}}
                            <div class="field product-content-box">
                                {{-- Fila 1: Nombre del producto (izq) y Vista previa del nombre completo (der) --}}
                                <div class="product-content-grid">
                                    <x-campo etiqueta="Nombre del producto *" campo="nombre">
                                        <input id="nombre" class="input" type="text" maxlength="150"
                                            wire:model.live.debounce.200ms="nombre"
                                            x-model="nombre"
                                            placeholder="{{ __('Ej. Cyclone') }}" required autofocus>
                                    </x-campo>

                                    <div style="display:flex; flex-direction:column; gap:8px; min-width:0;">
                                        <div style="display:flex; align-items:center; gap:6px;">
                                            <x-icon name="tag" size="sm" style="width:14px; height:14px; color:var(--muted);" />
                                            <span class="field-label" style="margin:0; font-weight:600; color:var(--muted);">
                                                {{ __('Vista previa del nombre completo') }}
                                            </span>
                                        </div>
                                        <div class="product-preview-pill">
                                            <strong x-show="previewName" style="font-size:20px; font-weight:800; letter-spacing:-0.02em; display:inline-flex; gap:8px; align-items:baseline; flex-wrap:wrap; justify-content:center; word-break:break-word; overflow-wrap:anywhere;">
                                                <span style="color:var(--text-strong, #ffffff);" x-text="previewName">
                                                    {{ $this->vistaPreviaNombreBase }}
                                                </span>
                                                <span style="color:var(--brand-bright, #e08441);" x-text="previewContent" x-show="tipoVenta === 'unidad' && previewContent">
                                                    {{ $this->vistaPreviaContenido }}
                                                </span>
                                            </strong>
                                        </div>
                                    </div>
                                </div>

                                {{-- Fila 2: ¿Cómo se vende este producto? * ⓘ --}}
                                <div style="display:flex; flex-direction:column; gap:8px;">
                                    <div style="display:flex; align-items:center; gap:6px;">
                                        <span class="field-label" style="margin:0; font-weight:700; color:var(--text-strong); font-size:12px;">
                                            {{ __('¿Cómo se vende este producto? *') }}
                                        </span>
                                        <span class="subtle" title="{{ __('Elige si este producto se comercializa y despacha por unidad fija o por peso/medida fraccionable.') }}" style="cursor:help; display:inline-flex;">
                                            <x-icon name="info" size="sm" style="width:14px; height:14px; color:var(--muted);" />
                                        </span>
                                    </div>

                                    <div class="product-selling-type-grid" role="radiogroup" aria-label="{{ __('¿Cómo se vende este producto?') }}">
                                        <label class="selling-type-card" :class="{ 'is-selected': tipoVenta === 'unidad' }">
                                            <input type="radio" name="tipo_venta" value="unidad"
                                                wire:model.live="tipo_venta"
                                                x-model="tipoVenta"
                                                class="sr-only">
                                            <div class="selling-type-indicator">
                                                <span class="radio-dot" x-show="tipoVenta === 'unidad'"></span>
                                            </div>
                                            <div class="selling-type-icon">
                                                <x-icon name="box" style="width:22px; height:22px;" />
                                            </div>
                                            <div class="selling-type-text">
                                                <span class="selling-type-title">{{ __('Por unidad') }}</span>
                                                <span class="selling-type-desc">{{ __('Botellas, cajas, piezas, paquetes, etc.') }}</span>
                                            </div>
                                        </label>

                                        <label class="selling-type-card" :class="{ 'is-selected': tipoVenta === 'medida' }">
                                            <input type="radio" name="tipo_venta" value="medida"
                                                wire:model.live="tipo_venta"
                                                x-model="tipoVenta"
                                                class="sr-only">
                                            <div class="selling-type-indicator">
                                                <span class="radio-dot" x-show="tipoVenta === 'medida'"></span>
                                            </div>
                                            <div class="selling-type-icon">
                                                <x-icon name="scale" style="width:22px; height:22px;" />
                                            </div>
                                            <div class="selling-type-text">
                                                <span class="selling-type-title">{{ __('Por peso o medida') }}</span>
                                                <span class="selling-type-desc">{{ __('Kg, gramos, litros, metros, etc.') }}</span>
                                            </div>
                                        </label>
                                    </div>
                                    @error('tipo_venta')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                                </div>

                                {{-- Fila 3A: Contenido / Presentación (solo cuando es vendido por unidad) --}}
                                <div style="display:flex; flex-direction:column; gap:8px;" x-show="tipoVenta === 'unidad'" x-cloak>
                                    <label class="field-label" for="contenido" style="margin:0; font-weight:700; color:var(--text-strong); font-size:12px;">
                                        {{ __('Contenido / Presentación') }} <span class="muted" style="font-weight:400">({{ __('opcional') }})</span>
                                    </label>

                                    <div class="product-content-inputs-grid">
                                        <input id="contenido" class="input" type="text" inputmode="decimal"
                                            x-model="contenido" wire:model.live.debounce.200ms="contenido"
                                            placeholder="{{ __('350') }}"
                                            aria-label="{{ __('Contenido numérico') }}">
                                        <select id="unidad_medida_id" class="select"
                                            x-model="unidadId" wire:model.live="unidad_medida_id"
                                            required
                                            aria-label="{{ __('Unidad de medida') }}">
                                            @foreach ($unidades as $opcion)
                                                <option value="{{ $opcion->id }}">{{ $opcion->abreviatura }} ({{ $opcion->nombre }})</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <small class="subtle" style="font-size:12px; margin-top:2px; display:block; color:var(--muted); line-height:1.4;">
                                        <span x-text="previewExample">{{ $this->textoEjemplo }}</span>
                                    </small>
                                    @error('contenido')<p class="auth-error" id="contenido-error" role="alert">{{ $message }}</p>@enderror
                                    @error('unidad_medida_id')<p class="auth-error" id="unidad_medida_id-error" role="alert">{{ $message }}</p>@enderror
                                </div>

                                {{-- Fila 3B: Unidad de medida (solo cuando es vendido por peso o medida) --}}
                                <div style="display:flex; flex-direction:column; gap:8px;" x-show="tipoVenta === 'medida'" x-cloak>
                                    <label class="field-label" for="unidad_medida_id_medida" style="margin:0; font-weight:700; color:var(--text-strong); font-size:12px;">
                                        {{ __('Unidad de medida *') }}
                                    </label>

                                    <div>
                                        <select id="unidad_medida_id_medida" class="select"
                                            x-model="unidadId" wire:model.live="unidad_medida_id"
                                            required
                                            aria-label="{{ __('Unidad de medida') }}">
                                            @foreach ($unidades as $opcion)
                                                <option value="{{ $opcion->id }}">{{ $opcion->nombre }} ({{ $opcion->abreviatura }})</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <small class="subtle" style="font-size:12px; margin-top:2px; display:block; color:var(--muted); line-height:1.4;">
                                        <span x-text="previewExample">{{ $this->textoEjemplo }}</span>
                                    </small>
                                    @error('unidad_medida_id')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                                </div>
                            </div>

                            {{-- 2. Categoría y Código de barras --}}
                            <x-campo etiqueta="Categoría" campo="categoria_id">
                                <select id="categoria_id" class="select" wire:model="categoria_id">
                                    <option value="">{{ __('Sin categoría') }}</option>
                                    @foreach ($categorias as $opcion)
                                        <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                                    @endforeach
                                </select>
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

                            {{-- 3. Código / SKU (opcional) y Alerta de stock mínimo --}}
                            <x-campo etiqueta="Código / SKU (opcional)" campo="sku">
                                <input id="sku" class="input" type="text" maxlength="40" wire:model="sku" placeholder="{{ __('Ej. BEB-001') }}">
                            </x-campo>
                            <x-campo etiqueta="Alerta de stock mínimo" campo="stock_minimo">
                                <input id="stock_minimo" class="input" type="text" inputmode="decimal" wire:model="stock_minimo">
                            </x-campo>
                        </div>
                    </div>
                    <div class="divider"></div>
                    <div class="heading">{{ __('Precios de venta') }}</div>
                    <p class="subtle" style="margin:8px 0 15px">{{ __('En pedidos se selecciona uno de estos precios. Consumidor será el predeterminado.') }}</p>
                    <div class="fields three">
                        <x-campo etiqueta="Consumidor (Bs) *" campo="precio_consumidor">
                            <input id="precio_consumidor" class="input" type="text" inputmode="decimal" wire:model="precio_consumidor" placeholder="0,00" required>
                        </x-campo>
                        <x-campo etiqueta="Minorista (Bs)" campo="precio_minorista">
                            <input id="precio_minorista" class="input" type="text" inputmode="decimal" wire:model="precio_minorista" placeholder="0,00">
                        </x-campo>
                        <x-campo etiqueta="Mayorista (Bs)" campo="precio_mayorista">
                            <input id="precio_mayorista" class="input" type="text" inputmode="decimal" wire:model="precio_mayorista" placeholder="0,00">
                        </x-campo>
                    </div>
                    <div class="divider"></div>
                    <x-campo etiqueta="Descripción (opcional)" campo="descripcion">
                        <textarea id="descripcion" class="textarea" maxlength="2000" wire:model="descripcion" placeholder="{{ __('Presentación, detalles o notas del producto') }}"></textarea>
                    </x-campo>
                    @if ($productoId)
                        <label class="row" style="margin-top:16px"><input type="checkbox" wire:model="activo"><span>{{ __('Producto activo (los inactivos no aparecen al agregar productos en operaciones)') }}</span></label>
                    @endif
                </section>

                @if ($productoId)
                    <section class="card card-pad">
                        <div class="card-head"><div><div class="heading">{{ __('Stock por sucursal') }}</div><p class="subtle" style="margin:9px 0 0">{{ __('El stock cambia solo con ajustes y traspasos. Deja el mínimo vacío para usar la alerta general.') }}</p></div></div>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>{{ __('Sucursal') }}</th><th>{{ __('Stock actual') }}</th><th>{{ __('Mínimo propio') }}</th></tr></thead>
                                <tbody>
                                    @forelse ($stockSucursales as $fila)
                                        <tr wire:key="minimo-{{ $fila['sucursal']->id }}">
                                            <td>{{ $fila['sucursal']->nombre }}</td>
                                            <td><b>{{ Decimal::formatear($fila['stock']) }}</b></td>
                                            <td>
                                                <input class="input rapid-numeric" type="text" inputmode="decimal" placeholder="{{ __('General') }}" aria-label="{{ __('Mínimo en :nombre', ['nombre' => $fila['sucursal']->nombre]) }}" wire:model="minimos.{{ $fila['sucursal']->id }}">
                                                @error('minimos.'.$fila['sucursal']->id)<p class="auth-error">{{ $message }}</p>@enderror
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="3"><div class="empty">{{ __('No hay sucursales activas.') }}</div></td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endif
            </div>

            <aside class="rapid-aside">
                <section class="card card-pad summary-sticky">
                    <div class="heading">{{ __('Buenas prácticas') }}</div>
                    <ul class="product-guide">
                        <li>{{ __('Una sola ficha de producto, compartida entre sucursales.') }}</li>
                        <li>{{ __('El inventario se actualiza con ajustes y traspasos; guardar la ficha no cambia el stock.') }}</li>
                        <li>{{ __('La foto se guarda de forma privada y solo la ve tu empresa.') }}</li>
                        <li>{{ __('Las alertas de stock mínimo pueden ajustarse por sucursal.') }}</li>
                    </ul>
                    <div class="rapid-final-actions">
                        <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="guardar,imagen"><x-icon name="check" /> {{ __('Guardar producto') }}</button>
                        <a class="btn ghost" href="{{ route('productos.index') }}">{{ __('Cancelar') }}</a>
                    </div>
                </section>
            </aside>
        </div>
    </form>
</div>
