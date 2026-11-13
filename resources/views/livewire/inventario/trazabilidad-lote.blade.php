@use('App\Support\Decimal')
@use('App\Enums\TipoOperacionInventario')
@use('App\Domain\Inventario\Consultas\StockLotes')
<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Trazabilidad de lote') }}</div>
            <h1>{{ StockLotes::etiqueta($datos) }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ $producto }}</p>
        </div>
        <div class="page-actions">
            <a class="btn ghost" href="{{ route('inventario.vencimientos') }}"><x-icon name="arrow-left" /> {{ __('Vencimientos') }}</a>
        </div>
    </div>

    <div class="grid three" style="margin-bottom:18px">
        <article class="card stat">
            <span class="stat-label">{{ __('Estado') }}</span>
            <div class="stat-value" style="font-size:20px">@include('livewire.inventario.partials.estado-lote', ['lote' => $datos])</div>
            <div class="stat-foot">{{ $datos['regularizacion'] ? __('Stock anterior al control por lote') : __('Vence el :fecha', ['fecha' => \App\Domain\Inventario\DatosLote::formatear($datos['vencimiento'])]) }}</div>
        </article>
        <article class="card stat" style="grid-column: span 2">
            <span class="stat-label">{{ __('Stock actual por sucursal') }}</span>
            <div class="stat-foot" data-test="lote-stock">
                @forelse ($stock as $fila)
                    <span class="badge soft" style="margin:4px 6px 0 0">{{ $fila->sucursal }} · {{ Decimal::formatear((string) $fila->cantidad) }}</span>
                @empty
                    {{ __('Sin stock en ninguna sucursal.') }}
                @endforelse
            </div>
        </article>
    </div>

    <section class="card card-pad">
        <div class="card-head"><div class="heading"><span class="heading-icon"><x-icon name="history" /></span>{{ __('Movimientos del lote') }}</div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Fecha') }}</th><th>{{ __('Documento') }}</th><th>{{ __('Cliente o proveedor') }}</th><th>{{ __('Sucursal') }}</th><th>{{ __('Cantidad') }}</th><th>{{ __('Saldo') }}</th><th>{{ __('Usuario') }}</th></tr></thead>
                <tbody>
                    @forelse ($movimientos as $movimiento)
                        @php
                            $tipo = TipoOperacionInventario::from($movimiento->tipo);
                            $codigo = $movimiento->serie.'-'.str_pad((string) $movimiento->numero, 5, '0', STR_PAD_LEFT);
                            $enlace = match (true) {
                                $movimiento->venta_id !== null => route('ventas.ver', $movimiento->venta_id),
                                $movimiento->devolucion_venta_id !== null => route('ventas.ver', $movimiento->devolucion_venta_id),
                                $movimiento->compra_id !== null => route('compras.ver', $movimiento->compra_id),
                                default => null,
                            };
                            $positivo = Decimal::comparar((string) $movimiento->cantidad, '0') > 0;
                            $motivo = TipoOperacionInventario::etiquetaMotivo($movimiento->motivo);
                        @endphp
                        <tr wire:key="movimiento-lote-{{ $movimiento->id }}">
                            <td data-label="{{ __('Fecha') }}">{{ \Illuminate\Support\Carbon::parse($movimiento->created_at, 'UTC')->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i') }}</td>
                            <td data-label="{{ __('Documento') }}"><span class="badge info">{{ $tipo->etiqueta() }}</span>
                                <small style="display:block;margin-top:5px">@if ($enlace)<a class="link" href="{{ $enlace }}">{{ $codigo }}</a>@else{{ $codigo }}@endif{{ $motivo ? ' · '.$motivo : '' }}</small>
                                @if ($movimiento->nota && $enlace === null)<small style="display:block;color:var(--faint);margin-top:3px">{{ $movimiento->nota }}</small>@endif</td>
                            <td data-label="{{ __('Cliente o proveedor') }}">{{ $movimiento->cliente ?? $movimiento->proveedor ?? ($tipo === TipoOperacionInventario::Traspaso && $movimiento->destino ? __('Traspaso a :destino', ['destino' => $movimiento->destino]) : '—') }}</td>
                            <td data-label="{{ __('Sucursal') }}">{{ $movimiento->sucursal }}</td>
                            <td data-label="{{ __('Cantidad') }}" @class(['success-text' => $positivo, 'danger-text' => ! $positivo])>{{ $positivo ? '+' : '−' }}{{ Decimal::formatear(ltrim((string) $movimiento->cantidad, '-')) }}</td>
                            <td data-label="{{ __('Saldo') }}">{{ Decimal::formatear((string) $movimiento->saldo_posterior) }}</td>
                            <td data-label="{{ __('Usuario') }}">{{ $movimiento->usuario }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="empty">{{ __('Este lote todavía no tiene movimientos.') }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($movimientos->hasPages())
            <div class="table-footer">
                <span>{{ __('Página :currentpage de :lastpage', ['currentpage' => $movimientos->currentPage(), 'lastpage' => $movimientos->lastPage()]) }}</span>
                <span class="row">
                    @unless ($movimientos->onFirstPage())<button class="btn ghost sm" type="button" wire:click="previousPage">{{ __('Anterior') }}</button>@endunless
                    @if ($movimientos->hasMorePages())<button class="btn ghost sm" type="button" wire:click="nextPage">{{ __('Siguiente') }}</button>@endif
                </span>
            </div>
        @endif
    </section>
</div>
