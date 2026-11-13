@use('App\Support\Decimal')
{{-- Selector de talla y color (ESPECIFICACION_TALLAS_Y_COLORES.md § 5, etapa V2). Lo incluyen las pantallas que usan
     CapturaProductos (concern EligeVariantes): Pedidos y ventas y POS eligen con botones; Compras, Ajustes y Traspasos
     escriben cantidades en la cuadrícula. El stock es informativo: cada acción del servidor lo vuelve a validar. --}}
@php
    $selector = $this->selector;
@endphp
@if ($selector !== null)
    @php
        $modelo = $selector['modelo'];
        $celdas = $selector['celdas'];
        $etiquetaStock = function (array $celda) use ($selector): string {
            if (! $celda['controla_stock']) {
                return __('Sin control');
            }
            if (! $selector['sucursal']) {
                return '';
            }

            return Decimal::comparar($celda['stock'], '0') > 0 ? __(':cantidad disp.', ['cantidad' => Decimal::formatear($celda['stock'])]) : __('Sin stock');
        };
    @endphp
    <x-modal :titulo="$modelo->nombre" cerrar="cerrarSelector" class="selector-variante" data-test="selector-variante"
        :descripcion="$selector['cuadricula']
            ? ($selector['costo'] ? 'Escribe la cantidad de cada combinación y, si quieres, un costo para todas. Después puedes cambiarlo por línea.' : 'Escribe la cantidad de cada combinación. Se agregan a la lista como líneas normales.')
            : 'Elige la combinación. Cada toque agrega una unidad a la venta.'">
        @unless ($selector['sucursal'])
            <p class="notice" style="margin:0 0 14px">{{ __('Elige una sucursal para ver el stock de cada combinación.') }}</p>
        @endunless

        @if ($selector['cuadricula'])
            <form wire:submit="agregarCuadricula" class="selector-cuadricula">
                <div class="table-wrap variantes-cuadricula">
                    <table class="variantes-stock">
                        <thead><tr>
                            <th scope="col"><span class="sr-only">{{ __('Color') }}</span></th>
                            @foreach ($selector['tallas'] as $talla)<th scope="col">{{ $talla['nombre'] }}</th>@endforeach
                        </tr></thead>
                        <tbody>
                            @foreach ($selector['colores'] as $color)
                                <tr wire:key="selector-color-{{ $color['id'] ?? 0 }}">
                                    <th scope="row"><span class="color-nombre"><span class="color-muestra" @if ($color['muestra']) style="background:{{ $color['muestra'] }}" @endif aria-hidden="true"></span>{{ $color['nombre'] }}</span></th>
                                    @foreach ($selector['tallas'] as $talla)
                                        @php
                                            $clave = ($talla['id'] ?? 0).'-'.($color['id'] ?? 0);
                                            $celda = $celdas[$clave] ?? null;
                                        @endphp
                                        @if ($celda === null || ! $celda['controla_stock'])
                                            <td class="selector-celda is-vacia"><span aria-hidden="true">—</span><span class="sr-only">{{ __('No disponible') }}</span></td>
                                        @else
                                            <td class="selector-celda">
                                                <input class="input @error('selectorCantidades.'.$clave) is-error @enderror" type="text" inputmode="decimal" autocomplete="off"
                                                    wire:model="selectorCantidades.{{ $clave }}" placeholder="0"
                                                    aria-label="{{ __('Cantidad de :producto', ['producto' => $celda['nombre']]) }}">
                                                @if ($selector['sucursal'])<small>{{ __('Stock: :cantidad', ['cantidad' => Decimal::formatear($celda['stock'])]) }}</small>@endif
                                                @error('selectorCantidades.'.$clave)<small class="danger-text">{{ $message }}</small>@enderror
                                            </td>
                                        @endif
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($selector['costo'])
                    <label class="field selector-costo">
                        <span class="field-label">{{ __('Costo unitario para todas (Bs)') }}</span>
                        <input class="input @error('selectorCosto') is-error @enderror" type="text" inputmode="decimal" autocomplete="off" wire:model="selectorCosto" placeholder="0,00">
                        @error('selectorCosto')<small class="danger-text">{{ $message }}</small>@enderror
                    </label>
                @endif
                @error('selector')<p class="danger-text" role="alert" style="margin:12px 0 0">{{ $message }}</p>@enderror
                <div class="modal-actions">
                    <button type="button" class="btn ghost" wire:click="cerrarSelector">{{ __('Cancelar') }}</button>
                    <button type="submit" class="btn primary" wire:loading.attr="disabled" wire:target="agregarCuadricula"><x-icon name="plus" size="sm" /> {{ __('Agregar a la lista') }}</button>
                </div>
            </form>
        @else
            @php
                // Con tallas se elige el color y después la talla; sin tallas, cada color es una combinación.
                $porTalla = $selector['conTallas'];
                $botones = $porTalla
                    ? collect($selector['tallas'])->map(fn (array $t): array => ['nombre' => $t['nombre'], 'celda' => $celdas[($t['id'] ?? 0).'-'.$this->selectorColor] ?? null])
                    : collect($selector['colores'])->map(fn (array $c): array => ['nombre' => $c['nombre'], 'celda' => $celdas['0-'.($c['id'] ?? 0)] ?? null]);
            @endphp
            @if ($porTalla && $selector['conColores'])
                <div class="selector-grupo">
                    <span class="field-label">{{ __('Color') }}</span>
                    <div class="selector-opciones" role="group" aria-label="{{ __('Color') }}">
                        @foreach ($selector['colores'] as $color)
                            @php
                                $valor = (string) ($color['id'] ?? 0);
                            @endphp
                            <button type="button" class="talla-chip selector-color {{ $this->selectorColor === $valor ? 'is-elegido' : '' }}" wire:key="selector-elegir-color-{{ $valor }}"
                                aria-pressed="{{ $this->selectorColor === $valor ? 'true' : 'false' }}" wire:click="elegirColorSelector('{{ $valor }}')">
                                <span class="color-muestra" @if ($color['muestra']) style="background:{{ $color['muestra'] }}" @endif aria-hidden="true"></span>{{ $color['nombre'] }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
            <div class="selector-grupo">
                <span class="field-label">{{ $porTalla ? __('Talla') : __('Color') }}</span>
                <div class="selector-variantes" role="group" aria-label="{{ $porTalla ? __('Talla') : __('Color') }}">
                    @foreach ($botones as $boton)
                        @if ($boton['celda'] === null)
                            <span class="selector-variante-btn is-vacia" aria-hidden="true"><b>{{ $boton['nombre'] }}</b><small>—</small></span>
                        @else
                            @php
                                $celda = $boton['celda'];
                            @endphp
                            <button type="button" class="selector-variante-btn" wire:key="selector-variante-{{ $celda['id'] }}" wire:click="elegirVariante({{ $celda['id'] }})"
                                @disabled(! $celda['disponible']) aria-label="{{ __('Agregar :producto', ['producto' => $celda['nombre']]) }}">
                                <b>{{ $boton['nombre'] }}</b>
                                <small>{{ $etiquetaStock($celda) }}</small>
                                @if (Decimal::comparar($celda['precio_consumidor'], (string) $modelo->precio_consumidor) !== 0)
                                    <small class="selector-precio">Bs {{ Decimal::formatear($celda['precio_consumidor'], 2, 2) }}</small>
                                @endif
                            </button>
                        @endif
                    @endforeach
                </div>
            </div>
            <p class="subtle selector-mensaje" role="status" aria-live="polite">{{ $mensajeCaptura }}</p>
            <div class="modal-actions">
                <span class="selector-precio-base">{{ __('Precio del modelo: :precio', ['precio' => Decimal::bs((string) $modelo->precio_consumidor)]) }}</span>
                <button type="button" class="btn primary" wire:click="cerrarSelector">{{ __('Listo') }}</button>
            </div>
        @endif
    </x-modal>
@endif
