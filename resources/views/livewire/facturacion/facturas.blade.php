@use('App\Support\Decimal')
<div class="wide-left">
    @php($zona = config('mozistock.business_timezone'))
    <section class="card card-pad">
        <div class="card-head">
            <div class="heading"><span class="heading-icon"><x-icon name="receipt" /></span>{{ __('Facturas emitidas') }}</div>
            <select class="select" wire:model.live="estado" aria-label="{{ __('Filtrar por estado') }}" style="max-width:220px">
                <option value="">{{ __('Todos los estados') }}</option>
                @foreach ($estados as $opcion)
                    <option value="{{ $opcion->value }}">{{ $opcion->etiqueta() }}</option>
                @endforeach
            </select>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Factura') }}</th><th>{{ __('Venta') }}</th><th>{{ __('Comprador') }}</th><th>{{ __('Total') }}</th><th>{{ __('Estado') }}</th><th></th></tr></thead>
                <tbody>
                    @forelse ($facturas as $fila)
                        <tr wire:key="factura-{{ $fila->id }}">
                            <td>
                                <b>{{ $fila->numero ? __('N° :numero', ['numero' => $fila->numero]) : '—' }}</b>
                                <small style="display:block;color:var(--faint);margin-top:4px">{{ ($fila->fecha_emision ?? $fila->created_at)?->timezone($zona)->format('d/m/Y H:i') }}</small>
                            </td>
                            <td>
                                @if ($propia)
                                    <a href="{{ route('ventas.ver', $fila->venta_id) }}" wire:navigate>V-{{ $ventas[$fila->venta_id] ?? $fila->venta_id }}</a>
                                @else
                                    V-{{ $ventas[$fila->venta_id] ?? $fila->venta_id }}
                                @endif
                            </td>
                            <td>{{ $fila->razon_social }}<small style="display:block;color:var(--faint);margin-top:4px">{{ $fila->numero_documento }}</small></td>
                            <td>{{ Decimal::bs($fila->total) }}</td>
                            <td>
                                <span class="badge {{ $fila->estado->insignia() }}" style="white-space:nowrap">{{ $fila->estado->etiqueta() }}</span>
                                @if ($fila->motivo)<small style="display:block;color:var(--faint);margin-top:4px" title="{{ $fila->motivo }}">{{ \Illuminate\Support\Str::limit($fila->motivo, 90) }}</small>@endif
                            </td>
                            <td>
                                @if ($propia && $fila->estado === \App\Enums\EstadoFactura::Validada)
                                    <a class="btn ghost sm" href="{{ route('facturas.imprimir', $fila->id) }}" target="_blank"><x-icon name="print" size="sm" /> {{ __('Imprimir') }}</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="empty">{{ __('Todavía no hay facturas con este filtro.') }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($facturas->hasPages())
            <div class="table-footer">
                <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $facturas->currentPage(), 'lastpage' => $facturas->lastPage()]) }}</span>
                <span class="row">
                    @unless ($facturas->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                    @if ($facturas->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                </span>
            </div>
        @endif
        <div class="divider"></div>
        <div class="notice"><x-icon name="info" /><div><b>{{ __('Cada factura se gestiona desde su venta') }}</b><p>{{ __('Emitir, corregir, reintentar, imprimir y anular se hacen en el detalle de la venta. Una factura bloqueada no detiene la venta.') }}</p></div></div>
    </section>

    <section class="card card-pad">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="status-ok" /></span>{{ __('Preparación para facturar') }}</div></div>
        @foreach ($preparacion as $paso)
            <div class="list-line">
                <div>
                    <b>{{ $paso['punto'] }}</b>
                    <p class="subtle" style="margin:3px 0 0">{{ $paso['detalle'] }}</p>
                </div>
                @if ($paso['listo'])
                    <span class="badge success">{{ __('Listo') }}</span>
                @elseif ($paso['automatico'])
                    <span class="badge info">{{ __('Automático') }}</span>
                @else
                    <span class="badge warning">{{ __('Pendiente') }}</span>
                @endif
            </div>
        @endforeach
        <p class="subtle" style="margin:12px 0 0">{{ __('Con todo listo, confirma una venta: la factura se envía al SIN y, cuando quede validada, aparece el botón para imprimirla o guardarla en PDF.') }}</p>
    </section>
</div>
