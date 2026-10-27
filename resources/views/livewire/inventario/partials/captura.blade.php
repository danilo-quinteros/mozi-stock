@use('App\Support\Decimal')
{{-- Captura rápida compartida. Variables: $filas, $sugerencias, $seleccionadas, $mensajeCaptura y, según el modo,
     $etiquetaStock y $etiquetaDespues (ajustes y traspasos) o $modo = 'compra' (cantidad, costo unitario y subtotal). --}}
@php($esCompra = ($modo ?? 'stock') === 'compra')
<section class="card rapid-main">
    <div class="rapid-title"><h2>{{ __('Agregar productos') }}</h2><span class="rapid-kbd">{{ __('F2 · Buscar') }}</span></div>
    @include('livewire.inventario.partials.buscador', ['sugerencias' => $sugerencias])

    <div class="rapid-table-bar">
        <div><b>{{ count($filas) }} {{ count($filas) === 1 ? 'producto' : 'productos' }}</b></div>
        <div class="row">
            <button class="btn ghost sm" type="button" wire:click="seleccionarTodas" @disabled($filas === [])>{{ __('Seleccionar todos') }}</button>
            <button class="btn ghost sm" type="button" wire:click="quitarSeleccionadas" @disabled($seleccionadas === [])>{{ __('Eliminar seleccionados') }}</button>
        </div>
    </div>
    <div class="table-wrap rapid-table-wrap">
        <table class="rapid-table">
            <thead><tr><th><span class="sr-only">{{ __('Seleccionar') }}</span></th><th>{{ __('Producto') }}</th><th>{{ __('Código') }}</th>
                @if ($esCompra)<th>{{ __('Cantidad') }}</th><th>{{ __('Costo unitario') }}</th><th>{{ __('Subtotal') }}</th>@else<th>{{ __($etiquetaStock) }}</th><th>{{ __('Cantidad') }}</th><th>{{ __($etiquetaDespues) }}</th>@endif
                <th><span class="sr-only">{{ __('Quitar') }}</span></th></tr></thead>
            <tbody>
                @forelse ($filas as $fila)
                    @php($producto = $fila['producto'])
                    <tr data-sku="{{ $producto->sku }}" wire:key="linea-{{ $producto->id }}" @class(['rapid-selected' => in_array((string) $producto->id, array_map('strval', $seleccionadas), true)])>
                        <td data-label="{{ __('Seleccionar') }}"><input type="checkbox" class="row-checkbox" value="{{ $producto->id }}" wire:model.live="seleccionadas" aria-label="{{ __('Seleccionar :nombre', ['nombre' => $producto->nombre]) }}"></td>
                        <td data-label="{{ __('Producto') }}"><div class="rapid-product"><span class="product-dot" aria-hidden="true"><x-icon name="box" size="sm" /></span><div><b>{{ $producto->nombre }}</b><small>{{ $producto->unidad->abreviatura }}@if ($producto->categoria) · {{ $producto->categoria->nombre }}@endif</small></div></div></td>
                        <td class="sku-cell" data-label="{{ __('Código') }}">{{ $producto->sku }}</td>
                        @if ($esCompra)
                            <td data-label="{{ __('Cantidad') }}">
                                <input class="input rapid-numeric" type="text" inputmode="decimal" aria-label="{{ __('Cantidad de :nombre', ['nombre' => $producto->nombre]) }}"
                                    wire:model.live.debounce.400ms="lineas.{{ $fila['indice'] }}.cantidad">
                            </td>
                            <td data-label="{{ __('Costo unitario') }}">
                                <input class="input rapid-numeric" type="text" inputmode="decimal" placeholder="0,00" aria-label="{{ __('Costo unitario de :nombre', ['nombre' => $producto->nombre]) }}"
                                    wire:model.live.debounce.400ms="lineas.{{ $fila['indice'] }}.costo">
                            </td>
                            <td data-label="{{ __('Subtotal') }}" @class(['danger-text' => $fila['subtotal'] === null])>
                                {{ $fila['subtotal'] === null ? __('Revisa cantidad y costo') : Decimal::bs($fila['subtotal']) }}
                            </td>
                        @else
                            <td data-label="{{ __($etiquetaStock) }}">{{ Decimal::formatear($fila['actual']) }}</td>
                            <td data-label="{{ __('Cantidad') }}">
                                <input class="input rapid-numeric" type="text" inputmode="decimal" aria-label="{{ __('Cantidad de :nombre', ['nombre' => $producto->nombre]) }}"
                                    wire:model.live.debounce.400ms="lineas.{{ $fila['indice'] }}.cantidad">
                            </td>
                            <td data-label="{{ __($etiquetaDespues) }}" @class(['danger-text' => $fila['despues'] === null || Decimal::comparar($fila['despues'], '0') < 0])>
                                {{ $fila['despues'] === null ? __('Cantidad inválida') : Decimal::formatear($fila['despues']) }}
                            </td>
                        @endif
                        <td data-label="{{ __('Quitar') }}"><button class="icon-action" type="button" wire:click="quitarLinea({{ $producto->id }})" aria-label="{{ __('Quitar :nombre', ['nombre' => $producto->nombre]) }}" title="{{ __('Quitar') }}"><x-icon name="x" size="sm" /></button></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="rapid-empty"><span aria-hidden="true"><x-icon name="scan" size="lg" /></span><b>{{ __('Tu lista está lista para llenarse') }}</b><p>{{ __('Busca un producto o escanea su código y continúa con el siguiente.') }}</p></div></td></tr>
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
