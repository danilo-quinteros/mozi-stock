@use('App\Support\Decimal')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Cobros reales') }}</div>
            <h1>{{ __('Caja y arqueo') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('El dinero recibido es distinto del valor vendido.') }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('caja.imprimir', array_filter(['fecha' => $fecha, 'sucursal' => $sucursal, 'metodo' => $metodo])) }}" target="_blank"><x-icon name="print" /> {{ __('Imprimir detalle') }}</a>
            <a class="btn soft" href="{{ route('caja.exportar', array_filter(['fecha' => $fecha, 'sucursal' => $sucursal, 'metodo' => $metodo])) }}"><x-icon name="download" /> {{ __('Exportar arqueo') }}</a>
        </div>
    </div>

    <section class="card card-pad">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="search" /></span>{{ __('Filtros del arqueo') }}</div></div>
        <div class="fields three">
            <div class="field"><label class="field-label" for="fecha">{{ __('Fecha') }}</label><input id="fecha" class="input" type="date" wire:model.live="fecha" max="{{ now()->timezone(config('mozistock.business_timezone'))->format('Y-m-d') }}"></div>
            <div class="field">
                <label class="field-label" for="sucursal">{{ __('Sucursal') }}</label>
                <select id="sucursal" class="select" wire:model.live="sucursal" @disabled($sucursalFija)>
                    <option value="">{{ __('Todas las sucursales') }}</option>
                    @foreach ($sucursales as $opcion)
                        <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="metodo">{{ __('Medio de pago') }}</label>
                <select id="metodo" class="select" wire:model.live="metodo">
                    <option value="">{{ __('Todos') }}</option>
                    <option value="efectivo">{{ __('Efectivo') }}</option>
                    <option value="electronico">{{ __('Electrónico') }}</option>
                </select>
            </div>
        </div>
    </section>

    <div class="grid four" style="margin:18px 0">
        <article class="card stat"><span class="stat-label">{{ __('Efectivo cobrado') }}</span><div class="stat-value">{{ Decimal::bs($resumen['efectivo']) }}</div><div class="stat-foot">{{ __('Cuotas iniciales + abonos') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Electrónico cobrado') }}</span><div class="stat-value">{{ Decimal::bs($resumen['electronico']) }}</div><div class="stat-foot">{{ __('Pagos realmente recibidos') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Total cobrado') }}</span><div class="stat-value">{{ Decimal::bs($resumen['total']) }}</div><div class="stat-foot">{{ __('Sin créditos no cobrados') }}</div></article>
        <article class="card stat"><span class="stat-label">{{ __('Salidas pagadas') }}</span><div class="stat-value">{{ Decimal::bs($resumen['egresos']) }}</div><div class="stat-foot">{{ __('Compras, egresos y reembolsos') }}</div></article>
    </div>

    <div class="wide-left">
        <section class="card card-pad">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="lock" /></span>{{ __('Detalle de caja · :format', ['format' => $dia->desde->format('d/m/Y')]) }}</div></div>
            <div class="list-line"><span>{{ __('Cobros en efectivo') }}</span><b class="success-text">+ {{ Decimal::bs($resumen['efectivo']) }}</b></div>
            <div class="list-line"><span>{{ __('Cobros electrónicos') }}</span><b class="success-text">+ {{ Decimal::bs($resumen['electronico']) }}</b></div>
            <div class="list-line"><span>{{ __('Salidas en efectivo') }}</span><b class="danger-text">− {{ Decimal::bs($resumen['egresos_efectivo']) }}</b></div>
            <div class="list-line"><span>{{ __('Salidas electrónicas') }}</span><b class="danger-text">− {{ Decimal::bs($resumen['egresos_electronico']) }}</b></div>
            <div class="list-line"><span>{{ __('Caja efectivo neto') }}</span><b>{{ Decimal::bs($resumen['neto_efectivo']) }}</b></div>
            <div class="list-line"><span>{{ __('Movimiento electrónico neto') }}</span><b>{{ Decimal::bs($resumen['neto_electronico']) }}</b></div>
            <div class="list-line"><b>{{ __('Movimiento neto de caja') }}</b><strong class="callout-number">{{ Decimal::bs($resumen['neto']) }}</strong></div>
            <div class="divider"></div>
            <div class="notice"><x-icon name="info" /><div><b>{{ __('No es utilidad') }}</b><p>{{ __('El neto de caja representa entradas reales menos salidas reales. No es margen comercial ni ganancia.') }}</p></div></div>
        </section>
        <section class="card card-pad">
            <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="search" /></span>{{ __('Cobros por fecha') }}</div></div>
            <span class="subtle">Siete días hasta el {{ $dia->desde->format('d/m/Y') }}{{ $sucursal !== '' ? __(' · sucursal filtrada') : '' }}</span>
            <div class="chart-space" style="margin-top:13px">
                <div class="chartbars" role="img" aria-label="{{ __('Cobros de los últimos siete días') }}">
                    @foreach ($barras as $barra)
                        <div class="chartbar" data-label="{{ $barra['etiqueta'] }}" title="{{ $barra['fecha'] }}: {{ Decimal::bs($barra['total']) }}"><span style="height:{{ max($barra['alto'], 2) }}%"></span></div>
                    @endforeach
                </div>
            </div>
            <div class="divider"></div>
            <div class="row between" style="margin-bottom:8px"><span class="subtle">{{ __('Arqueo por sucursal') }}</span><span class="badge info">{{ $dia->desde->format('d/m/Y') }}</span></div>
            @foreach ($porSucursal as $fila)
                <div class="list-line" wire:key="arqueo-{{ $fila->id }}">
                    <div><b>{{ $fila->nombre }}</b><p class="subtle" style="margin:5px 0 0">{{ __('Efectivo :efectivo · Electrónico :electronico · Salidas :salidas', ['efectivo' => Decimal::bs((string) $fila->efectivo), 'electronico' => Decimal::bs((string) $fila->electronico), 'salidas' => Decimal::bs((string) $fila->salidas)]) }}</p></div>
                    <div style="text-align:right"><b>{{ Decimal::bs((string) $fila->total) }}</b><p class="subtle" style="margin:5px 0 0">{{ __('Neto :neto', ['neto' => Decimal::bs((string) $fila->neto)]) }}</p></div>
                </div>
            @endforeach
        </section>
    </div>

    <section class="card card-pad" style="margin-top:18px">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="users" /></span>{{ __('Cobros del día') }}</div><span class="subtle">{{ __('Cada cobro figura una sola vez, en la sucursal que lo recibió') }}</span></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Hora') }}</th><th>{{ __('Origen') }}</th><th>{{ __('Cliente') }}</th><th>{{ __('Sucursal') }}</th><th>{{ __('Recibió') }}</th><th>{{ __('Efectivo') }}</th><th>{{ __('Electrónico') }}</th><th>{{ __('Cobrado') }}</th></tr></thead>
                <tbody>
                    @forelse ($cobros as $cobro)
                        <tr wire:key="cobro-{{ $cobro->id }}">
                            <td>{{ \Illuminate\Support\Carbon::parse($cobro->fecha_cobro, 'UTC')->timezone(config('mozistock.business_timezone'))->format('H:i') }}</td>
                            <td><a class="link" href="{{ route('creditos.historial', $cobro->venta_id) }}">{{ $cobro->tipo === 'inicial' ? __('Cobro inicial') : __('Abono') }} · V-{{ str_pad((string) $cobro->venta_numero, 5, '0', STR_PAD_LEFT) }}</a></td>
                            <td>{{ $cobro->cliente ?? __('Consumidor final') }}</td>
                            <td>{{ $cobro->sucursal }}</td>
                            <td>{{ $cobro->recibido_por }}</td>
                            <td>{{ Decimal::bs((string) $cobro->efectivo) }}</td>
                            <td>{{ Decimal::bs((string) $cobro->electronico) }}</td>
                            <td><b>{{ Decimal::bs((string) $cobro->total) }}</b></td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><div class="empty">{{ __('No hay cobros en esta fecha.') }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($cobros->hasPages())
            <div class="table-footer">
                <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $cobros->currentPage(), 'lastpage' => $cobros->lastPage()]) }}</span>
                <span class="row">
                    @unless ($cobros->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage('cobros')">{{ __('Anterior') }}</button>@endunless
                    @if ($cobros->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage('cobros')">{{ __('Siguiente') }}</button>@endif
                </span>
            </div>
        @endif
    </section>

    <section class="card card-pad" style="margin-top:18px">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="download" /></span>{{ __('Salidas del día') }}</div><span class="subtle">{{ __('Compras pagadas, egresos y reembolsos, cada uno una sola vez') }}</span></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Hora') }}</th><th>{{ __('Concepto') }}</th><th>{{ __('Detalle') }}</th><th>{{ __('Sucursal') }}</th><th>{{ __('Registró') }}</th><th>{{ __('Efectivo') }}</th><th>{{ __('Electrónico') }}</th><th>{{ __('Pagado') }}</th></tr></thead>
                <tbody>
                    @forelse ($salidas as $salida)
                        @php($descripcion = \App\Domain\Reportes\Consultas\Salidas::describir($salida))
                        <tr wire:key="salida-{{ $salida->origen_tipo }}-{{ $salida->origen_id }}">
                            <td>{{ \Illuminate\Support\Carbon::parse($salida->fecha_operacion, 'UTC')->timezone(config('mozistock.business_timezone'))->format('H:i') }}</td>
                            <td>
                                @if ($salida->origen_tipo === 'compra')
                                    <a class="link" href="{{ route('compras.ver', $salida->origen_id) }}">{{ $descripcion['concepto'] }} · {{ $descripcion['referencia'] }}</a>
                                @elseif ($salida->origen_tipo === 'devolucion')
                                    <a class="link" href="{{ route('ventas.ver', $salida->venta_id) }}">{{ $descripcion['concepto'] }} · {{ $descripcion['referencia'] }}</a>
                                @else
                                    {{ $descripcion['concepto'] }} · {{ $descripcion['referencia'] }}
                                @endif
                            </td>
                            <td>{{ $descripcion['detalle'] }}</td>
                            <td>{{ $salida->sucursal }}</td>
                            <td>{{ $salida->usuario }}</td>
                            <td>{{ Decimal::bs((string) $salida->efectivo) }}</td>
                            <td>{{ Decimal::bs((string) $salida->electronico) }}</td>
                            <td><b class="danger-text">− {{ Decimal::bs((string) $salida->total) }}</b></td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><div class="empty">{{ __('No hay salidas de dinero en esta fecha.') }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($salidas->hasPages())
            <div class="table-footer">
                <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $salidas->currentPage(), 'lastpage' => $salidas->lastPage()]) }}</span>
                <span class="row">
                    @unless ($salidas->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage('salidas')">{{ __('Anterior') }}</button>@endunless
                    @if ($salidas->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage('salidas')">{{ __('Siguiente') }}</button>@endif
                </span>
            </div>
        @endif
    </section>
</div>
