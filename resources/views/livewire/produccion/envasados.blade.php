@use('App\Support\Decimal')
{{-- Producción → Envasado (ESPECIFICACION_PRODUCCION.md § 7). --}}
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Producción') }}</div>
            <h1>{{ __('Envasado') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Pasa un granel a sus presentaciones: se descuentan el granel y los envases y entran las unidades, con el mismo lote y su costo.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn primary" href="{{ route('produccion.envasados.crear') }}" data-test="nuevo-envasado"><x-icon name="plus" /> {{ __('Nuevo envasado') }}</a>
        </div>
    </div>

    <section class="card card-pad">
        <div class="card-head">
            <div class="heading"><span class="heading-icon"><x-icon name="swap" /></span>{{ __('Envasados') }}</div>
        </div>
        <div class="tool-row">
            <div class="search">
                <x-icon name="search" />
                <input class="input" type="search" wire:model.live.debounce.350ms="buscar" placeholder="{{ __('Buscar por número o granel') }}" aria-label="{{ __('Buscar envasados') }}">
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('Envasado') }}</th><th>{{ __('Granel') }}</th><th>{{ __('Presentaciones') }}</th><th>{{ __('Sucursal') }}</th>
                        <th>{{ __('Costo') }}</th><th><span class="sr-only">{{ __('Acción') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($envasados as $envasado)
                        <tr wire:key="envasado-{{ $envasado->id }}">
                            <td data-label="{{ __('Envasado') }}"><b>{{ $envasado->codigo() }}</b><small style="display:block;color:var(--faint);margin-top:4px">{{ $envasado->created_at->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i') }}</small></td>
                            <td data-label="{{ __('Granel') }}">
                                {{ $envasado->granel->nombre }}
                                <small style="display:block;color:var(--faint);margin-top:4px">
                                    {{ Decimal::formatear((string) $envasado->granel_real) }} {{ $envasado->granel->unidad->abreviatura }}@if ($envasado->lote && ! $envasado->lote->es_regularizacion) · {{ __('lote :lote', ['lote' => $envasado->lote->numero]) }}@endif
                                </small>
                            </td>
                            <td data-label="{{ __('Presentaciones') }}">
                                @foreach ($envasado->items as $item)
                                    <span style="display:block">{{ $item->producto->nombre }} × {{ Decimal::formatear((string) $item->unidades) }}</span>
                                @endforeach
                            </td>
                            <td data-label="{{ __('Sucursal') }}">{{ $envasado->sucursal->nombre }}</td>
                            <td data-label="{{ __('Costo') }}">{{ Decimal::bs((string) $envasado->costo_total) }}</td>
                            <td data-label="{{ __('Acción') }}"><a class="btn ghost sm" href="{{ route('produccion.envasados.ver', $envasado) }}"><x-icon name="eye" size="sm" /> {{ __('Ver') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="empty">
                            @if (trim($buscar) === '')
                                {{ __('Todavía no hay envasados. Registra uno con «Nuevo envasado».') }}
                            @else
                                {{ __('No hay envasados que coincidan con la búsqueda.') }}
                            @endif
                        </div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($envasados->hasPages())
            <div class="table-footer">
                <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $envasados->currentPage(), 'lastpage' => $envasados->lastPage()]) }}</span>
                <span class="row">
                    @unless ($envasados->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                    @if ($envasados->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                </span>
            </div>
        @endif
    </section>

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
