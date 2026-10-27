@use('App\Support\Decimal')
@use('App\Domain\Catalogo\Consultas\ListadoProductos')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Análisis') }}</div>
            <h1>{{ __('Reportes generales') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Indicadores claros, filtros y exportaciones CSV.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn soft" href="{{ route('reportes.exportar', $filtrosCsv) }}"><x-icon name="download" /> {{ __('Exportar CSV') }}</a>
        </div>
    </div>

    <section class="card card-pad">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="search" /></span>{{ __('Filtros generales') }}</div></div>
        <div class="fields three">
            <div class="field"><label class="field-label" for="desde">{{ __('Fecha desde') }}</label><input id="desde" class="input" type="date" wire:model.live="desde"></div>
            <div class="field"><label class="field-label" for="hasta">{{ __('Fecha hasta') }}</label><input id="hasta" class="input" type="date" wire:model.live="hasta"></div>
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

    <div class="grid four" style="margin:18px 0">
        <article class="card stat"><span class="stat-label">{{ __('Cobros reales') }}</span><div class="stat-value">{{ Decimal::bs($cobros['total']) }}</div><div class="stat-foot">{{ __('Por fecha efectiva del pago') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Valor vendido') }}</span><div class="stat-value">{{ Decimal::bs($ventas['valor_vendido']) }}</div><div class="stat-foot">{{ __(':ventas ventas · métrica comercial separada', ['ventas' => $ventas['cantidad']]) }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Pendiente por cobrar') }}</span><div class="stat-value">{{ Decimal::bs($pendientes['pendiente']) }}</div><div class="stat-foot">{{ __('A hoy · no se trata como ingreso') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Cotizaciones pendientes') }}</span><div class="stat-value">{{ $pendientes['cotizaciones'] }}</div><div class="stat-foot">{{ __('Siempre excluidas de ingresos') }}</div></article>
    </div>

    <section class="card card-pad">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="store" /></span>{{ __('Reportes por categoría') }}</div><span class="subtle">{{ $periodo->desde->format('d/m/Y') }}{{ $periodo->esUnDia() ? '' : ' al '.$periodo->hasta->format('d/m/Y') }}</span></div>
        <div class="tabs" role="tablist">
            @foreach (['cobros' => 'Cobros', 'ventas' => 'Ventas', 'inventario' => 'Inventario', 'creditos' => 'Créditos'] as $clave => $etiqueta)
                <button type="button" role="tab" class="tab {{ $pestana === $clave ? 'active' : '' }}" aria-selected="{{ $pestana === $clave ? 'true' : 'false' }}" wire:click="$set('pestana', '{{ $clave }}')">{{ __($etiqueta) }}</button>
            @endforeach
        </div>

        @if ($pestana === 'cobros')
            <div class="notice"><x-icon name="info" /><div><b>{{ __('Cobros e ingresos') }}</b><p>{{ __('Incluye cuotas iniciales y abonos según la fecha de recepción. Una operación figura una sola vez. Efectivo :cobros · Electrónico :cobros2.', ['cobros' => Decimal::bs($cobros['efectivo']), 'cobros2' => Decimal::bs($cobros['electronico'])]) }}</p></div></div>
        @elseif ($pestana === 'ventas')
            <div class="notice"><x-icon name="info" /><div><b>{{ __('Vendido ≠ cobrado') }}</b><p>{{ __('El valor de venta se muestra como dato comercial; no se suma a ingresos ni a caja. Las fechas son de confirmación de la venta.') }}</p></div></div>
        @elseif ($pestana === 'inventario')
            <div class="notice"><x-icon name="info" /><div><b>{{ __('Stock actual por sucursal') }}</b><p>{{ __('Existencias a hoy (no dependen del rango de fechas). Las alertas usan el mínimo de cada sucursal o el general del producto.') }}</p></div></div>
        @else
            <div class="notice"><x-icon name="info" /><div><b>{{ __('Saldos por cobrar a hoy') }}</b><p>{{ __('Los abonos cuentan como cobros en el día en que se recibieron, no en la fecha de la venta.') }}</p></div></div>
        @endif
        <div style="height:16px"></div>
        <div class="tool-row">
            <div class="search">
                <x-icon name="search" />
                <input class="input" type="search" wire:model.live.debounce.350ms="buscar" aria-label="{{ __('Buscar en el reporte') }}"
                    placeholder="{{ $pestana === 'inventario' ? __('Buscar producto o código...') : __('Buscar venta, cliente o usuario...') }}">
            </div>
            @if ($pestana === 'inventario')
                <label class="row" style="font-size:12px"><input type="checkbox" wire:model.live="soloAlertas"> {{ __('Solo en o bajo el mínimo') }}</label>
            @endif
            <a class="btn ghost sm" href="{{ route('reportes.exportar', $filtrosCsv) }}"><x-icon name="download" size="sm" /> {{ __('Exportar CSV') }}</a>
        </div>

        <div class="table-wrap">
            <table>
                @if ($pestana === 'cobros')
                    <thead><tr><th>{{ __('Fecha de cobro') }}</th><th>{{ __('Origen') }}</th><th>{{ __('Cliente') }}</th><th>{{ __('Sucursal / recibió') }}</th><th>{{ __('Vendedor') }}</th><th>{{ __('Efectivo') }}</th><th>{{ __('Electrónico') }}</th><th>{{ __('Cobrado') }}</th></tr></thead>
                    <tbody>
                        @forelse ($filas as $fila)
                            <tr wire:key="r-cobro-{{ $fila->id }}">
                                <td>{{ \Illuminate\Support\Carbon::parse($fila->fecha_cobro, 'UTC')->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i') }}</td>
                                <td>{{ $fila->tipo === 'inicial' ? __('Cuota inicial') : __('Abono') }} · V-{{ str_pad((string) $fila->venta_numero, 5, '0', STR_PAD_LEFT) }}</td>
                                <td>{{ $fila->cliente ?? __('Consumidor final') }}</td>
                                <td>{{ $fila->sucursal }}<small style="display:block;color:var(--faint);margin-top:4px">{{ $fila->recibido_por }}</small></td>
                                <td>{{ $fila->vendedor }}</td>
                                <td>{{ Decimal::bs((string) $fila->efectivo) }}</td>
                                <td>{{ Decimal::bs((string) $fila->electronico) }}</td>
                                <td><b>{{ Decimal::bs((string) $fila->total) }}</b></td>
                            </tr>
                        @empty
                            <tr><td colspan="8"><div class="empty">{{ __('Sin cobros en el período.') }}</div></td></tr>
                        @endforelse
                    </tbody>
                @elseif ($pestana === 'ventas')
                    <thead><tr><th>{{ __('Fecha venta') }}</th><th>{{ __('Venta') }}</th><th>{{ __('Cliente') }}</th><th>{{ __('Vendedor') }}</th><th>{{ __('Valor vendido') }}</th><th>{{ __('Cobrado a hoy') }}</th><th>{{ __('Saldo pendiente') }}</th></tr></thead>
                    <tbody>
                        @forelse ($filas as $fila)
                            <tr wire:key="r-venta-{{ $fila->id }}">
                                <td>{{ \Illuminate\Support\Carbon::parse($fila->fecha, 'UTC')->timezone(config('mozistock.business_timezone'))->format('d/m/Y') }}</td>
                                <td><a class="link" href="{{ route('ventas.ver', $fila->id) }}">V-{{ str_pad((string) $fila->numero, 5, '0', STR_PAD_LEFT) }}</a><small style="display:block;color:var(--faint);margin-top:4px">{{ $fila->sucursal }}</small></td>
                                <td>{{ $fila->cliente ?? __('Consumidor final') }}</td>
                                <td>{{ $fila->vendedor }}</td>
                                <td>{{ Decimal::bs((string) $fila->total) }}</td>
                                <td>{{ Decimal::bs((string) $fila->cobrado) }}</td>
                                <td>{{ Decimal::bs((string) $fila->saldo_pendiente) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><div class="empty">{{ __('Sin ventas confirmadas en el período.') }}</div></td></tr>
                        @endforelse
                    </tbody>
                @elseif ($pestana === 'inventario')
                    <thead><tr><th>{{ __('Producto') }}</th><th>{{ __('Sucursal') }}</th><th>{{ __('Existencias') }}</th><th>{{ __('Mínimo') }}</th><th>{{ __('Estado') }}</th></tr></thead>
                    <tbody>
                        @forelse ($filas as $fila)
                            @php($alerta = ListadoProductos::alerta((string) $fila->stock, (string) $fila->minimo))
                            <tr wire:key="r-inv-{{ $fila->id }}-{{ $loop->index }}">
                                <td><b>{{ $fila->nombre }}</b><small style="display:block;color:var(--faint);margin-top:4px">{{ $fila->sku }}</small></td>
                                <td>{{ $fila->sucursal }}</td>
                                <td>{{ Decimal::formatear((string) $fila->stock) }} {{ $fila->abreviatura }}</td>
                                <td>{{ Decimal::formatear((string) $fila->minimo) }}</td>
                                <td><span class="badge {{ $alerta === 'normal' ? 'info' : 'danger' }}">{{ ['sin_stock' => __('Sin stock'), 'bajo' => __('Stock bajo'), 'normal' => __('Normal')][$alerta] }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><div class="empty">{{ __('Sin productos para mostrar.') }}</div></td></tr>
                        @endforelse
                    </tbody>
                @else
                    <thead><tr><th>{{ __('Venta') }}</th><th>{{ __('Fecha venta') }}</th><th>{{ __('Cliente') }}</th><th>{{ __('Sucursal') }}</th><th>{{ __('Vendido') }}</th><th>{{ __('Cobrado') }}</th><th>{{ __('Saldo') }}</th></tr></thead>
                    <tbody>
                        @forelse ($filas as $fila)
                            <tr wire:key="r-cred-{{ $fila->id }}">
                                <td><a class="link" href="{{ route('creditos.historial', $fila->id) }}">V-{{ str_pad((string) $fila->numero, 5, '0', STR_PAD_LEFT) }}</a></td>
                                <td>{{ \Illuminate\Support\Carbon::parse($fila->fecha, 'UTC')->timezone(config('mozistock.business_timezone'))->format('d/m/Y') }}</td>
                                <td>{{ $fila->cliente ?? __('Sin cliente') }}</td>
                                <td>{{ $fila->sucursal }}</td>
                                <td>{{ Decimal::bs((string) $fila->total) }}</td>
                                <td>{{ Decimal::bs((string) $fila->cobrado) }}</td>
                                <td><b class="accent-text">{{ Decimal::bs((string) $fila->saldo_pendiente) }}</b></td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><div class="empty">{{ __('No hay saldos pendientes.') }}</div></td></tr>
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
