@use('App\Support\Decimal')
@use('App\Domain\Catalogo\Variantes')
{{-- Agrupar en modelo (ESPECIFICACION_TALLAS_Y_COLORES.md § 9): página completa, no modal. Elegir modelo, confirmar
     talla y color de cada producto, revisar la vista previa y confirmar. Todo se valida de nuevo en AgruparEnModelo. --}}
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Catálogo') }}</div>
            <h1>{{ __('Agrupar en modelo') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Los productos conservan su stock en todas las sucursales, su historial, su SKU y su código de barras. Solo se les asigna modelo, talla y color.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('productos.index') }}"><x-icon name="box" /> {{ __('Productos') }}</a>
        </div>
    </div>

    <div class="stack">
        <section class="card card-pad" data-test="agrupar-destino">
            <div class="card-head"><div><div class="heading">{{ __('1. Modelo') }}</div>
                <p class="subtle" style="margin:9px 0 0">{{ __('Elige un modelo que ya existe o crea uno nuevo. Para el nuevo se proponen los datos del primer producto marcado.') }}</p></div></div>
            <div class="row" role="radiogroup" aria-label="{{ __('Modelo') }}" style="gap:8px;flex-wrap:wrap;margin-bottom:16px">
                <label class="variante-opcion"><input type="radio" value="nuevo" wire:model.live="destino"><span>{{ __('Crear un modelo nuevo') }}</span></label>
                <label class="variante-opcion"><input type="radio" value="existente" wire:model.live="destino"><span>{{ __('Usar un modelo existente') }}</span></label>
            </div>

            @if ($destino === 'existente')
                <x-campo etiqueta="Modelo *" campo="modelo_id" style="max-width:420px">
                    <select id="modelo_id" class="select" wire:model.live="modelo_id">
                        <option value="">{{ __('Elige un modelo') }}</option>
                        @foreach ($modelos as $opcion)
                            <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                        @endforeach
                    </select>
                </x-campo>
                @if ($modelos->isEmpty())
                    <p class="subtle" style="margin:8px 0 0">{{ __('No hay modelos activos con la unidad de medida de estos productos.') }}</p>
                @endif
                @if ($modeloElegido)
                    <p class="subtle" style="margin:10px 0 0">{{ __('Precio del modelo: :precio', ['precio' => Decimal::bs((string) $modeloElegido->precio_consumidor)]) }}
                        · {{ $nombresCategorias[$modeloElegido->categoria_id] ?? __('Sin categoría') }}
                        · <a href="{{ route('productos.modelos.ver', $modeloElegido) }}" target="_blank" rel="noopener">{{ __('Ver modelo') }}</a></p>
                @endif
            @else
                <div class="fields">
                    <x-campo etiqueta="Nombre del modelo *" campo="nombre">
                        <input id="nombre" class="input" type="text" maxlength="{{ Variantes::MAX_NOMBRE_MODELO }}" wire:model.blur="nombre" required>
                    </x-campo>
                    <x-campo etiqueta="SKU base (opcional)" campo="sku_base">
                        <input id="sku_base" class="input" type="text" maxlength="{{ Variantes::MAX_SKU_BASE }}" wire:model.blur="sku_base" placeholder="{{ __('Ej. POL001') }}">
                    </x-campo>
                    <x-campo etiqueta="Categoría" campo="categoria_id">
                        <select id="categoria_id" class="select" wire:model.live="categoria_id">
                            <option value="">{{ __('Sin categoría') }}</option>
                            @foreach ($categorias as $opcion)
                                <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                            @endforeach
                        </select>
                    </x-campo>
                    <div class="field">
                        <span class="field-label">{{ __('Unidad de medida') }}</span>
                        <p style="margin:10px 0 0"><b>{{ $unidad ? $unidad->nombre.' ('.$unidad->abreviatura.')' : '—' }}</b> <small class="subtle">{{ __('la de los productos') }}</small></p>
                    </div>
                    <x-campo etiqueta="Stock mínimo sugerido" campo="stock_minimo">
                        <input id="stock_minimo" class="input" type="text" inputmode="decimal" wire:model.blur="stock_minimo">
                    </x-campo>
                    <x-campo etiqueta="Grupo de tallas" campo="grupo_talla_id">
                        <select id="grupo_talla_id" class="select" wire:model.live="grupo_talla_id">
                            <option value="">{{ __('Sin talla') }}</option>
                            @foreach ($grupos as $grupo)
                                <option value="{{ $grupo->id }}">{{ $grupo->nombre }}</option>
                            @endforeach
                        </select>
                    </x-campo>
                </div>
                <div class="fields three product-prices-grid" style="margin-top:14px">
                    <x-campo etiqueta="Consumidor (Bs) *" campo="precio_consumidor">
                        <input id="precio_consumidor" class="input" type="text" inputmode="decimal" wire:model.blur="precio_consumidor" required>
                    </x-campo>
                    <x-campo etiqueta="Minorista (Bs)" campo="precio_minorista">
                        <input id="precio_minorista" class="input" type="text" inputmode="decimal" wire:model.blur="precio_minorista">
                    </x-campo>
                    <x-campo etiqueta="Mayorista (Bs)" campo="precio_mayorista">
                        <input id="precio_mayorista" class="input" type="text" inputmode="decimal" wire:model.blur="precio_mayorista">
                    </x-campo>
                </div>
                <p class="subtle" style="margin:10px 0 0">{{ __('Un producto con precios distintos a estos queda con precio propio y el mismo importe: ningún precio cambia en silencio.') }}</p>
            @endif
        </section>

        <section class="card card-pad" data-test="agrupar-productos">
            <div class="card-head"><div><div class="heading">{{ __('2. Talla y color de cada producto') }}</div>
                <p class="subtle" style="margin:9px 0 0">{{ __('Se proponen a partir del nombre. Revisa cada fila: no puede haber dos productos con la misma talla y color.') }}</p></div></div>
            @if ($unidadesMezcladas)
                <p class="danger-text" role="alert" style="margin:0 0 12px">{{ __('Los productos marcados tienen distintas unidades de medida: todos deben usar la del modelo.') }}</p>
            @endif
            @error('productos')<p class="danger-text" role="alert" data-test="error-productos" style="margin:0 0 12px">{{ $message }}</p>@enderror
            <div class="table-wrap variantes-tabla">
                <table>
                    <thead><tr><th>{{ __('Producto') }}</th><th>{{ __('Stock total') }}</th><th>{{ __('Precio') }}</th><th>{{ __('Talla') }}</th><th>{{ __('Color') }}</th><th><span class="sr-only">{{ __('Quitar') }}</span></th></tr></thead>
                    <tbody>
                        @foreach ($productos as $producto)
                            <tr wire:key="agrupar-{{ $producto->id }}" data-test="agrupar-fila-{{ $producto->id }}">
                                <td data-label="{{ __('Producto') }}">
                                    <span class="variante-nombre"><b>{{ $producto->nombre }}</b>
                                        <small>{{ $producto->sku ?? '—' }} · {{ $producto->categoria->nombre ?? __('Sin categoría') }}@unless ($producto->activo) {{ __('· Inactivo') }}@endunless</small></span>
                                    @error('asignaciones.'.$producto->id)<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                                </td>
                                <td data-label="{{ __('Stock total') }}">{{ Decimal::formatear($stock[$producto->id] ?? '0') }} {{ $producto->unidad->abreviatura ?? '' }}</td>
                                <td data-label="{{ __('Precio') }}">{{ Decimal::bs((string) $producto->precio_consumidor) }}</td>
                                <td data-label="{{ __('Talla') }}">
                                    <select class="select" wire:model.live="asignaciones.{{ $producto->id }}.talla_id" aria-label="{{ __('Talla de :producto', ['producto' => $producto->nombre]) }}" @disabled($tallas->isEmpty())>
                                        <option value="">{{ __('Sin talla') }}</option>
                                        @foreach ($tallas as $talla)
                                            <option value="{{ $talla->id }}">{{ $talla->nombre }}</option>
                                        @endforeach
                                    </select>
                                    @error('asignaciones.'.$producto->id.'.talla_id')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                                </td>
                                <td data-label="{{ __('Color') }}">
                                    <select class="select" wire:model.live="asignaciones.{{ $producto->id }}.color_id" aria-label="{{ __('Color de :producto', ['producto' => $producto->nombre]) }}">
                                        <option value="">{{ __('Sin color') }}</option>
                                        @foreach ($colores as $color)
                                            <option value="{{ $color->id }}">{{ $color->nombre }}</option>
                                        @endforeach
                                    </select>
                                    @error('asignaciones.'.$producto->id.'.color_id')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                                </td>
                                <td>
                                    @if (count($productoIds) > 1)
                                        <button class="btn sm ghost" type="button" wire:click="quitarProducto({{ $producto->id }})"><x-icon name="x" size="sm" /><span class="sr-only">{{ __('Quitar :nombre de la lista', ['nombre' => $producto->nombre]) }}</span></button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card card-pad" data-test="agrupar-previa">
            <div class="card-head"><div><div class="heading">{{ __('3. Vista previa') }}</div>
                <p class="subtle" style="margin:9px 0 0">{{ __('Qué cambia en cada producto. Las ventas pasadas conservan el nombre anterior.') }}</p></div></div>
            @if ($previa === [])
                <p class="subtle" style="margin:0">{{ __('Pulsa «Revisar cambios» para ver la vista previa antes de confirmar.') }}</p>
            @else
                <div class="table-wrap variantes-tabla">
                    <table>
                        <thead><tr><th>{{ __('Nombre actual') }}</th><th>{{ __('Nombre nuevo') }}</th><th>{{ __('Categoría') }}</th><th>{{ __('Precio') }}</th></tr></thead>
                        <tbody>
                            @foreach ($previa as $fila)
                                <tr wire:key="previa-{{ $fila['id'] }}" data-test="previa-{{ $fila['id'] }}">
                                    <td data-label="{{ __('Nombre actual') }}">{{ $fila['nombre_antes'] }}</td>
                                    <td data-label="{{ __('Nombre nuevo') }}"><b>{{ $fila['nombre_visible'] }}</b></td>
                                    <td data-label="{{ __('Categoría') }}">
                                        @if ($fila['categoria_antes'] === $fila['categoria_despues'])
                                            {{ $nombresCategorias[$fila['categoria_despues']] ?? __('Sin categoría') }}
                                        @else
                                            <s class="subtle">{{ $nombresCategorias[$fila['categoria_antes']] ?? __('Sin categoría') }}</s> → {{ $nombresCategorias[$fila['categoria_despues']] ?? __('Sin categoría') }}
                                        @endif
                                    </td>
                                    <td data-label="{{ __('Precio') }}">
                                        {{ Decimal::bs($fila['precio_consumidor']) }}
                                        <span class="badge {{ $fila['precio_propio'] ? 'warning' : '' }}" data-test="previa-precio-{{ $fila['precio_propio'] ? 'propio' : 'modelo' }}">{{ $fila['precio_propio'] ? __('Propio') : __('Del modelo') }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="product-form-actions">
                <a class="btn ghost" href="{{ route('productos.index') }}">{{ __('Cancelar') }}</a>
                <button class="btn ghost" type="button" wire:click="revisar" wire:loading.attr="disabled" wire:target="revisar" data-test="revisar-agrupar"><x-icon name="search" /> {{ __('Revisar cambios') }}</button>
                <button class="btn primary" type="button" wire:click="confirmar" wire:loading.attr="disabled" wire:target="confirmar" @disabled($previa === []) data-test="confirmar-agrupar">
                    <x-icon name="check" /> {{ __('Confirmar y agrupar') }}
                </button>
            </div>
        </section>
    </div>
</div>
