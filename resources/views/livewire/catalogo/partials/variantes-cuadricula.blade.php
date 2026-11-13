{{-- Cuadrícula de combinaciones (ArmaCombinaciones). Variables: las de vistaCombinaciones(), $editando y, opcionales,
     $conStockInicial (cada celda nueva pide su cantidad de stock inicial, «Nuevo producto»), $conAcciones (botones
     Cancelar y Guardar al final; por defecto sí), $cancelarUrl y $textoGuardar. --}}
@php
    $conStockInicial = $conStockInicial ?? false;
@endphp
<section class="card card-pad" data-test="modelo-cuadricula">
    <div class="card-head"><div><div class="heading">{{ __('Combinaciones') }}</div><p class="subtle" style="margin:9px 0 0">{{ $conStockInicial
        ? __('Cada celda será un producto. Revisa el SKU sugerido, agrega el código de barras si lo tienes, escribe su stock inicial (vacío = sin stock) o desmarca la que no exista.')
        : __('Cada celda será un producto. Revisa el SKU sugerido, agrega el código de barras si lo tienes o desmarca la que no exista.') }}</p></div></div>

    <div class="variantes-resumen" aria-live="polite">
        <span class="badge success">{{ trans_choice(':count nueva|:count nuevas', $resumen['nuevas']) }}</span>
        @if ($editando)
            <span class="badge">{{ trans_choice(':count se mantiene|:count se mantienen', $resumen['quedan']) }}</span>
            @if ($resumen['reactivadas'] > 0)<span class="badge info">{{ trans_choice(':count se reactiva|:count se reactivan', $resumen['reactivadas']) }}</span>@endif
            @if ($resumen['quitadas'] > 0)<span class="badge danger">{{ trans_choice(':count se quita|:count se quitan', $resumen['quitadas']) }}</span>@endif
        @endif
    </div>

    <div class="table-wrap variantes-cuadricula-wrap">
        <table class="variantes-cuadricula">
            <thead>
                <tr>
                    <th scope="col"><span class="sr-only">{{ __('Color') }}</span></th>
                    @foreach ($columnas as $talla)
                        <th scope="col">{{ $talla?->nombre ?? __('Sin talla') }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($filas as $color)
                    <tr wire:key="fila-{{ $color?->id ?? 0 }}">
                        <th scope="row"><span class="color-nombre">@if ($color)<span class="color-muestra" @if ($color->muestra_hex) style="background:{{ $color->muestra_hex }}" @endif aria-hidden="true"></span>@endif{{ $color?->nombre ?? __('Sin color') }}</span></th>
                        @foreach ($columnas as $talla)
                            @php
                                $clave = \App\Domain\Catalogo\Variantes::clave($talla?->id, $color?->id);
                                $existente = $existentes->get($clave);
                                $crear = $combinaciones[$clave]['crear'] ?? true;
                                $marca = $repetidos[$clave] ?? [];
                                $etiqueta = \App\Domain\Catalogo\Variantes::nombre($nombre !== '' ? $nombre : __('Modelo'), $talla?->nombre, $color?->nombre);
                            @endphp
                            <td wire:key="celda-{{ $clave }}" class="variante-celda {{ $crear ? '' : 'is-omitida' }}" data-test="celda-{{ $clave }}">
                                <label class="row variante-celda-crear">
                                    <input type="checkbox" class="toggle" wire:model.live="combinaciones.{{ $clave }}.crear" aria-label="{{ __('Crear :nombre', ['nombre' => $etiqueta]) }}">
                                    @if ($existente === null)
                                        <span class="badge success">{{ __('Nueva') }}</span>
                                    @elseif ($existente->activo)
                                        <span class="badge">{{ __('Existe') }}</span>
                                    @else
                                        <span class="badge info">{{ __('Inactiva') }}</span>
                                    @endif
                                </label>
                                @if ($crear)
                                    <input class="input {{ isset($marca['sku']) ? 'is-error' : '' }}" type="text" maxlength="{{ \App\Domain\Catalogo\Variantes::MAX_SKU }}" wire:model.live.debounce.400ms="combinaciones.{{ $clave }}.sku" placeholder="{{ __('SKU') }}" aria-label="{{ __('SKU de :nombre', ['nombre' => $etiqueta]) }}">
                                    @if (isset($marca['sku']))<p class="auth-error" role="alert">{{ $marca['sku'] }}</p>@endif
                                    @error('combinaciones.'.$clave.'.sku')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                                    <input class="input {{ isset($marca['codigo_barras']) ? 'is-error' : '' }}" type="text" maxlength="64" wire:model.live.debounce.400ms="combinaciones.{{ $clave }}.codigo_barras" placeholder="{{ __('Código de barras') }}" aria-label="{{ __('Código de barras de :nombre', ['nombre' => $etiqueta]) }}" wire:keydown.enter.prevent>
                                    @if (isset($marca['codigo_barras']))<p class="auth-error" role="alert">{{ $marca['codigo_barras'] }}</p>@endif
                                    @error('combinaciones.'.$clave.'.codigo_barras')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                                    @if ($conStockInicial)
                                        <input class="input variante-stock-inicial" type="text" inputmode="decimal" wire:model="stock_combinaciones.{{ $clave }}" placeholder="{{ __('Stock inicial') }}" aria-label="{{ __('Stock inicial de :nombre', ['nombre' => $etiqueta]) }}" data-test="stock-{{ $clave }}" wire:keydown.enter.prevent>
                                        @error('stock_combinaciones.'.$clave)<p class="auth-error" role="alert">{{ $message }}</p>@enderror
                                    @endif
                                @else
                                    <small class="subtle">{{ $existente?->activo ? __('Se quitará') : __('No se crea') }}</small>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @error('combinaciones')<p class="auth-error" role="alert">{{ $message }}</p>@enderror

    @if ($quitadas->isNotEmpty())
        <div class="notice variantes-quitadas" data-test="modelo-quitadas"><x-icon name="info" /><div>
            <b>{{ __('Estas combinaciones dejan el modelo') }}</b>
            <p>{{ $quitadas->pluck('nombre')->join(', ') }}</p>
            <p class="subtle">{{ __('Las que ya tienen movimientos se desactivan y conservan su historial; las que nunca se usaron se eliminan.') }}</p>
        </div></div>
    @endif

    @if ($conAcciones ?? true)
        <div class="product-form-actions">
            <a class="btn ghost" href="{{ $cancelarUrl }}">{{ __('Cancelar') }}</a>
            <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="guardar,imagen" data-test="guardar-modelo">
                <x-icon name="check" /> {{ $textoGuardar }}
            </button>
        </div>
    @endif
</section>
