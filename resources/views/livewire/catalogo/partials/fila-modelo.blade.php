@use('App\Support\Decimal')
@use('App\Domain\Catalogo\Consultas\ListadoProductos')
{{-- Tallas y colores § 5: un modelo es una fila con el stock total de sus combinaciones activas y un botón para
     desplegar las combinaciones que cumplen los filtros. El precio consumidor de cada combinación se edita aquí igual que
     el de un producto suelto (queda con precio propio); los tres precios, en el detalle del modelo. --}}
<tr wire:key="modelo-{{ $modelo->id }}" class="modelo-fila" data-test="fila-modelo-{{ $modelo->id }}">
    <td>
        <div class="item-name">
            <span class="token" style="overflow:hidden">
                @if ($modelo->imagen_path)
                    <img src="{{ route('productos.modelos.imagen', $modelo) }}" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover">
                @else
                    <x-icon name="tag" size="sm" />
                @endif
            </span>
            <span>
                <a class="modelo-enlace" href="{{ route('productos.modelos.ver', $modelo) }}"><b>{{ $modelo->nombre }}</b></a>
                <small>{{ trans_choice(':count combinación|:count combinaciones', $resumen['variantes'] ?? 0) }}@if ($modelo->sku_base) · {{ $modelo->sku_base }}@endif @unless ($modelo->activo) {{ __('· Inactivo') }} @endunless</small>
                @if ($variantes->isNotEmpty())
                    <button type="button" class="modelo-desplegar" x-on:click="abiertos[{{ $modelo->id }}] = ! abiertos[{{ $modelo->id }}]"
                        x-bind:aria-expanded="abiertos[{{ $modelo->id }}] ? 'true' : 'false'" aria-controls="variantes-{{ $modelo->id }}" data-test="desplegar-{{ $modelo->id }}">
                        <x-icon name="chevron-down" size="sm" x-bind:class="abiertos[{{ $modelo->id }}] && 'is-abierto'" />
                        <span x-text="abiertos[{{ $modelo->id }}] ? @js(__('Ocultar combinaciones')) : @js(__('Ver combinaciones'))">{{ __('Ver combinaciones') }}</span>
                    </button>
                @endif
            </span>
        </div>
    </td>
    <td>{{ $modelo->categoria->nombre ?? '—' }}</td>
    <td>
        <b>{{ Decimal::bs($modelo->precio_consumidor) }}</b>
        @if (($resumen['precio_propio'] ?? 0) > 0)<small class="subtle" style="display:block">{{ trans_choice(':count con precio propio|:count con precio propio', $resumen['precio_propio']) }}</small>@endif
    </td>
    <td>{{ $modelo->precio_minorista === null ? '—' : Decimal::bs($modelo->precio_minorista) }}</td>
    <td>{{ $modelo->precio_mayorista === null ? '—' : Decimal::bs($modelo->precio_mayorista) }}</td>
    <td><b>{{ Decimal::formatear($resumen['stock'] ?? '0') }}</b> {{ $modelo->unidad?->abreviatura }} <small class="subtle">{{ __('en total') }}</small></td>
    <td>
        @if ($resumen === null)<span class="subtle">—</span>
        @elseif ($resumen['alertas'] > 0)<span class="badge danger">{{ trans_choice(':count con alerta|:count con alerta', $resumen['alertas']) }}</span>
        @else<span class="badge info">{{ __('Normal') }}</span>@endif
    </td>
    @if ($administra)
        <td>
            <div class="row" style="gap:6px;flex-wrap:nowrap">
                <a class="btn sm ghost" href="{{ route('productos.modelos.ver', $modelo) }}"><x-icon name="eye" size="sm" /> {{ __('Ver') }}</a>
                <a class="btn sm ghost" href="{{ route('productos.modelos.editar', $modelo) }}"><x-icon name="edit" size="sm" /><span class="sr-only">{{ __('Editar :nombre', ['nombre' => $modelo->nombre]) }}</span></a>
            </div>
        </td>
    @endif
</tr>
@foreach ($variantes as $variante)
    @php $alerta = ListadoProductos::alerta((string) $variante->stock, (string) $variante->minimo, $variante->controla_stock); @endphp
    <tr wire:key="variante-{{ $variante->id }}" class="variante-fila" x-show="abiertos[{{ $modelo->id }}]" x-cloak @if ($loop->first) id="variantes-{{ $modelo->id }}" @endif data-test="fila-variante-{{ $variante->id }}">
        <td>
            <span class="variante-nombre">
                <b>{{ $variante->nombre }}</b>
                <small>{{ $variante->sku }}@if ($variante->codigo_barras) · {{ $variante->codigo_barras }}@endif @unless ($variante->activo) {{ __('· Inactiva') }} @endunless</small>
            </span>
        </td>
        <td><span class="subtle">—</span></td>
        <td>
            @include('livewire.catalogo.partials.precio-editable', ['producto' => $variante])
            @if ($variante->precio_propio)<span class="badge warning" style="font-size:10px;padding:2px 6px" title="{{ __('El cambio de precio del modelo no la toca.') }}">{{ __('Propio') }}</span>@endif
        </td>
        <td>{{ $variante->precio_minorista === null ? '—' : Decimal::bs($variante->precio_minorista) }}</td>
        <td>{{ $variante->precio_mayorista === null ? '—' : Decimal::bs($variante->precio_mayorista) }}</td>
        <td><b>{{ Decimal::formatear((string) $variante->stock) }}</b> {{ $variante->unidad->abreviatura }} @isset($reservados[$variante->id])<small class="accent-text" data-reservado>{{ __('Reservado por despachar: :cantidad', ['cantidad' => Decimal::formatear($reservados[$variante->id])]) }}</small>@endisset</td>
        <td>
            @if ($alerta === 'sin_control')<span class="subtle">—</span>
            @elseif ($alerta === 'sin_stock')<span class="badge danger">{{ __('Sin stock') }}</span>
            @elseif ($alerta === 'bajo')<span class="badge danger">{{ __('Stock bajo') }}</span>
            @else<span class="badge info">{{ __('Normal') }}</span>@endif
        </td>
        @if ($administra)
            <td><a class="btn sm ghost" href="{{ route('productos.editar', $variante) }}"><x-icon name="edit" size="sm" /> {{ __('Editar') }}</a></td>
        @endif
    </tr>
@endforeach
