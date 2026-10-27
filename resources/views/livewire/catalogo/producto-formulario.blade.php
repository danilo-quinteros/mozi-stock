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
                    <div class="product-details">
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
                            <x-campo etiqueta="Nombre del producto *" campo="nombre">
                                <input id="nombre" class="input" type="text" maxlength="150" wire:model="nombre" placeholder="{{ __('Ej. Coca-Cola 2L') }}" required autofocus>
                            </x-campo>
                            <x-campo etiqueta="Código / SKU *" campo="sku">
                                <input id="sku" class="input" type="text" maxlength="40" wire:model="sku" placeholder="{{ __('Ej. BEB-001') }}" required>
                            </x-campo>
                            <x-campo etiqueta="Código de barras (opcional)" campo="codigo_barras">
                                <input id="codigo_barras" class="input" type="text" maxlength="64" wire:model="codigo_barras" placeholder="{{ __('Escanéalo si tienes lector') }}" wire:keydown.enter.prevent>
                            </x-campo>
                            <x-campo etiqueta="Categoría" campo="categoria_id">
                                <select id="categoria_id" class="select" wire:model="categoria_id">
                                    <option value="">{{ __('Sin categoría') }}</option>
                                    @foreach ($categorias as $opcion)
                                        <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                                    @endforeach
                                </select>
                            </x-campo>
                            <x-campo etiqueta="Unidad y medida" campo="unidad_medida_id">
                                <select id="unidad_medida_id" class="select" wire:model="unidad_medida_id" required>
                                    @foreach ($unidades as $opcion)
                                        <option value="{{ $opcion->id }}">{{ $opcion->nombre }} ({{ $opcion->abreviatura }})</option>
                                    @endforeach
                                </select>
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
