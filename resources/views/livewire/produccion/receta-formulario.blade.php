@use('Illuminate\Support\Js')
{{-- Nueva receta o versión nueva (ESPECIFICACION_PRODUCCION.md § 5). Las cantidades son para la cantidad base del
     producto, cada una en la unidad de su materia prima. --}}
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Producción') }}</div>
            <h1>{{ $versionEditada ? __('Editar receta') : __('Nueva receta') }}<span class="accent-text">.</span></h1>
            <p class="lead">
                @if ($versionEditada)
                    {{ __('Al guardar se crea la versión :siguiente. La versión :actual queda en el historial y las órdenes ya emitidas conservan sus ingredientes.', ['siguiente' => $versionEditada + 1, 'actual' => $versionEditada]) }}
                @else
                    {{ __('Elige el producto que fabricas, la cantidad base y las materias primas que lleva esa cantidad.') }}
                @endif
            </p>
        </div>
    </div>

    <form wire:submit="guardar">
        <div class="stack" style="max-width:960px">
            @error('receta')
                <div class="notice caja-alerta" role="alert" data-test="receta-error" data-error-validacion><x-icon name="status-alert" /><div><b>{{ __('No se guardó') }}</b><p>{{ $message }}</p></div></div>
            @enderror

            <section class="card card-pad" data-test="receta-producto">
                <div class="card-head"><div><div class="heading">{{ __('Producto fabricado') }}</div><p class="subtle" style="margin:9px 0 0">{{ __('Debe existir en Productos, ser de venta, estar activo y controlar stock.') }}</p></div></div>

                @if ($producto)
                    <div class="list-line" data-test="receta-producto-elegido">
                        <span><b>{{ $producto->nombre }}</b>@if ($producto->sku) · {{ $producto->sku }}@endif · {{ $producto->unidad->abreviatura }}</span>
                        @unless ($versionEditada)
                            <button class="btn ghost sm" type="button" wire:click="cambiarProducto">{{ __('Cambiar') }}</button>
                        @endunless
                    </div>
                @else
                    <div class="search" style="position:relative">
                        <x-icon name="search" />
                        <input class="input" type="search" autocomplete="off" wire:model.live.debounce.300ms="buscarProducto" placeholder="{{ __('Buscar producto por nombre o código') }}" aria-label="{{ __('Buscar el producto fabricado') }}" data-test="receta-buscar-producto">
                    </div>
                    @if ($this->productosSugeridos->isNotEmpty())
                        <div class="suggestions" role="listbox" aria-label="{{ __('Productos encontrados') }}" style="position:static;margin-top:8px">
                            @foreach ($this->productosSugeridos as $sugerido)
                                <button type="button" class="suggestion" role="option" wire:key="fabricable-{{ $sugerido->id }}" wire:click="elegirProducto({{ $sugerido->id }})">
                                    <span class="suggestion-product"><b>{{ $sugerido->nombre }}</b><small>{{ implode(' · ', array_filter([$sugerido->marca, $sugerido->sku, $sugerido->unidad->abreviatura])) }}</small></span>
                                    <span class="kbd-quiet">{{ __('Elegir') }}</span>
                                </button>
                            @endforeach
                        </div>
                    @elseif (mb_strlen(trim($buscarProducto)) >= 2)
                        <p class="subtle" style="margin:8px 0 0" data-test="receta-sin-productos">{{ __('No hay productos para fabricar con ese nombre, o ya tienen receta. Créalo en Productos o busca su receta en la lista.') }}</p>
                    @endif
                @endif
                @error('producto_id')<p class="auth-error" role="alert">{{ $message }}</p>@enderror

                <div class="fields" style="margin-top:14px">
                    <x-campo etiqueta="Cantidad base *" campo="cantidad_base">
                        <div class="row" style="gap:8px;align-items:center">
                            <input id="cantidad_base" class="input" type="text" inputmode="decimal" wire:model="cantidad_base" placeholder="{{ __('Ej. 100') }}" data-test="receta-cantidad-base">
                            <span class="subtle">{{ $producto?->unidad->abreviatura }}</span>
                        </div>
                    </x-campo>
                    <x-campo etiqueta="Nota" campo="nota">
                        <input id="nota" class="input" type="text" maxlength="250" wire:model="nota" placeholder="{{ __('Opcional: procedimiento, tiempos, cuidados') }}">
                    </x-campo>
                </div>
                <p class="subtle" style="margin:6px 0 0">{{ __('La cantidad que produce esta receta, por ejemplo 100 L o 20 kg. Las órdenes la escalan: para 50 L se usa la mitad de cada materia prima.') }}</p>
            </section>

            <section class="card rapid-main" data-test="receta-materias">
                <div class="rapid-title"><h2>{{ __('Materias primas') }}</h2><span class="rapid-kbd">{{ __('F2 · Buscar') }}</span></div>
                @include('livewire.inventario.partials.buscador', [
                    'sugerencias' => $sugerencias,
                    'placeholder' => 'Buscar materia prima por nombre o código, o escanear',
                    'vacioTitulo' => 'Materia prima no encontrada',
                    'vacioAyuda' => 'Revisa el nombre o el código, o créala en Producción › Materias primas.',
                    'botonAgregar' => 'Agregar',
                ])
                <div class="table-wrap rapid-table-wrap">
                    <table class="rapid-table">
                        <thead><tr><th>{{ __('Materia prima') }}</th><th>{{ __('Código') }}</th><th>{{ __('Cantidad para la base') }}</th><th><span class="sr-only">{{ __('Quitar') }}</span></th></tr></thead>
                        <tbody>
                            @forelse ($filas as $fila)
                                @php($materia = $fila['materia'])
                                <tr wire:key="ingrediente-{{ $materia->id }}">
                                    <td data-label="{{ __('Materia prima') }}"><div class="rapid-product"><span class="product-dot" aria-hidden="true"><x-icon name="box" size="sm" /></span><div><b>{{ $materia->nombre }}</b><small>{{ $materia->unidad->abreviatura }}</small></div></div>
                                        @error('lineas.'.$fila['indice'].'.producto_id')<small class="danger-text">{{ $message }}</small>@enderror</td>
                                    <td class="sku-cell" data-label="{{ __('Código') }}">{{ $materia->sku }}</td>
                                    <td data-label="{{ __('Cantidad para la base') }}">
                                        <div class="row" style="gap:6px;align-items:center">
                                            <input class="input rapid-numeric" type="text" inputmode="decimal" aria-label="{{ __('Cantidad de :nombre', ['nombre' => $materia->nombre]) }}"
                                                wire:model.blur="lineas.{{ $fila['indice'] }}.cantidad" @error('lineas.'.$fila['indice'].'.cantidad') aria-invalid="true" @enderror>
                                            <span class="subtle">{{ $materia->unidad->abreviatura }}</span>
                                        </div>
                                        @error('lineas.'.$fila['indice'].'.cantidad')<small class="danger-text">{{ $message }}</small>@enderror
                                    </td>
                                    <td data-label="{{ __('Quitar') }}"><button class="icon-action" type="button" wire:click="quitarLinea({{ Js::from($materia->id) }})" aria-label="{{ __('Quitar :nombre', ['nombre' => $materia->nombre]) }}" title="{{ __('Quitar') }}"><x-icon name="x" size="sm" /></button></td>
                                </tr>
                            @empty
                                <tr class="rapid-empty-row"><td colspan="4"><div class="rapid-empty"><span aria-hidden="true"><x-icon name="scan" size="lg" /></span><b>{{ __('Todavía no hay materias primas') }}</b><p>{{ __('Busca una materia prima o escanea su código y escribe cuánto lleva la cantidad base.') }}</p></div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="rapid-message" role="status" aria-live="polite">
                    @if ($errors->has('lineas'))
                        @foreach ($errors->get('lineas') as $mensaje)<p class="danger-text" style="margin:0 0 4px">{{ $mensaje }}</p>@endforeach
                    @elseif ($mensajeCaptura)
                        {{ $mensajeCaptura }}
                    @endif
                </div>
            </section>

            <div class="product-form-actions producto-form-final">
                <a class="btn ghost" href="{{ $versionEditada ? route('produccion.recetas.ver', $productoId) : route('produccion.recetas') }}">{{ __('Cancelar') }}</a>
                <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="guardar" data-test="guardar-receta">
                    <x-icon name="check" /> {{ $versionEditada ? __('Guardar versión :version', ['version' => $versionEditada + 1]) : __('Guardar receta') }}
                </button>
            </div>
        </div>
    </form>
</div>
