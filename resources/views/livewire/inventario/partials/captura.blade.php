@use('App\Support\Decimal')
@use('App\Domain\Inventario\DatosLote')
@use('Illuminate\Support\Js')
{{-- Captura rápida compartida. Variables: $filas, $sugerencias, $seleccionadas, $mensajeCaptura y, según el modo,
     $etiquetaStock y $etiquetaDespues (ajustes y traspasos) o $modo = 'compra' (cantidad, costo unitario y subtotal).
     Opcional: $reservados (producto_id => cantidad Por despachar en la sucursal, Despachos § 5.4).
     En compras, cada fila trae 'clave' (uid de la línea) y, en productos con vencimiento, 'lote' = true con su
     número y vencimiento (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 4). --}}
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
                    @php($clave = (string) ($fila['clave'] ?? $producto->id))
                    <tr data-sku="{{ $producto->sku }}" wire:key="linea-{{ $clave }}" @class(['rapid-selected' => in_array($clave, array_map('strval', $seleccionadas), true)])>
                        <td data-label="{{ __('Seleccionar') }}"><input type="checkbox" class="row-checkbox" value="{{ $clave }}" wire:model.live="seleccionadas" aria-label="{{ __('Seleccionar :nombre', ['nombre' => $producto->nombre]) }}"></td>
                        <td data-label="{{ __('Producto') }}"><div class="rapid-product"><span class="product-dot" aria-hidden="true"><x-icon name="box" size="sm" /></span><div><b>{{ $producto->nombre }}</b><small>@if ($producto->marca){{ $producto->marca }} · @endif{{ $producto->unidad->abreviatura }}@if ($producto->categoria) · {{ $producto->categoria->nombre }}@endif</small></div></div>
                            @if (($fila['lote'] ?? false) && ($fila['loteModo'] ?? 'ingreso') === 'salida')
                                {{-- Salida o ajuste que resta (§ 5.4): automático (primero lo vencido) o un lote concreto, también vencido. --}}
                                <div class="lote-campos" data-test="lote-selector">
                                    <label class="lote-campo lote-campo-ancho">
                                        <span>{{ __('Lote') }}</span>
                                        <select class="select" wire:model.live="lineas.{{ $fila['indice'] }}.lote_id" aria-label="{{ __('Lote de :nombre', ['nombre' => $producto->nombre]) }}">
                                            <option value="">{{ __('Automático (vence primero)') }}</option>
                                            @foreach ($fila['lotes'] ?? [] as $opcion)
                                                <option value="{{ $opcion['id'] }}">{{ \App\Domain\Inventario\Consultas\StockLotes::etiqueta($opcion) }} · {{ Decimal::formatear($opcion['cantidad']) }}@if ($opcion['vencido']) · {{ __('vencido') }}@endif</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <button class="btn ghost sm lote-otro" type="button" wire:click="otroLote('{{ $clave }}')" title="{{ __('Agregar otra línea de este producto con otro lote') }}"><x-icon name="plus" size="sm" /> {{ __('Otro lote') }}</button>
                                </div>
                            @elseif ($fila['lote'] ?? false)
                                <div class="lote-campos" data-test="lote-campos">
                                    <label class="lote-campo">
                                        <span>{{ __('N.º de lote') }}</span>
                                        <input class="input" type="text" maxlength="40" autocomplete="off" aria-label="{{ __('Número de lote de :nombre', ['nombre' => $producto->nombre]) }}"
                                            wire:model.blur="lineas.{{ $fila['indice'] }}.lote" @error('lineas.'.$fila['indice'].'.lote') aria-invalid="true" @enderror>
                                        @error('lineas.'.$fila['indice'].'.lote')<small class="danger-text">{{ $message }}</small>@enderror
                                    </label>
                                    <label class="lote-campo">
                                        {{-- «Vencimiento» ya se traduce como fecha de pago; para el lote se usa la clave Expiry (es.json). --}}
                                        <span>{{ __('Expiry') }}</span>
                                        <input class="input" type="text" inputmode="numeric" placeholder="{{ __('mm/aaaa') }}" autocomplete="off" aria-label="{{ __('Vencimiento del lote de :nombre', ['nombre' => $producto->nombre]) }}"
                                            wire:model.live.debounce.500ms="lineas.{{ $fila['indice'] }}.vencimiento" @error('lineas.'.$fila['indice'].'.vencimiento') aria-invalid="true" @enderror>
                                        @error('lineas.'.$fila['indice'].'.vencimiento')
                                            <small class="danger-text">{{ $message }}</small>
                                        @else
                                            @if ($fila['vence'])<small @class(['danger-text' => $fila['vencido'], 'subtle' => ! $fila['vencido']])>{{ $fila['vencido'] ? __('Vencido el :fecha: no se podrá vender.', ['fecha' => DatosLote::formatear($fila['vence'])]) : __('Vence el :fecha', ['fecha' => DatosLote::formatear($fila['vence'])]) }}</small>@endif
                                        @enderror
                                    </label>
                                    <button class="btn ghost sm lote-otro" type="button" wire:click="otroLote('{{ $clave }}')" title="{{ __('Agregar otra línea de este producto con otro lote') }}"><x-icon name="plus" size="sm" /> {{ __('Otro lote') }}</button>
                                </div>
                            @endif
                        </td>
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
                            <td data-label="{{ __($etiquetaStock) }}">{{ Decimal::formatear($fila['actual']) }}@isset($reservados[$producto->id])<small class="accent-text" data-reservado>{{ __('Reservado por despachar: :cantidad', ['cantidad' => Decimal::formatear($reservados[$producto->id])]) }}</small>@endisset
                                @isset($vencidas[$producto->id])<small class="danger-text" data-vencidas>{{ __($notaVencidas ?? 'Incluye :cantidad vencidas.', ['cantidad' => Decimal::formatear($vencidas[$producto->id])]) }}</small>@endisset</td>
                            <td data-label="{{ __('Cantidad') }}">
                                <input class="input rapid-numeric" type="text" inputmode="decimal" aria-label="{{ __('Cantidad de :nombre', ['nombre' => $producto->nombre]) }}"
                                    wire:model.live.debounce.400ms="lineas.{{ $fila['indice'] }}.cantidad">
                            </td>
                            <td data-label="{{ __($etiquetaDespues) }}" @class(['danger-text' => $fila['despues'] === null || Decimal::comparar($fila['despues'], '0') < 0])>
                                {{ $fila['despues'] === null ? __('Cantidad inválida') : Decimal::formatear($fila['despues']) }}
                            </td>
                        @endif
                        <td data-label="{{ __('Quitar') }}"><button class="icon-action" type="button" wire:click="quitarLinea({{ Js::from($clave) }})" aria-label="{{ __('Quitar :nombre', ['nombre' => $producto->nombre]) }}" title="{{ __('Quitar') }}"><x-icon name="x" size="sm" /></button></td>
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
    @include('livewire.catalogo.partials.selector-variante')
</section>
