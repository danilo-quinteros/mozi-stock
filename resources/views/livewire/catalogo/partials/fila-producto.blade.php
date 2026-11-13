@use('App\Support\Decimal')
@use('App\Domain\Catalogo\Consultas\ListadoProductos')
{{-- Fila de un producto suelto del catálogo (también una variante cuando la capacidad «variantes» está apagada). --}}
@php $alerta = ListadoProductos::alerta((string) $producto->stock, (string) $producto->minimo, $producto->controla_stock); @endphp
<tr wire:key="producto-{{ $producto->id }}">
    <td>
        <div class="item-name">
            @if ($conVariantes ?? false)
                {{-- Tallas y colores § 9: marcar para «Agrupar en modelo». --}}
                <input type="checkbox" class="row-checkbox" value="{{ $producto->id }}" x-model="agrupar" data-test="marcar-agrupar-{{ $producto->id }}"
                    aria-label="{{ __('Marcar :nombre para agrupar en un modelo', ['nombre' => $producto->nombre]) }}">
            @endif
            <span class="token" style="overflow:hidden">
                @if ($producto->imagen_path)
                    <img src="{{ route('productos.imagen', $producto) }}" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover">
                @else
                    <x-icon name="store" size="sm" />
                @endif
            </span>
            <span>
                <b>{{ $producto->nombre }}</b>
                @if ($producto->vinculoExterno)
                    <span class="badge info" style="font-size:10px;padding:2px 6px;margin-left:4px">Cyclone</span>
                @endif
                <small>@if ($producto->marca){{ $producto->marca }} · @endif{{ $producto->sku }}@unless ($producto->activo) {{ __('· Inactivo') }} @endunless</small>
            </span>
        </div>
    </td>
    <td>{{ $producto->categoria->nombre ?? '—' }}</td>
    <td>
        @include('livewire.catalogo.partials.precio-editable', ['producto' => $producto])
        @if (Decimal::comparar($producto->precio_consumidor, '0') === 0 && $producto->vinculoExterno)
            <small style="display:block;color:var(--brand);font-weight:600">{{ __('Precio pendiente') }}</small>
        @endif
    </td>
    <td>{{ $producto->precio_minorista === null ? '—' : Decimal::bs($producto->precio_minorista) }}</td>
    <td>{{ $producto->precio_mayorista === null ? '—' : Decimal::bs($producto->precio_mayorista) }}</td>
    <td>@if ($alerta === 'sin_control')<span class="subtle" data-sin-control>{{ __('Sin control') }}</span>@else<b>{{ Decimal::formatear((string) $producto->stock) }}</b> {{ $producto->unidad->abreviatura }}@endif @isset($reservados[$producto->id])<small class="accent-text" data-reservado title="{{ __('Vendido y todavía en el almacén: inclúyelo en el conteo físico, no en el stock.') }}">{{ __('Reservado por despachar: :cantidad', ['cantidad' => Decimal::formatear($reservados[$producto->id])]) }}</small>@endisset</td>
    <td>
        @if ($alerta === 'sin_control')<span class="subtle">—</span>
        @elseif ($alerta === 'sin_stock')<span class="badge danger">{{ __('Sin stock') }}</span>
        @elseif ($alerta === 'bajo')<span class="badge danger">{{ __('Stock bajo') }}</span>
        @else<span class="badge info">{{ __('Normal') }}</span>@endif
    </td>
    @if ($administra)
        <td><a class="btn sm ghost" href="{{ route('productos.editar', $producto) }}"><x-icon name="edit" size="sm" /> {{ __('Editar') }}</a></td>
    @endif
</tr>
