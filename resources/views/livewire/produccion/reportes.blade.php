@use('App\Support\Decimal')
@use('App\Domain\Produccion\Consultas\ReporteProduccion')
{{-- Producción → Reportes (ESPECIFICACION_PRODUCCION.md § 12, P4): órdenes finalizadas en el período. --}}
@php
    $zona = config('mozistock.business_timezone');
    // Rendimiento (obtenido ÷ planificado) con su merma o excedente; null si no se puede calcular.
    $rendimiento = function (string $planificada, string $obtenida): ?array {
        $valor = ReporteProduccion::rendimiento($planificada, $obtenida);

        return $valor === null ? null : ['valor' => $valor, 'diferencia' => Decimal::restar($valor, '100', 1)];
    };
    $unitario = fn (?string $costo): string => $costo === null ? '—' : 'Bs '.Decimal::formatear(Decimal::redondear($costo, 4), 2, 4);
@endphp
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Producción') }}</div>
            <h1>{{ __('Reportes de producción') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Lo producido en el período, su rendimiento, su costo y el consumo de materias primas.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn soft" href="{{ route('produccion.reportes.exportar', $filtrosCsv) }}" data-test="exportar-reporte"><x-icon name="download" /> {{ __('Exportar CSV') }}</a>
        </div>
    </div>

    <section class="card card-pad">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="search" /></span>{{ __('Filtros') }}</div></div>
        <div class="fields three">
            <div class="field"><label class="field-label" for="desde">{{ __('Finalizadas desde') }}</label><input id="desde" class="input" type="date" wire:model.live="desde"></div>
            <div class="field"><label class="field-label" for="hasta">{{ __('Finalizadas hasta') }}</label><input id="hasta" class="input" type="date" wire:model.live="hasta"></div>
            <div class="field">
                <label class="field-label" for="sucursal">{{ __('Sucursal') }}</label>
                <select id="sucursal" class="select" wire:model.live="sucursal" @disabled($sucursalFija)>
                    <option value="">{{ __('Todas las sucursales') }}</option>
                    @foreach ($sucursales as $opcion)
                        <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </section>

    <div class="grid three" style="margin:18px 0">
        <article class="card stat" data-test="resumen-ordenes"><span class="stat-label">{{ __('Órdenes finalizadas') }}</span><div class="stat-value">{{ $resumen['ordenes'] }}</div><div class="stat-foot">{{ __('Por fecha de finalización') }}</div></article>
        <article class="card stat" data-test="resumen-costo"><span class="stat-label">{{ __('Costo de producción') }}</span><div class="stat-value">{{ Decimal::bs($resumen['costo']) }}</div><div class="stat-foot">{{ __('Consumo real × costo al aprobar') }}</div></article>
        <article class="card stat" data-test="resumen-merma"><span class="stat-label">{{ __('Órdenes con merma') }}</span><div class="stat-value">{{ $resumen['con_merma'] }}</div><div class="stat-foot">{{ __('Se obtuvo menos de lo planificado') }}</div></article>
    </div>

    <section class="card card-pad">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="chart" /></span>{{ __('Reportes') }}</div><span class="subtle">{{ $periodo->desde->format('d/m/Y') }}{{ $periodo->esUnDia() ? '' : ' al '.$periodo->hasta->format('d/m/Y') }}</span></div>
        <div class="tabs" role="tablist">
            @foreach (['productos' => 'Por producto', 'ordenes' => 'Por orden', 'materias' => 'Materias primas'] as $clave => $etiqueta)
                <button type="button" role="tab" class="tab {{ $pestanaActiva === $clave ? 'active' : '' }}" aria-selected="{{ $pestanaActiva === $clave ? 'true' : 'false' }}" wire:click="$set('pestana', '{{ $clave }}')">{{ __($etiqueta) }}</button>
            @endforeach
        </div>

        <div class="notice"><x-icon name="info" /><div>
            @if ($pestanaActiva === 'materias')
                <b>{{ __('Teórico contra usado') }}</b><p>{{ __('Teórico es lo de la receta escalada a cada orden; usado, el consumo real registrado al finalizar. El costo es lo usado por el costo guardado al aprobar.') }}</p>
            @else
                <b>{{ __('Rendimiento') }}</b><p>{{ __('Lo obtenido sobre lo planificado: 100 % es exacto, menos es merma y más es excedente. El costo unitario es el costo de la orden entre lo obtenido.') }}</p>
            @endif
            <p class="subtle" style="margin:6px 0 0">{{ __('Solo órdenes finalizadas en el período. Lo descontado por órdenes aprobadas sin finalizar se ve en Ajustes y traspasos, tipo Producción.') }}</p>
        </div></div>
        <div style="height:16px"></div>
        <div class="tool-row">
            <div class="search">
                <x-icon name="search" />
                <input class="input" type="search" wire:model.live.debounce.350ms="buscar" aria-label="{{ __('Buscar en el reporte') }}"
                    placeholder="{{ $pestanaActiva === 'materias' ? __('Buscar materia prima o código...') : __('Buscar producto o número de orden...') }}">
            </div>
            <a class="btn ghost sm" href="{{ route('produccion.reportes.exportar', $filtrosCsv) }}"><x-icon name="download" size="sm" /> {{ __('Exportar CSV') }}</a>
        </div>

        <div class="table-wrap">
            <table>
                @if ($pestanaActiva === 'ordenes')
                    <thead><tr><th>{{ __('Orden') }}</th><th>{{ __('Finalizada') }}</th><th>{{ __('Producto') }}</th><th>{{ __('Sucursal') }}</th><th>{{ __('Planificado') }}</th><th>{{ __('Obtenido') }}</th><th>{{ __('Rendimiento') }}</th><th>{{ __('Costo') }}</th><th>{{ __('Costo unitario') }}</th></tr></thead>
                    <tbody>
                        @forelse ($filas as $fila)
                            @php
                                $r = $rendimiento((string) $fila->planificada, (string) $fila->obtenida);
                            @endphp
                            <tr wire:key="rp-orden-{{ $fila->id }}">
                                <td data-label="{{ __('Orden') }}"><a class="link" href="{{ route('produccion.ordenes.ver', $fila->id) }}"><b>{{ \App\Models\OrdenProduccion::formatearCodigo((int) $fila->numero) }}</b></a></td>
                                <td data-label="{{ __('Finalizada') }}">{{ \Illuminate\Support\Carbon::parse($fila->finalizada_en, 'UTC')->timezone($zona)->format('d/m/Y H:i') }}</td>
                                <td data-label="{{ __('Producto') }}"><b>{{ $fila->nombre }}</b>@if ($fila->sku)<small style="display:block;color:var(--faint);margin-top:4px">{{ $fila->sku }}</small>@endif</td>
                                <td data-label="{{ __('Sucursal') }}">{{ $fila->sucursal }}</td>
                                <td data-label="{{ __('Planificado') }}">{{ Decimal::formatear((string) $fila->planificada) }} {{ $fila->unidad }}</td>
                                <td data-label="{{ __('Obtenido') }}">{{ Decimal::formatear((string) $fila->obtenida) }} {{ $fila->unidad }}</td>
                                <td data-label="{{ __('Rendimiento') }}">@include('livewire.produccion.partials.rendimiento', ['r' => $r])</td>
                                <td data-label="{{ __('Costo') }}">{{ Decimal::bs(Decimal::redondear((string) $fila->costo_total)) }}</td>
                                <td data-label="{{ __('Costo unitario') }}">{{ $unitario((string) $fila->costo_unitario) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9"><div class="empty">{{ __('Sin órdenes finalizadas en el período.') }}</div></td></tr>
                        @endforelse
                    </tbody>
                @elseif ($pestanaActiva === 'materias')
                    <thead><tr><th>{{ __('Materia prima') }}</th><th>{{ __('Órdenes') }}</th><th>{{ __('Teórico') }}</th><th>{{ __('Usado') }}</th><th>{{ __('Diferencia') }}</th><th>{{ __('Costo') }}</th></tr></thead>
                    <tbody>
                        @forelse ($filas as $fila)
                            @php
                                $diferencia = Decimal::restar((string) $fila->usada, (string) $fila->teorica);
                                $desvio = ReporteProduccion::desvio((string) $fila->teorica, (string) $fila->usada);
                                $signo = Decimal::comparar($diferencia, '0');
                            @endphp
                            <tr wire:key="rp-materia-{{ $fila->id }}">
                                <td data-label="{{ __('Materia prima') }}"><b>{{ $fila->nombre }}</b>@if ($fila->sku)<small style="display:block;color:var(--faint);margin-top:4px">{{ $fila->sku }}</small>@endif</td>
                                <td data-label="{{ __('Órdenes') }}">{{ $fila->ordenes }}</td>
                                <td data-label="{{ __('Teórico') }}">{{ Decimal::formatear((string) $fila->teorica) }} {{ $fila->unidad }}</td>
                                <td data-label="{{ __('Usado') }}">{{ Decimal::formatear((string) $fila->usada) }} {{ $fila->unidad }}</td>
                                <td data-label="{{ __('Diferencia') }}">
                                    @if ($signo === 0)
                                        —
                                    @else
                                        <span @class(['danger-text' => $signo > 0, 'success-text' => $signo < 0])>{{ $signo > 0 ? '+' : '' }}{{ Decimal::formatear($diferencia) }} {{ $fila->unidad }}</span>
                                        @if ($desvio !== null)<small style="display:block;color:var(--faint);margin-top:4px">{{ $signo > 0 ? __(':porcentaje % más de lo teórico', ['porcentaje' => Decimal::formatear($desvio, 1, 1)]) : __(':porcentaje % menos de lo teórico', ['porcentaje' => Decimal::formatear(ltrim($desvio, '-'), 1, 1)]) }}</small>@endif
                                    @endif
                                </td>
                                <td data-label="{{ __('Costo') }}">{{ Decimal::bs(Decimal::redondear((string) $fila->costo)) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><div class="empty">{{ __('Sin consumo de materias primas en el período.') }}</div></td></tr>
                        @endforelse
                    </tbody>
                @else
                    <thead><tr><th>{{ __('Producto') }}</th><th>{{ __('Órdenes') }}</th><th>{{ __('Planificado') }}</th><th>{{ __('Obtenido') }}</th><th>{{ __('Rendimiento') }}</th><th>{{ __('Costo') }}</th><th>{{ __('Costo unitario') }}</th></tr></thead>
                    <tbody>
                        @forelse ($filas as $fila)
                            @php
                                $r = $rendimiento((string) $fila->planificada, (string) $fila->obtenida);
                            @endphp
                            <tr wire:key="rp-producto-{{ $fila->id }}">
                                <td data-label="{{ __('Producto') }}"><b>{{ $fila->nombre }}</b>@if ($fila->sku)<small style="display:block;color:var(--faint);margin-top:4px">{{ $fila->sku }}</small>@endif</td>
                                <td data-label="{{ __('Órdenes') }}">{{ $fila->ordenes }}</td>
                                <td data-label="{{ __('Planificado') }}">{{ Decimal::formatear((string) $fila->planificada) }} {{ $fila->unidad }}</td>
                                <td data-label="{{ __('Obtenido') }}">{{ Decimal::formatear((string) $fila->obtenida) }} {{ $fila->unidad }}</td>
                                <td data-label="{{ __('Rendimiento') }}">@include('livewire.produccion.partials.rendimiento', ['r' => $r])</td>
                                <td data-label="{{ __('Costo') }}">{{ Decimal::bs(Decimal::redondear((string) $fila->costo_total)) }}</td>
                                <td data-label="{{ __('Costo unitario') }}">{{ $unitario(ReporteProduccion::costoUnitario((string) $fila->costo_total, (string) $fila->obtenida)) }}<small style="display:block;color:var(--faint);margin-top:4px">{{ __('por :unidad', ['unidad' => $fila->unidad]) }}</small></td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><div class="empty">{{ __('Sin órdenes finalizadas en el período.') }}</div></td></tr>
                        @endforelse
                    </tbody>
                @endif
            </table>
        </div>
        @if ($filas->hasPages())
            <div class="table-footer">
                <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $filas->currentPage(), 'lastpage' => $filas->lastPage()]) }}</span>
                <span class="row">
                    @unless ($filas->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                    @if ($filas->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                </span>
            </div>
        @endif
    </section>
</div>
