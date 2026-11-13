@use('App\Support\Decimal')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Catálogo · Modelo') }}</div>
            <h1>{{ $modelo->nombre }}<span class="accent-text">.</span></h1>
            <p class="lead">
                {{ $modelo->categoria?->nombre ?? __('Sin categoría') }} · {{ $modelo->unidad?->nombre }}
                @if ($modelo->sku_base) · <code>{{ $modelo->sku_base }}</code>@endif
                @unless ($modelo->activo) · <span class="badge danger">{{ __('Inactivo') }}</span>@endunless
            </p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('productos.index') }}"><x-icon name="box" /> {{ __('Productos') }}</a>
            @if ($administra)
                <a class="btn primary" href="{{ route('productos.modelos.editar', $modelo) }}" data-test="editar-modelo"><x-icon name="edit" /> {{ __('Editar modelo') }}</a>
            @endif
        </div>
    </div>

    <div class="grid four" style="margin-bottom:18px">
        <article class="card stat"><span class="stat-label">{{ __('Combinaciones activas') }}</span><div class="stat-value">{{ $activas }}</div><div class="stat-foot">{{ $modelo->grupoTalla?->nombre ?? __('Sin talla') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Stock total') }}</span><div class="stat-value" data-test="modelo-stock-total">{{ Decimal::formatear($cuadricula['total']) }}</div><div class="stat-foot">{{ $sucursalVista ? __('En :sucursal', ['sucursal' => $sucursalVista->nombre]) : __('Sin sucursal') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Sin stock o bajo') }}</span><div class="stat-value">{{ $alertasStock }}</div><div class="stat-foot">{{ __('Según el mínimo de cada combinación') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Precio del modelo') }}</span><div class="stat-value" style="font-size:clamp(18px,2vw,24px)">{{ Decimal::bs($modelo->precio_consumidor) }}</div><div class="stat-foot">{{ trans_choice(':count con precio propio|:count con precio propio', $conPrecioPropio) }}</div></article>
    </div>

    <section class="card card-pad" data-test="modelo-cuadricula-stock">
        <div class="card-head">
            <div><div class="heading"><span class="heading-icon"><x-icon name="box" /></span>{{ __('Stock por talla y color') }}</div>
                <p class="subtle" style="margin:9px 0 0">{{ __('El stock entra por Compras o Ajustes → Entrada y se mueve con ventas y traspasos.') }}</p></div>
            @if ($sucursales->isNotEmpty())
                <select class="select" wire:model.live="sucursal" aria-label="{{ __('Sucursal') }}" @disabled($sucursalFija) style="max-width:220px">
                    @foreach ($sucursales as $opcion)
                        <option value="{{ $opcion->id }}" @selected($sucursalVista?->id === $opcion->id)>{{ $opcion->nombre }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        @if ($cuadricula['celdas'] === [])
            <div class="empty">{{ __('Este modelo aún no tiene combinaciones.') }}</div>
        @else
            <div class="table-wrap variantes-cuadricula-wrap">
                <table class="variantes-cuadricula variantes-stock">
                    <thead>
                        <tr>
                            <th scope="col"><span class="sr-only">{{ __('Color') }}</span></th>
                            @foreach ($cuadricula['tallas'] as $talla)
                                <th scope="col">{{ $talla['id'] === null ? __('Sin talla') : $talla['nombre'] }}</th>
                            @endforeach
                            <th scope="col">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cuadricula['colores'] as $color)
                            <tr>
                                <th scope="row"><span class="color-nombre">@if ($color['id'] !== null)<span class="color-muestra" @if ($color['muestra']) style="background:{{ $color['muestra'] }}" @endif aria-hidden="true"></span>@endif{{ $color['id'] === null ? __('Sin color') : $color['nombre'] }}</span></th>
                                @foreach ($cuadricula['tallas'] as $talla)
                                    @php($celda = $cuadricula['celdas'][($talla['id'] ?? 0).'-'.($color['id'] ?? 0)] ?? null)
                                    @if ($celda === null)
                                        <td class="variante-stock is-vacia"><span class="subtle" title="{{ __('Esta combinación no existe') }}">—</span></td>
                                    @else
                                        <td class="variante-stock is-{{ $celda['activo'] ? $celda['alerta'] : 'inactiva' }}" data-test="stock-{{ $celda['id'] }}" title="{{ $celda['nombre'] }}">
                                            @if ($celda['con_foto'])<span class="variante-foto" title="{{ __('Con foto propia') }}" data-test="con-foto-{{ $celda['id'] }}"><x-icon name="image" size="sm" /><span class="sr-only">{{ __('Con foto propia') }}</span></span>@endif
                                            <b>{{ Decimal::formatear($celda['stock']) }}</b>
                                            @unless ($celda['activo'])<small>{{ __('Inactiva') }}</small>
                                            @elseif ($celda['alerta'] === 'sin_stock')<small>{{ __('Sin stock') }}</small>
                                            @elseif ($celda['alerta'] === 'bajo')<small>{{ __('Bajo') }}</small>
                                            @endunless
                                        </td>
                                    @endif
                                @endforeach
                                <td class="variante-total"><b>{{ Decimal::formatear($cuadricula['totales_color'][$color['id'] ?? 0] ?? '0') }}</b></td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th scope="row">{{ __('Total') }}</th>
                            @foreach ($cuadricula['tallas'] as $talla)
                                <td class="variante-total"><b>{{ Decimal::formatear($cuadricula['totales_talla'][$talla['id'] ?? 0] ?? '0') }}</b></td>
                            @endforeach
                            <td class="variante-total"><b>{{ Decimal::formatear($cuadricula['total']) }}</b></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="variantes-leyenda" aria-hidden="true">
                <span><i class="is-normal"></i>{{ __('Normal') }}</span>
                <span><i class="is-bajo"></i>{{ __('Stock bajo') }}</span>
                <span><i class="is-sin_stock"></i>{{ __('Sin stock') }}</span>
                <span><x-icon name="image" size="sm" />{{ __('Con foto propia (las demás usan la del modelo)') }}</span>
            </div>
        @endif
    </section>

    <section class="card card-pad" style="margin-top:18px" data-test="modelo-combinaciones">
        <div class="card-head"><div><div class="heading"><span class="heading-icon"><x-icon name="tag" /></span>{{ __('Combinaciones y precios') }}</div>
            <p class="subtle" style="margin:9px 0 0">{{ __('Sin precio propio, la combinación toma el precio del modelo y cambia con él. Las ventas pasadas no cambian.') }}</p></div></div>
        @error('variante')<p class="danger-text" role="alert" data-test="error-variante" style="margin:0 0 12px">{{ $message }}</p>@enderror
        <div class="table-wrap">
            <table class="variantes-tabla">
                <thead><tr><th>{{ __('Combinación') }}</th><th>{{ __('SKU') }}</th><th>{{ __('Código de barras') }}</th><th>{{ __('Stock') }}</th><th>{{ __('Precio') }}</th><th>{{ __('Estado') }}</th>@if ($administra)<th><span class="sr-only">{{ __('Acción') }}</span></th>@endif</tr></thead>
                <tbody>
                    @foreach ($cuadricula['tallas'] as $talla)
                        @foreach ($cuadricula['colores'] as $color)
                            @php($celda = $cuadricula['celdas'][($talla['id'] ?? 0).'-'.($color['id'] ?? 0)] ?? null)
                            @continue($celda === null)
                            <tr wire:key="variante-{{ $celda['id'] }}" data-test="variante-{{ $celda['id'] }}">
                                <td data-label="{{ __('Combinación') }}"><b>{{ $celda['nombre'] }}</b></td>
                                <td data-label="{{ __('SKU') }}">@if ($celda['sku'])<code>{{ $celda['sku'] }}</code>@else<span class="subtle">—</span>@endif</td>
                                <td data-label="{{ __('Código de barras') }}">{{ $celda['codigo_barras'] ?? '—' }}</td>
                                <td data-label="{{ __('Stock') }}"><b>{{ Decimal::formatear($celda['stock']) }}</b> {{ $modelo->unidad?->abreviatura }}</td>
                                <td data-label="{{ __('Precio') }}">
                                    <b>{{ Decimal::bs($celda['precio_consumidor']) }}</b>
                                    <span class="badge {{ $celda['precio_propio'] ? 'warning' : '' }}" data-test="precio-{{ $celda['precio_propio'] ? 'propio' : 'modelo' }}">{{ $celda['precio_propio'] ? __('Propio') : __('Del modelo') }}</span>
                                </td>
                                <td data-label="{{ __('Estado') }}"><span class="badge {{ $celda['activo'] ? 'success' : 'danger' }}">{{ $celda['activo'] ? __('Activa') : __('Inactiva') }}</span></td>
                                @if ($administra)
                                    <td>
                                        <div class="row" style="gap:6px;flex-wrap:wrap">
                                            <button class="btn sm ghost" type="button" wire:click="cambiarPrecio({{ $celda['id'] }})">{{ __('Cambiar precio') }}</button>
                                            @if ($celda['precio_propio'])
                                                <button class="btn sm ghost" type="button" wire:click="usarPrecioModelo({{ $celda['id'] }})" wire:confirm="{{ __('¿Volver al precio del modelo (:precio)?', ['precio' => Decimal::bs($modelo->precio_consumidor)]) }}">{{ __('Usar precio del modelo') }}</button>
                                            @endif
                                            <a class="btn sm ghost" href="{{ route('productos.editar', $celda['id']) }}" title="{{ __('SKU, código, foto y stock mínimo por sucursal') }}"><x-icon name="edit" size="sm" /><span class="sr-only">{{ __('Editar :nombre', ['nombre' => $celda['nombre']]) }}</span></a>
                                            <button class="btn sm ghost" type="button" wire:click="quitarDelModelo({{ $celda['id'] }})" data-test="quitar-{{ $celda['id'] }}"
                                                wire:confirm="{{ __('¿Quitar :nombre del modelo? Vuelve a ser un producto suelto con su nombre actual, su stock y su historial.', ['nombre' => $celda['nombre']]) }}">{{ __('Quitar del modelo') }}</button>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($modelo->descripcion)
            <div class="divider"></div>
            <p class="subtle" style="margin:0;white-space:pre-line">{{ $modelo->descripcion }}</p>
        @endif
    </section>

    @if ($formPrecio && $varianteEditada)
        <x-modal titulo="Precio propio" :descripcion="__(':nombre deja de seguir el precio del modelo (:precio).', ['nombre' => $varianteEditada['nombre'], 'precio' => Decimal::bs($modelo->precio_consumidor)])" cerrar="cerrar">
            <form wire:submit="guardarPrecio" class="stack" style="gap:14px">
                <div class="fields three">
                    <x-campo etiqueta="Consumidor (Bs) *" campo="precioConsumidor">
                        <input id="precioConsumidor" class="input" type="text" inputmode="decimal" wire:model="precioConsumidor" required autofocus>
                    </x-campo>
                    <x-campo etiqueta="Minorista (Bs)" campo="precioMinorista">
                        <input id="precioMinorista" class="input" type="text" inputmode="decimal" wire:model="precioMinorista">
                    </x-campo>
                    <x-campo etiqueta="Mayorista (Bs)" campo="precioMayorista">
                        <input id="precioMayorista" class="input" type="text" inputmode="decimal" wire:model="precioMayorista">
                    </x-campo>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn ghost" wire:click="cerrar">{{ __('Cancelar') }}</button>
                    <button type="submit" class="btn primary" wire:loading.attr="disabled" wire:target="guardarPrecio"><x-icon name="check" /> {{ __('Guardar precio propio') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
