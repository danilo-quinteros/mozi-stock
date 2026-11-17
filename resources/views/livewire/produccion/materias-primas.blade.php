@use('App\Support\Decimal')
@use('App\Domain\Produccion\Consultas\ListadoMateriasPrimas')
{{-- Producción → Materias primas (ESPECIFICACION_PRODUCCION.md § 3 y § 4). --}}
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Producción') }}</div>
            <h1>{{ __('Materias primas') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Materias primas, insumos y envases del almacén de producción. No se venden: solo se compran, se ajustan y se usan en producción.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('compras.crear', ['materia_prima' => 1]) }}" data-test="ingreso-materia-prima"><x-icon name="truck" /> {{ __('Ingreso de materia prima') }}</a>
            <a class="btn primary" href="{{ route('produccion.materias.crear') }}" data-test="nueva-materia-prima"><x-icon name="plus" /> {{ __('Nueva materia prima') }}</a>
        </div>
    </div>

    <div class="grid three" style="margin-bottom:18px">
        <button type="button" class="card stat quick-entry" wire:click="$set('filtro', 'todas')">
            <span class="stat-label">{{ __('Materias primas activas') }}</span>
            <div class="stat-value">{{ $resumen['total'] }}</div>
            <div class="stat-foot">{{ $sucursalVista ? __('Stock de :sucursal', ['sucursal' => $sucursalVista->nombre]) : __('Sin sucursal activa') }}</div>
        </button>
        <button type="button" class="card stat quick-entry" wire:click="$set('filtro', 'sugerencia')" data-test="resumen-bajo-minimo">
            <span class="stat-label">{{ __('Bajo el mínimo') }}</span>
            <div @class(['stat-value', 'danger-text' => $resumen['bajo_minimo'] > 0])>{{ $resumen['bajo_minimo'] }}</div>
            <div class="stat-foot">{{ __('Ver la sugerencia de compra') }}</div>
        </button>
        <button type="button" class="card stat quick-entry" wire:click="$set('filtro', 'sobre_maximo')" data-test="resumen-sobre-maximo">
            <span class="stat-label">{{ __('Sobre el máximo') }}</span>
            <div class="stat-value">{{ $resumen['sobre_maximo'] }}</div>
            <div class="stat-foot">{{ __('Más stock del que conviene tener') }}</div>
        </button>
    </div>

    <section class="card card-pad">
        <div class="card-head">
            <div class="heading"><span class="heading-icon"><x-icon name="box" /></span>{{ $filtroActivo === 'sugerencia' ? __('Sugerencia de compra') : __('Almacén de materias primas') }}</div>
            @if ($filtroActivo === 'sugerencia')
                <a class="btn ghost sm" href="{{ route('produccion.sugerencia.exportar', array_filter(['sucursal' => $sucursalVista?->id])) }}" data-test="exportar-sugerencia"><x-icon name="download" size="sm" /> {{ __('Exportar CSV') }}</a>
            @endif
        </div>
        <div class="tabs" role="tablist">
            @foreach (['todas' => 'Todas', 'sugerencia' => 'Sugerencia de compra', 'sobre_maximo' => 'Sobre el máximo'] as $clave => $etiqueta)
                <button type="button" role="tab" class="tab {{ $filtroActivo === $clave ? 'active' : '' }}" aria-selected="{{ $filtroActivo === $clave ? 'true' : 'false' }}" wire:click="$set('filtro', '{{ $clave }}')">{{ __($etiqueta) }}</button>
            @endforeach
        </div>
        <div class="tool-row">
            <div class="search">
                <x-icon name="search" />
                <input class="input" type="search" wire:model.live.debounce.350ms="buscar" placeholder="{{ __('Buscar por nombre o código') }}" aria-label="{{ __('Buscar materias primas') }}">
            </div>
            <select class="select" wire:model.live="sucursal" @disabled($sucursalFija) aria-label="{{ __('Sucursal del stock') }}">
                @foreach ($sucursales as $opcion)
                    <option value="{{ $opcion->id }}" @selected($sucursalVista?->id === $opcion->id)>{{ $opcion->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('Código') }}</th><th>{{ __('Materia prima') }}</th><th>{{ __('Stock') }}</th><th>{{ __('Mínimo') }}</th><th>{{ __('Máximo') }}</th>
                        @if ($filtroActivo === 'sugerencia')<th>{{ __('Comprar') }}</th>@else<th>{{ __('Costo promedio') }}</th>@endif
                        <th>{{ __('Estado') }}</th><th><span class="sr-only">{{ __('Acción') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($materias as $materia)
                        @php
                            $stock = (string) $materia->stock;
                            $minimo = (string) $materia->minimo;
                            $maximo = $materia->stock_maximo === null ? null : (string) $materia->stock_maximo;
                            $aviso = ListadoMateriasPrimas::aviso($stock, $minimo, $maximo);
                            $unidad = $materia->unidad?->abreviatura;
                        @endphp
                        <tr wire:key="materia-{{ $materia->id }}" @if (! $materia->activo) style="opacity:.6" @endif>
                            <td data-label="{{ __('Código') }}">{{ $materia->sku ?? '—' }}</td>
                            <td data-label="{{ __('Materia prima') }}"><b>{{ $materia->nombre }}</b>@if ($materia->descripcion)<small style="display:block;color:var(--faint);margin-top:4px">{{ \Illuminate\Support\Str::limit($materia->descripcion, 80) }}</small>@endif</td>
                            <td data-label="{{ __('Stock') }}"><b>{{ Decimal::formatear($stock) }}</b> {{ $unidad }}</td>
                            <td data-label="{{ __('Mínimo') }}">{{ Decimal::comparar($minimo, '0') > 0 ? Decimal::formatear($minimo) : '—' }}</td>
                            <td data-label="{{ __('Máximo') }}">{{ $maximo === null ? '—' : Decimal::formatear($maximo) }}</td>
                            @if ($filtroActivo === 'sugerencia')
                                @php($sugerido = ListadoMateriasPrimas::sugerido($stock, $minimo, $maximo))
                                <td data-label="{{ __('Comprar') }}"><b>{{ $sugerido === null ? '—' : Decimal::formatear($sugerido).' '.$unidad }}</b></td>
                            @else
                                <td data-label="{{ __('Costo promedio') }}">{{ Decimal::comparar((string) $materia->costo_promedio, '0', 6) > 0 ? 'Bs '.Decimal::formatear((string) $materia->costo_promedio, 2, 6) : '—' }}</td>
                            @endif
                            <td data-label="{{ __('Estado') }}">
                                @if (! $materia->activo)
                                    <span class="badge">{{ __('Inactiva') }}</span>
                                @else
                                    @switch($aviso)
                                        @case('sin_stock')<span class="badge danger">{{ __('Sin stock') }}</span>@break
                                        @case('bajo')<span class="badge warn">{{ __('Bajo el mínimo') }}</span>@break
                                        @case('sobre')<span class="badge info">{{ __('Sobre el máximo') }}</span>@break
                                        @default<span class="badge success">{{ __('Normal') }}</span>
                                    @endswitch
                                @endif
                            </td>
                            <td data-label="{{ __('Acción') }}"><a class="btn ghost sm" href="{{ route('produccion.materias.editar', $materia) }}"><x-icon name="edit" size="sm" /> {{ __('Editar') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><div class="empty">
                            @if ($filtroActivo === 'todas' && trim($buscar) === '')
                                {{ __('Todavía no hay materias primas. Crea la primera con «Nueva materia prima».') }}
                            @else
                                {{ __('No hay materias primas que coincidan con el filtro.') }}
                            @endif
                        </div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($materias->hasPages())
            <div class="table-footer">
                <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $materias->currentPage(), 'lastpage' => $materias->lastPage()]) }}</span>
                <span class="row">
                    @unless ($materias->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                    @if ($materias->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                </span>
            </div>
        @endif
    </section>
    <p class="subtle" style="margin-top:14px">{{ __('El costo promedio se recalcula con cada ingreso de materia prima (compra). La sugerencia de compra propone llegar al máximo o, si no tiene, al mínimo.') }}</p>

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
