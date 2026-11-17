@use('App\Support\Decimal')
{{-- Producción → Órdenes (ESPECIFICACION_PRODUCCION.md § 3 y § 6). --}}
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Producción') }}</div>
            <h1>{{ __('Órdenes de producción') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Emite la orden, apruébala para descontar las materias primas y finalízala con lo que se obtuvo: el producto entra al stock con su lote y su costo.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn primary" href="{{ route('produccion.ordenes.crear') }}" data-test="nueva-orden"><x-icon name="plus" /> {{ __('Nueva orden') }}</a>
        </div>
    </div>

    <section class="card card-pad">
        <div class="card-head">
            <div class="heading"><span class="heading-icon"><x-icon name="receipt" /></span>{{ __('Órdenes') }}</div>
        </div>
        <div class="tabs" role="tablist">
            @foreach (['abiertas' => 'Abiertas', 'finalizadas' => 'Finalizadas', 'anuladas' => 'Anuladas', 'todas' => 'Todas'] as $clave => $etiqueta)
                <button type="button" role="tab" class="tab {{ $estadoActivo === $clave ? 'active' : '' }}" aria-selected="{{ $estadoActivo === $clave ? 'true' : 'false' }}" wire:click="$set('estado', '{{ $clave }}')">{{ __($etiqueta) }}</button>
            @endforeach
        </div>
        <div class="tool-row">
            <div class="search">
                <x-icon name="search" />
                <input class="input" type="search" wire:model.live.debounce.350ms="buscar" placeholder="{{ __('Buscar por número de orden o producto') }}" aria-label="{{ __('Buscar órdenes') }}">
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('Orden') }}</th><th>{{ __('Producto') }}</th><th>{{ __('Cantidad') }}</th><th>{{ __('Sucursal') }}</th>
                        <th>{{ __('Programada') }}</th><th>{{ __('Estado') }}</th><th><span class="sr-only">{{ __('Acción') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($ordenes as $orden)
                        @php($unidad = $orden->producto->unidad->abreviatura)
                        <tr wire:key="orden-{{ $orden->id }}">
                            <td data-label="{{ __('Orden') }}"><b>{{ $orden->codigo() }}</b></td>
                            <td data-label="{{ __('Producto') }}">{{ $orden->producto->nombre }}@if ($orden->producto->sku)<small style="display:block;color:var(--faint);margin-top:4px">{{ $orden->producto->sku }}</small>@endif</td>
                            <td data-label="{{ __('Cantidad') }}">
                                {{ Decimal::formatear((string) $orden->cantidad_planificada) }} {{ $unidad }}
                                @if ($orden->cantidad_obtenida !== null)<small style="display:block;color:var(--faint);margin-top:4px">{{ __('Obtenido: :cantidad :unidad', ['cantidad' => Decimal::formatear((string) $orden->cantidad_obtenida), 'unidad' => $unidad]) }}</small>@endif
                            </td>
                            <td data-label="{{ __('Sucursal') }}">{{ $orden->sucursal->nombre }}</td>
                            <td data-label="{{ __('Programada') }}">{{ $orden->fecha_programada->format('d/m/Y') }}</td>
                            <td data-label="{{ __('Estado') }}"><span class="badge {{ $orden->estado->insignia() }}">{{ $orden->estado->etiqueta() }}</span></td>
                            <td data-label="{{ __('Acción') }}"><a class="btn ghost sm" href="{{ route('produccion.ordenes.ver', $orden) }}"><x-icon name="eye" size="sm" /> {{ __('Ver') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="empty">
                            @if ($estadoActivo === 'abiertas' && trim($buscar) === '')
                                {{ __('No hay órdenes abiertas. Emite una con «Nueva orden».') }}
                            @else
                                {{ __('No hay órdenes que coincidan con el filtro.') }}
                            @endif
                        </div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($ordenes->hasPages())
            <div class="table-footer">
                <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $ordenes->currentPage(), 'lastpage' => $ordenes->lastPage()]) }}</span>
                <span class="row">
                    @unless ($ordenes->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                    @if ($ordenes->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                </span>
            </div>
        @endif
    </section>

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
