<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Catálogo') }}</div>
            <h1>{{ $modeloId ? __('Editar modelo') : __('Nuevo modelo') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Un modelo agrupa sus tallas y colores. Cada combinación es un producto con su propio stock; el stock entra por Compras o Ajustes.') }}</p>
        </div>
        @if ($modeloId)
            <div class="page-actions">
                <a class="btn ghost" href="{{ route('productos.modelos.ver', $modeloId) }}"><x-icon name="arrow" /> {{ __('Ver modelo') }}</a>
            </div>
        @endif
    </div>

    <form wire:submit="guardar">
        <div class="product-form-container product-layout">
            <div class="stack">
                <section class="card card-pad">
                    <div class="card-head"><div><div class="heading">{{ __('Datos del modelo') }}</div><p class="subtle" style="margin:9px 0 0">{{ __('Las combinaciones comparten categoría, unidad y, salvo que tengan precio propio, los precios del modelo.') }}</p></div></div>
                    <div class="product-details">
                        <div class="product-upload">
                            <label class="upload-box" for="imagen">
                                @if ($imagen && $imagen->isPreviewable())
                                    <img src="{{ $imagen->temporaryUrl() }}" alt="{{ __('Vista previa de la imagen') }}" class="upload-preview-img">
                                @elseif ($modelo?->imagen_path && ! $quitarImagen)
                                    <img src="{{ route('productos.modelos.imagen', $modelo) }}" alt="{{ __('Foto actual del modelo') }}" class="upload-preview-img">
                                @else
                                    <x-icon name="image" size="lg" style="width:32px;height:32px;color:var(--brand-bright)" />
                                @endif
                                <b>{{ __('Foto general del modelo') }}</b>
                                <small wire:loading.remove wire:target="imagen">{{ __('Haz clic para elegir una imagen') }}</small>
                                <small wire:loading wire:target="imagen">{{ __('Subiendo…') }}</small>
                                <small>{{ __('JPG, PNG o WebP · máx. 3 MB') }}</small>
                            </label>
                            <input id="imagen" type="file" accept="image/png,image/jpeg,image/webp" wire:model="imagen" hidden>
                            @error('imagen')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                            @if ($imagen || ($modelo?->imagen_path && ! $quitarImagen))
                                <button class="btn ghost sm" type="button" wire:click="quitarFoto">{{ __('Quitar imagen') }}</button>
                            @endif
                        </div>
                        <div class="fields">
                            <x-campo etiqueta="Nombre del modelo *" campo="nombre">
                                <input id="nombre" class="input" type="text" maxlength="{{ \App\Domain\Catalogo\Variantes::MAX_NOMBRE_MODELO }}" wire:model="nombre" placeholder="{{ __('Ej. Polera Básica') }}" required autofocus>
                            </x-campo>
                            <x-campo etiqueta="SKU base (opcional)" campo="sku_base">
                                <input id="sku_base" class="input" type="text" maxlength="{{ \App\Domain\Catalogo\Variantes::MAX_SKU_BASE }}" wire:model.live.debounce.400ms="sku_base" placeholder="{{ __('Ej. POL001') }}">
                            </x-campo>
                            <x-campo etiqueta="Categoría" campo="categoria_id">
                                <select id="categoria_id" class="select" wire:model="categoria_id">
                                    <option value="">{{ __('Sin categoría') }}</option>
                                    @foreach ($categorias as $opcion)
                                        <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                                    @endforeach
                                </select>
                            </x-campo>
                            <x-campo etiqueta="Unidad de medida *" campo="unidad_medida_id">
                                <select id="unidad_medida_id" class="select" wire:model="unidad_medida_id" required>
                                    @foreach ($unidades as $opcion)
                                        <option value="{{ $opcion->id }}">{{ $opcion->nombre }} ({{ $opcion->abreviatura }})</option>
                                    @endforeach
                                </select>
                            </x-campo>
                            <x-campo etiqueta="Stock mínimo sugerido" campo="stock_minimo">
                                <input id="stock_minimo" class="input" type="text" inputmode="decimal" wire:model="stock_minimo">
                            </x-campo>
                        </div>
                    </div>
                    <div class="divider"></div>
                    <div class="heading">{{ __('Precios del modelo') }}</div>
                    <p class="subtle" style="margin:8px 0 15px">{{ __('Las combinaciones sin precio propio usan estos precios y cambian con ellos.') }}</p>
                    <div class="fields three product-prices-grid">
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
                        <textarea id="descripcion" class="textarea" maxlength="2000" wire:model="descripcion" placeholder="{{ __('Tela, corte o notas del modelo') }}"></textarea>
                    </x-campo>
                    @if ($modeloId)
                        <label class="row" style="margin-top:16px"><input type="checkbox" wire:model="activo"><span>{{ __('Modelo activo (al desactivarlo se desactivan todas sus combinaciones)') }}</span></label>
                    @endif
                </section>

                @include('livewire.catalogo.partials.combinaciones', ['editando' => (bool) $modeloId, 'conSkuBase' => false, 'cancelarUrl' => $modeloId ? route('productos.modelos.ver', $modeloId) : route('productos.index'), 'textoGuardar' => __('Guardar modelo')])
            </div>
        </div>
    </form>
</div>
