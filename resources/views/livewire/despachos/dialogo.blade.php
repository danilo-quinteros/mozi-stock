@use('App\Support\Decimal')
{{-- Diálogos de entrega compartidos por la pestaña Despachos y el detalle de venta (GestionaEntregas). --}}
@if ($dialogo)
    @php
        $venta = $dialogo['venta'];
        [$titulo, $descripcion, $boton] = match ($entregaDialogo) {
            'despachar' => [__('Despachar venta'), __('Autoriza la salida del almacén. No mueve stock ni dinero: el stock ya se descontó al vender.'), __('Confirmar despacho')],
            'despachar_entregar' => [__('Despachar y entregar'), __('El cliente retira en el almacén: se registran el despacho y la entrega con tu nombre.'), __('Despachar y entregar')],
            'entregar' => [__('Marcar entregado'), __('Confirma que el cliente recibió los productos.'), __('Marcar entregado')],
            'corregir' => [__('Deshacer último cambio'), __('Solo el administrador. El cambio y tu nota quedan en el historial de la venta.'), __('Deshacer cambio')],
            default => [__('Cancelar venta despachada'), __('Se devolvieron todos los productos antes de entregarlos.'), __('Cancelar venta')],
        };
        // Lo calcula CorregirEstadoEntrega: Despachado, Por despachar o En cocina (cocina § 5.3).
        $destino = $dialogo['destino'] ?? null;
    @endphp
    <x-modal :titulo="$titulo" :descripcion="$descripcion" cerrar="cerrarEntrega" data-dialogo-entrega="{{ $entregaDialogo }}">
        <div class="quote-line"><span>{{ __('Venta') }}</span><strong>{{ $venta->codigo() }}</strong></div>
        <div class="quote-line"><span>{{ __('Cliente') }}</span><strong>{{ $venta->cliente->nombre ?? __('Consumidor final') }}</strong></div>

        @if (in_array($entregaDialogo, ['despachar', 'despachar_entregar'], true))
            <p class="modal-info" style="margin:16px 0 8px"><b>{{ __('Productos a entregar') }}</b></p>
            @if ($dialogo['lineas'] === [])
                <p class="modal-info">{{ __('No queda nada por entregar: todo fue devuelto.') }}</p>
            @else
                <ul class="entrega-lineas">
                    @foreach ($dialogo['lineas'] as $linea)
                        <li><span>{{ $linea['nombre'] }}</span><b>{{ Decimal::formatear($linea['cantidad']) }} {{ $linea['unidad'] }}</b></li>
                    @endforeach
                </ul>
            @endif
        @endif

        <div class="entrega-campos">
            @if ($entregaDialogo === 'despachar')
                <x-campo etiqueta="Retira (opcional)" campo="retira">
                    <input id="retira" class="input" type="text" wire:model="entregaRetira" maxlength="100" placeholder="{{ __('Nombre de quien se lleva la mercadería') }}" autocomplete="off">
                </x-campo>
            @endif

            @if (in_array($entregaDialogo, ['despachar_entregar', 'entregar'], true))
                <x-campo etiqueta="Recibió (opcional)" campo="recibio">
                    <input id="recibio" class="input" type="text" wire:model="entregaRecibio" maxlength="100" placeholder="{{ __('Nombre de quien recibe') }}" autocomplete="off">
                </x-campo>
                <x-campo etiqueta="Nota (opcional)" campo="nota">
                    <textarea id="nota" class="input" rows="2" wire:model="entregaNota" maxlength="250"></textarea>
                </x-campo>
                <div class="notice">
                    <x-icon name="info" />
                    <div>
                        <b>{{ __('Entregar no registra pagos') }}</b>
                        <p>
                            @if (Decimal::comparar($venta->saldo_pendiente, '0', 2) > 0)
                                {{ __('Saldo pendiente :saldo. Si el cliente paga al recibir, registra el abono aparte.', ['saldo' => Decimal::bs($venta->saldo_pendiente)]) }}
                            @else
                                {{ __('La venta no tiene saldo pendiente.') }}
                            @endif
                            <a class="link" href="{{ route('creditos.historial', $venta) }}">{{ __('Historial de pagos') }} <x-icon name="arrow" size="sm" /></a>
                        </p>
                    </div>
                </div>
            @endif

            @if ($entregaDialogo === 'corregir' && $destino !== null)
                <p class="modal-info">{!! __('La venta volverá de <b>:desde</b> a <b>:hasta</b>.', ['desde' => e($venta->estado_entrega->etiqueta()), 'hasta' => e($destino->etiqueta())]) !!}</p>
            @endif

            @if (in_array($entregaDialogo, ['corregir', 'cancelar'], true))
                <x-campo etiqueta="Nota (obligatoria)" campo="nota">
                    <textarea id="nota" class="input" rows="2" wire:model="entregaNota" maxlength="250" placeholder="{{ __('Explica el motivo del cambio') }}"></textarea>
                </x-campo>
            @endif
        </div>

        <div class="modal-actions">
            <button type="button" class="btn ghost" wire:click="cerrarEntrega">{{ __('Volver') }}</button>
            <button type="button" class="btn primary" wire:click="confirmarEntrega" wire:loading.attr="disabled" wire:target="confirmarEntrega" data-confirmar-entrega><x-icon name="check" /> {{ $boton }}</button>
        </div>
    </x-modal>
@endif
