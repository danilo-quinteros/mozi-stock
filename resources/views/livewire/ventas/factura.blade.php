@use('App\Support\Decimal')
@use('App\Enums\EstadoFactura')
@php($zona = config('mozistock.business_timezone'))
<div @if ($vigente?->estado === EstadoFactura::Pendiente) wire:poll.5s @endif>
    @if ($habilitada || $vigente || $anteriores->isNotEmpty())
        <section class="card card-pad" style="margin-top:18px">
            <div class="card-head">
                <div class="heading"><span class="heading-icon"><x-icon name="receipt" /></span>{{ __('Factura SIAT') }}</div>
                @if ($vigente)<span class="badge {{ $vigente->estado->insignia() }}">{{ $vigente->estado->etiqueta() }}</span>@endif
            </div>

            @if ($errorSin && ! $formulario && ! $anulandoId && ! $revirtiendoId)
                <div class="notice" role="alert"><x-icon name="status-alert" /><div><b>{{ __('No se completó la operación') }}</b><p>{{ $errorSin }}</p></div></div>
            @endif

            @if ($vigente)
                <div class="list-line"><span class="subtle">{{ __('Factura N°') }}</span><b>{{ $vigente->numero ?? '—' }}</b></div>
                @if ($vigente->fecha_emision)
                    <div class="list-line"><span class="subtle">{{ __('Emisión') }}</span><b>{{ $vigente->fecha_emision->timezone($zona)->format('d/m/Y H:i') }}</b></div>
                @endif
                <div class="list-line"><span class="subtle">{{ __('Comprador') }}</span><b>{{ $vigente->razon_social }} · {{ $vigente->numero_documento }}{{ $vigente->complemento ? '-'.$vigente->complemento : '' }}</b></div>
                <div class="list-line"><span class="subtle">{{ __('Método de pago fiscal') }}</span><b>{{ $metodoPago($vigente->metodo_pago) ?? '—' }}</b></div>
                @if ($vigente->total_precio_neto !== null)
                    <div class="list-line"><span class="subtle">{{ __('Neto · IVA') }}</span><b>{{ Decimal::bs($vigente->total_precio_neto) }} · {{ Decimal::bs($vigente->iva) }}</b></div>
                @endif
                <div class="list-line"><span>{{ __('Monto a pagar') }}</span><b>{{ Decimal::bs($vigente->total) }}</b></div>
                @if ($vigente->cuf)<p class="subtle" style="word-break:break-all;margin:8px 0 0">{{ __('CUF: :cuf', ['cuf' => $vigente->cuf]) }}</p>@endif

                @if ($vigente->motivo)
                    <div class="notice" style="margin-top:12px"><x-icon name="{{ $vigente->estado === EstadoFactura::Pendiente ? 'info' : 'status-alert' }}" /><div>
                        <b>{{ match ($vigente->estado) {
                            EstadoFactura::Bloqueada => __('Factura bloqueada: no se envió al SIN'),
                            EstadoFactura::Pendiente => __('En proceso'),
                            default => __('Respuesta del SIN'),
                        } }}</b>
                        @php($motivos = $vigente->listaMotivos())
                        @if (count($motivos) > 1)
                            <ul style="margin:6px 0 0;padding-left:18px;color:var(--muted);font-size:11px;line-height:1.5;list-style:disc;display:flex;flex-direction:column;gap:4px">
                                @foreach ($motivos as $item)
                                    <li>{{ $item }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p>{{ $motivos[0] ?? $vigente->motivo }}</p>
                        @endif
                        @if ($vigente->estado === EstadoFactura::Bloqueada)<p class="subtle" style="margin-top:8px">{{ __('La venta está confirmada igual: no se ajustó el IVA ni se agregaron descuentos.') }}</p>@endif
                    </div></div>
                @endif

                <div class="action-right" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:14px">
                    @if ($vigente->estado === EstadoFactura::Validada)
                        <a class="btn primary sm" href="{{ route('facturas.imprimir', $vigente) }}" target="_blank"><x-icon name="print" /> {{ __('Imprimir / PDF') }}</a>
                        @if ($enlace($vigente))<a class="btn soft sm" href="{{ $enlace($vigente) }}" target="_blank" rel="noopener noreferrer"><x-icon name="qr" /> {{ __('Ver en el SIN') }}</a>@endif
                        <a class="btn ghost sm" href="{{ route('facturas.xml', $vigente) }}"><x-icon name="download" /> {{ __('XML') }}</a>
                        @if ($puedeAnular && $vigente->dentroDelPlazoDeAnulacion())
                            <button type="button" class="btn ghost sm" wire:click="abrirAnulacion({{ $vigente->id }})">{{ __('Anular factura') }}</button>
                        @endif
                    @elseif ($puedeEmitir && $vigente->estado === EstadoFactura::Pendiente)
                        <button type="button" class="btn soft sm" wire:click="reintentar({{ $vigente->id }})" wire:loading.attr="disabled">{{ __('Consultar al SIN') }}</button>
                    @elseif ($puedeEmitir && $vigente->estado->reintentable())
                        <button type="button" class="btn primary sm" wire:click="reintentar({{ $vigente->id }})" wire:loading.attr="disabled">{{ __('Reintentar') }}</button>
                        <button type="button" class="btn soft sm" wire:click="abrirCorreccion({{ $vigente->id }})">{{ __('Corregir datos y reintentar') }}</button>
                    @endif
                </div>
            @elseif ($habilitada)
                <div class="empty">{{ __('Esta venta no tiene una factura vigente.') }}</div>
                @if ($puedeEmitir && Decimal::comparar($venta->devuelto, '0', 2) === 0)
                    <button type="button" class="btn primary sm" style="margin-top:12px" wire:click="abrirEmision"><x-icon name="receipt" /> {{ __('Emitir factura') }}</button>
                @endif
            @endif

            @if ($anteriores->isNotEmpty())
                <div class="divider"></div>
                <p class="subtle" style="margin:0 0 6px">{{ __('Facturas anteriores de esta venta') }}</p>
                @foreach ($anteriores as $anterior)
                    <div class="list-line" wire:key="factura-{{ $anterior->id }}">
                        <div>
                            <b>{{ __('N° :numero', ['numero' => $anterior->numero ?? '—']) }}</b> · <span class="badge {{ $anterior->estado->insignia() }}">{{ $anterior->estado->etiqueta() }}</span>
                            @if ($anterior->anulada_en)<p class="subtle" style="margin:4px 0 0">{{ __('Anulada el :fecha', ['fecha' => $anterior->anulada_en->timezone($zona)->format('d/m/Y H:i')]) }}</p>@endif
                        </div>
                        @if ($puedeAnular && ! $vigente && $anterior->estado === EstadoFactura::Anulada && $anterior->revertida_en === null && $anterior->dentroDelPlazoDeAnulacion())
                            <button type="button" class="btn ghost sm" wire:click="abrirReversion({{ $anterior->id }})">{{ __('Revertir anulación') }}</button>
                        @endif
                    </div>
                @endforeach
            @endif
        </section>
    @endif

    @if ($formulario)
        <x-modal :titulo="$formulario === 'emitir' ? 'Emitir factura' : 'Corregir datos de la factura'" descripcion="Los datos del comprador y el método de pago van al SIN tal como los escribas." cerrar="cerrar">
            <form wire:submit="guardarFormulario">
                @if ($errorSin)<div class="notice" role="alert"><x-icon name="status-alert" /><div><p>{{ $errorSin }}</p></div></div>@endif
                @include('livewire.facturacion.comprador', ['tiposDocumento' => $tiposDocumento, 'metodosPago' => $metodosPago, 'factura' => $factura])
                <div class="modal-actions">
                    <button type="button" class="btn ghost" wire:click="cerrar">{{ __('Cancelar') }}</button>
                    <button class="btn primary" type="submit" wire:loading.attr="disabled"><x-icon name="check" /> {{ $formulario === 'emitir' ? __('Emitir') : __('Guardar y reintentar') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($anulandoId)
        <x-modal titulo="Anular factura" descripcion="El SIN anula la factura; la venta, la caja y el stock no cambian. Plazo: hasta el día 9 del mes siguiente a la emisión." cerrar="cerrar">
            <form wire:submit="anular">
                @if ($errorSin)<div class="notice" role="alert"><x-icon name="status-alert" /><div><p>{{ $errorSin }}</p></div></div>@endif
                <x-campo etiqueta="Motivo de anulación" campo="motivoAnulacion">
                    <select id="motivoAnulacion" class="select" wire:model="motivoAnulacion" required>
                        <option value="">{{ __('Elige…') }}</option>
                        @foreach ($motivosAnulacion as $motivo)
                            <option value="{{ $motivo->codigo }}">{{ $motivo->descripcion }}</option>
                        @endforeach
                    </select>
                </x-campo>
                <p class="modal-info">{{ __('Después de anular, avisa al comprador. La anulación se puede revertir una sola vez dentro del plazo.') }}</p>
                <div class="modal-actions">
                    <button type="button" class="btn ghost" wire:click="cerrar">{{ __('Volver') }}</button>
                    <button class="btn primary" type="submit" wire:loading.attr="disabled">{{ __('Anular en el SIN') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($revirtiendoId)
        <x-modal titulo="Revertir anulación" descripcion="La factura vuelve a estar validada. El SIN permite revertir una sola vez." cerrar="cerrar">
            @if ($errorSin)<div class="notice" role="alert"><x-icon name="status-alert" /><div><p>{{ $errorSin }}</p></div></div>@endif
            <div class="modal-actions">
                <button type="button" class="btn ghost" wire:click="cerrar">{{ __('Volver') }}</button>
                <button type="button" class="btn primary" wire:click="revertir" wire:loading.attr="disabled">{{ __('Revertir en el SIN') }}</button>
            </div>
        </x-modal>
    @endif

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" />
</div>
