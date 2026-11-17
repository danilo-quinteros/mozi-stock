@use('App\Support\Decimal')
@use('App\Domain\Inventario\DatosLote')
@use('App\Models\Producto')
{{-- Detalle de un envasado (ESPECIFICACION_PRODUCCION.md § 7). --}}
@php
    $nombre = fn ($p) => Producto::formatoNombreCompleto($p->nombre, $p->contenido, $p->unidad->abreviatura);
    $granel = $envasado->granel;
    $unidadGranel = $granel->unidad->abreviatura;
    $mermaGranel = Decimal::restar((string) $envasado->granel_real, (string) $envasado->granel_teorico);
@endphp
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Producción · Envasado') }}</div>
            <h1>{{ $envasado->codigo() }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __(':granel envasado en :sucursal.', ['granel' => $granel->nombre, 'sucursal' => $envasado->sucursal->nombre]) }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('produccion.envasados') }}"><x-icon name="arrow-left" /> {{ __('Envasado') }}</a>
            <a class="btn primary" href="{{ route('produccion.envasados.crear', ['granel' => $granel->id]) }}" data-test="otro-envasado"><x-icon name="plus" /> {{ __('Nuevo envasado') }}</a>
        </div>
    </div>

    <div class="stack">
        <section class="card card-pad">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="swap" /></span>{{ __('Datos del envasado') }}</div></div>
            <div class="info-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px">
                <div><span class="subtle">{{ __('Granel') }}</span><b style="display:block">{{ $nombre($granel) }}</b>@if ($granel->sku)<small style="color:var(--faint)">{{ $granel->sku }}</small>@endif</div>
                @if ($envasado->lote)
                    <div data-test="lote-envasado"><span class="subtle">{{ __('Lote') }}</span>
                        <b style="display:block"><a class="link" href="{{ route('inventario.lote', $envasado->lote) }}">{{ $envasado->lote->es_regularizacion ? __('Sin lote (stock anterior)') : $envasado->lote->numero }}</a></b>
                        @if ($envasado->lote->vencimiento)<small style="color:var(--faint)">{{ __('Vence :fecha', ['fecha' => DatosLote::formatear($envasado->lote->vencimiento->format('Y-m-d'))]) }}</small>@endif
                    </div>
                @endif
                <div><span class="subtle">{{ __('Registrado') }}</span><b style="display:block">{{ $envasado->created_at->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i') }}</b><small style="color:var(--faint)">{{ $envasado->usuario->name }}</small></div>
                <div><span class="subtle">{{ __('Costo del envasado') }}</span><b style="display:block" data-test="costo-envasado">{{ Decimal::bs((string) $envasado->costo_total) }}</b></div>
            </div>
            @if ($envasado->nota)<p style="margin:14px 0 0">{{ $envasado->nota }}</p>@endif
        </section>

        <section class="card card-pad" data-test="presentaciones-envasado">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="box" /></span>{{ __('Presentaciones que entraron') }}</div></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Presentación') }}</th><th>{{ __('Unidades') }}</th><th>{{ __('Granel por unidad') }}</th><th>{{ __('Costo unitario') }}</th><th>{{ __('Costo total') }}</th></tr></thead>
                    <tbody>
                        @foreach ($envasado->items as $item)
                            <tr wire:key="item-{{ $item->id }}">
                                <td data-label="{{ __('Presentación') }}"><b>{{ $nombre($item->producto) }}</b>@if ($item->producto->sku)<small style="display:block;color:var(--faint);margin-top:4px">{{ $item->producto->sku }}</small>@endif</td>
                                <td data-label="{{ __('Unidades') }}">{{ Decimal::formatear((string) $item->unidades) }} {{ $item->producto->unidad->abreviatura }}</td>
                                <td data-label="{{ __('Granel por unidad') }}">{{ Decimal::formatear((string) $item->granel_por_unidad) }} {{ $unidadGranel }}</td>
                                <td data-label="{{ __('Costo unitario') }}">Bs {{ Decimal::formatear((string) $item->costo_unitario, 2, 4) }}</td>
                                <td data-label="{{ __('Costo total') }}">{{ Decimal::bs((string) $item->costo_total) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card card-pad" data-test="materiales-envasado">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="table" /></span>{{ __('Lo que se usó') }}</div></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Material') }}</th><th>{{ __('Necesario') }}</th><th>{{ __('Usado') }}</th><th>{{ __('Merma') }}</th><th>{{ __('Costo') }}</th></tr></thead>
                    <tbody>
                        <tr wire:key="material-granel">
                            <td data-label="{{ __('Material') }}"><b>{{ $granel->nombre }}</b><small style="display:block;color:var(--faint);margin-top:4px">{{ __('Granel') }}</small></td>
                            <td data-label="{{ __('Necesario') }}">{{ Decimal::formatear((string) $envasado->granel_teorico) }} {{ $unidadGranel }}</td>
                            <td data-label="{{ __('Usado') }}">{{ Decimal::formatear((string) $envasado->granel_real) }} {{ $unidadGranel }}</td>
                            <td data-label="{{ __('Merma') }}">@if (Decimal::comparar($mermaGranel, '0') > 0)<span class="danger-text">{{ Decimal::formatear($mermaGranel) }} {{ $unidadGranel }}</span>@else — @endif</td>
                            <td data-label="{{ __('Costo') }}">Bs {{ Decimal::formatear((string) $envasado->granel_costo_unitario, 2, 4) }} <small style="color:var(--faint)">/ {{ $unidadGranel }}</small></td>
                        </tr>
                        @foreach ($envasado->consumos as $consumo)
                            @php
                                $unidad = $consumo->producto->unidad->abreviatura;
                                $mermaEnvase = Decimal::restar((string) $consumo->cantidad_real, (string) $consumo->cantidad_teorica);
                            @endphp
                            <tr wire:key="consumo-{{ $consumo->id }}">
                                <td data-label="{{ __('Material') }}"><b>{{ $consumo->producto->nombre }}</b><small style="display:block;color:var(--faint);margin-top:4px">{{ __('Envase') }}</small></td>
                                <td data-label="{{ __('Necesario') }}">{{ Decimal::formatear((string) $consumo->cantidad_teorica) }} {{ $unidad }}</td>
                                <td data-label="{{ __('Usado') }}">{{ Decimal::formatear((string) $consumo->cantidad_real) }} {{ $unidad }}</td>
                                <td data-label="{{ __('Merma') }}">@if (Decimal::comparar($mermaEnvase, '0') > 0)<span class="danger-text">{{ Decimal::formatear($mermaEnvase) }} {{ $unidad }}</span>@else — @endif</td>
                                <td data-label="{{ __('Costo') }}">Bs {{ Decimal::formatear((string) $consumo->costo_unitario, 2, 4) }} <small style="color:var(--faint)">/ {{ $unidad }}</small></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
