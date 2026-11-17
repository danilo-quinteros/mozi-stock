@use('App\Support\Decimal')
@use('App\Domain\Produccion\Consultas\ListadoRecetas')
{{-- Producción → Recetas (ESPECIFICACION_PRODUCCION.md § 3 y § 5). --}}
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Producción') }}</div>
            <h1>{{ __('Recetas') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Qué materias primas lleva cada producto que fabricas, para una cantidad base. Cada cambio crea una versión nueva; las órdenes ya emitidas conservan la suya.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn primary" href="{{ route('produccion.recetas.crear') }}" data-test="nueva-receta"><x-icon name="plus" /> {{ __('Nueva receta') }}</a>
        </div>
    </div>

    <section class="card card-pad">
        <div class="card-head">
            <div class="heading"><span class="heading-icon"><x-icon name="table" /></span>{{ __('Productos fabricados') }}</div>
        </div>
        <div class="tabs" role="tablist">
            @foreach (['activas' => 'Activas', 'inactivas' => 'Desactivadas', 'todas' => 'Todas'] as $clave => $etiqueta)
                <button type="button" role="tab" class="tab {{ $estadoActivo === $clave ? 'active' : '' }}" aria-selected="{{ $estadoActivo === $clave ? 'true' : 'false' }}" wire:click="$set('estado', '{{ $clave }}')">{{ __($etiqueta) }}</button>
            @endforeach
        </div>
        <div class="tool-row">
            <div class="search">
                <x-icon name="search" />
                <input class="input" type="search" wire:model.live.debounce.350ms="buscar" placeholder="{{ __('Buscar producto por nombre o código') }}" aria-label="{{ __('Buscar recetas') }}">
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('Producto') }}</th><th>{{ __('Cantidad base') }}</th><th>{{ __('Materias primas') }}</th><th>{{ __('Versión') }}</th>
                        <th>{{ __('Actualizada') }}</th><th>{{ __('Estado') }}</th><th><span class="sr-only">{{ __('Acción') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recetas as $receta)
                        @php($producto = $receta->producto)
                        <tr wire:key="receta-{{ $receta->id }}" @if (! $receta->activa) style="opacity:.6" @endif>
                            <td data-label="{{ __('Producto') }}"><b>{{ $producto->nombre }}</b>@if ($producto->sku)<small style="display:block;color:var(--faint);margin-top:4px">{{ $producto->sku }}</small>@endif</td>
                            <td data-label="{{ __('Cantidad base') }}">{{ Decimal::formatear((string) $receta->cantidad_base) }} {{ $producto->unidad->abreviatura }}</td>
                            <td data-label="{{ __('Materias primas') }}">{{ $receta->items_count }}</td>
                            <td data-label="{{ __('Versión') }}">v{{ $receta->version }}</td>
                            <td data-label="{{ __('Actualizada') }}">{{ $receta->created_at->timezone(config('mozistock.business_timezone'))->format('d/m/Y') }}</td>
                            <td data-label="{{ __('Estado') }}">
                                @if ($receta->activa)<span class="badge success">{{ __('Activa') }}</span>@else<span class="badge">{{ __('Desactivada') }}</span>@endif
                                @foreach (ListadoRecetas::avisos($producto) as $aviso)<span class="badge warn">{{ $aviso }}</span>@endforeach
                            </td>
                            <td data-label="{{ __('Acción') }}"><a class="btn ghost sm" href="{{ route('produccion.recetas.ver', $producto) }}"><x-icon name="eye" size="sm" /> {{ __('Ver') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="empty">
                            @if ($estadoActivo === 'activas' && trim($buscar) === '')
                                {{ __('Todavía no hay recetas. Crea la primera con «Nueva receta».') }}
                            @else
                                {{ __('No hay recetas que coincidan con el filtro.') }}
                            @endif
                        </div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($recetas->hasPages())
            <div class="table-footer">
                <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $recetas->currentPage(), 'lastpage' => $recetas->lastPage()]) }}</span>
                <span class="row">
                    @unless ($recetas->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                    @if ($recetas->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                </span>
            </div>
        @endif
    </section>

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
