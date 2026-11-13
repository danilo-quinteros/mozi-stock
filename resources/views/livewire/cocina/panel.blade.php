@use('App\Domain\Despachos\Consultas\PedidosEnCocina')
@use('App\Enums\EstadoEntrega')
@use('App\Support\Decimal')
@php($zona = config('mozistock.business_timezone'))
<div>
    {{-- Cocina § 4.3: cajón lateral sobre la pantalla actual; se actualiza cada 5 s solo mientras está abierto. --}}
    @if ($abierto)
        <div class="cocina-capa" wire:click="cerrar" aria-hidden="true"></div>
        <aside class="cocina-panel" id="cocina-panel" role="dialog" aria-modal="true" aria-labelledby="cocina-panel-titulo"
            wire:poll.visible.5s wire:keydown.escape.window="cerrar"
            x-data x-init="$nextTick(() => $el.querySelector('[data-cocina-cerrar]')?.focus())" data-cocina-panel>
            <header class="cocina-panel-head">
                <h2 id="cocina-panel-titulo">{{ __('En cocina') }} <span class="cocina-panel-total" data-cocina-total>({{ $conteo['total'] }})</span></h2>
                @if ($sucursales->isNotEmpty())
                    <select class="select cocina-sucursal" wire:model.live="sucursal" aria-label="{{ __('Sucursal') }}" data-cocina-sucursal>
                        <option value="">{{ __('Todas las sucursales') }}</option>
                        @foreach ($sucursales as $opcion)
                            <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
                        @endforeach
                    </select>
                @endif
                <button type="button" class="icon-action" wire:click="cerrar" aria-label="{{ __('Cerrar') }}" data-cocina-cerrar><x-icon name="x" size="sm" /></button>
            </header>

            <div class="cocina-panel-cuerpo">
                @if ($detalle)
                    @php($min = PedidosEnCocina::minutos($detalle, $ahora))
                    <div class="cocina-detalle" wire:key="cocina-detalle-{{ $detalle->id }}" data-cocina-detalle="{{ $detalle->id }}">
                        <button type="button" class="btn ghost sm" wire:click="volver"><x-icon name="arrow-left" size="sm" /> {{ __('Volver') }}</button>
                        <b class="cocina-nombre grande">{{ $detalle->nombre_mesa ?? $detalle->codigo() }}</b>
                        <p class="cocina-meta">
                            #{{ $detalle->codigo() }} · {{ $detalle->fecha->timezone($zona)->format('H:i') }}
                            @if ($detalle->estado_entrega === EstadoEntrega::EnCocina)
                                · <span @class(['cocina-tiempo', 'is-demora' => $min > $minutos])>{{ PedidosEnCocina::textoTiempo($min) }}</span>
                            @endif
                            @if ($todasLasSucursales) · {{ $detalle->sucursal->nombre }}@endif
                        </p>
                        @if ($detalle->estado_entrega !== EstadoEntrega::EnCocina)
                            <p><span class="badge {{ $detalle->estado_entrega->insignia() }}">{{ $detalle->estado_entrega->etiqueta() }}</span>
                                @if ($detalle->entregador)<small class="subtle">{{ __('por :usuario', ['usuario' => $detalle->entregador->name]) }}</small>@endif</p>
                        @endif
                        <ul class="cocina-detalle-items">
                            @forelse ($detalle->lineas as $linea)
                                <li><span>{{ $linea['nombre'] }}</span><b>{{ Decimal::formatear($linea['cantidad']) }} {{ $linea['unidad'] }}</b></li>
                            @empty
                                <li><span class="subtle">{{ __('Todos los productos se devolvieron.') }}</span></li>
                            @endforelse
                        </ul>
                        @if ($detalle->pedido?->nota)
                            <div class="notice"><x-icon name="info" /><div><b>{{ __('Nota') }}</b><p>{{ $detalle->pedido->nota }}</p></div></div>
                        @endif
                        <div class="list-line"><span class="subtle">{{ __('Vendedor') }}</span><b>{{ $detalle->vendedor->name }}</b></div>
                        <div class="list-line"><span class="subtle">{{ __('Pago') }}</span>
                            @if (Decimal::comparar($detalle->saldo_pendiente, '0', Decimal::ESCALA_DINERO) > 0)
                                <b class="danger-text" data-cocina-pago>{{ __('Saldo :saldo', ['saldo' => Decimal::bs($detalle->saldo_pendiente)]) }}</b>
                            @else
                                <b class="success-text" data-cocina-pago>{{ __('Pagado') }}</b>
                            @endif
                        </div>
                        @if ($detalle->estado_entrega === EstadoEntrega::EnCocina)
                            <div class="cocina-acciones">
                                <button type="button" class="btn primary" wire:click="entregarPedido({{ $detalle->id }})" wire:loading.attr="disabled"
                                    wire:target="entregarPedido" data-cocina-entregar="{{ $detalle->id }}"><x-icon name="check" size="sm" /> {{ __('Entregar') }}</button>
                            </div>
                        @endif
                    </div>
                @elseif ($pedidos->isEmpty())
                    <div class="empty cocina-vacio" data-cocina-vacio><x-icon name="cocina" /><p>{{ __('No hay pedidos en cocina.') }}</p></div>
                @else
                    <ul class="cocina-lista">
                        @foreach ($pedidos as $pedido)
                            @php($min = PedidosEnCocina::minutos($pedido, $ahora))
                            @php($demora = $min > $minutos)
                            <li @class(['cocina-pedido', 'is-demora' => $demora]) wire:key="cocina-{{ $pedido->id }}" data-cocina-pedido="{{ $pedido->id }}" data-demora="{{ $demora ? '1' : '0' }}">
                                <div class="cocina-pedido-cab">
                                    <b class="cocina-nombre">{{ $pedido->nombre_mesa ?? $pedido->codigo() }}</b>
                                    <span class="cocina-meta">
                                        #{{ $pedido->codigo() }} · {{ $pedido->fecha->timezone($zona)->format('H:i') }} ·
                                        <span @class(['cocina-tiempo', 'is-demora' => $demora])>{{ PedidosEnCocina::textoTiempo($min) }}</span>
                                        @if ($demora)<x-icon name="status-alert" size="sm" /><span class="cocina-oculto">{{ __('con demora') }}</span>@endif
                                    </span>
                                </div>
                                @if ($todasLasSucursales)<small class="cocina-sucursal-nombre">{{ $pedido->sucursal->nombre }}</small>@endif
                                <p class="cocina-items">{{ PedidosEnCocina::resumen($pedido->lineas) }}</p>
                                <div class="cocina-acciones">
                                    <button type="button" class="btn ghost sm" wire:click="ver({{ $pedido->id }})" data-cocina-ver="{{ $pedido->id }}">{{ __('Ver') }}</button>
                                    <button type="button" class="btn primary sm" wire:click="entregarPedido({{ $pedido->id }})" wire:loading.attr="disabled"
                                        wire:target="entregarPedido" data-cocina-entregar="{{ $pedido->id }}"><x-icon name="check" size="sm" /> {{ __('Entregar') }}</button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @if ($conteo['total'] > $pedidos->count())
                        <p class="subtle cocina-limite">{{ __('Se muestran los :n más antiguos de :total. Entrégalos para ver los siguientes.', ['n' => $pedidos->count(), 'total' => $conteo['total']]) }}</p>
                    @endif
                @endif
            </div>
        </aside>
    @endif

    <x-aviso-deshacer :deshacer="$deshacer" />
    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" :tipo="$avisoTipo" />
</div>
