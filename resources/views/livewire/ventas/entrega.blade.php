@use('App\Enums\EstadoEntrega')
@use('App\Domain\Despachos\HistorialEntrega')
@php
    $estado = $venta->estado_entrega;
    $botones = [
        'despachar' => [__('Despachar'), 'primary'],
        'despachar_entregar' => [__('Despachar y entregar'), 'soft'],
        'entregar' => [__('Marcar entregado'), 'primary'],
        'entregar_pedido' => [__('Marcar entregado'), 'primary'],
        'corregir' => [$estado === EstadoEntrega::Entregado ? __('Deshacer entrega') : __('Deshacer despacho'), 'ghost'],
        'cancelar' => [__('Cancelar venta'), 'ghost'],
    ];
    $zona = config('mozistock.business_timezone');
@endphp
<section class="card card-pad" style="margin-top:18px" data-entrega-venta>
    <div class="entrega-cabecera">
        <div class="heading"><span class="heading-icon"><x-icon name="box" /></span>{{ __('Entrega') }} <span class="badge {{ $estado->insignia() }}" data-estado-entrega>{{ $estado->etiqueta() }}</span></div>
        @if ($acciones !== [])
            <div class="entrega-acciones">
                @foreach ($acciones as $accion)
                    @if ($accion === 'entregar_pedido')
                        {{-- Cocina § 4.3: un solo clic, sin diálogo; el segundo clic de un doble envío no registra nada. --}}
                        <button type="button" class="btn {{ $botones[$accion][1] }} sm" wire:click="entregar" wire:loading.attr="disabled" wire:target="entregar" data-entregar-pedido>{{ $botones[$accion][0] }}</button>
                    @else
                        <button type="button" class="btn {{ $botones[$accion][1] }} sm" wire:click="abrir('{{ $accion }}')">{{ $botones[$accion][0] }}</button>
                    @endif
                @endforeach
            </div>
        @endif
    </div>

    @if ($venta->despachado_por !== null || $venta->entregado_por !== null)
        <div class="list-line" style="padding-top:0">
            <div class="subtle">
                @if ($venta->despachado_por !== null)
                    {{ __('Despachó :usuario :momento', ['usuario' => $venta->despachador->name ?? '', 'momento' => HistorialEntrega::momento($venta->despachado_en)]) }}@if ($venta->despacho_retira) · {{ __('Retira: :nombre', ['nombre' => $venta->despacho_retira]) }}@endif
                @endif
                @if ($venta->entregado_por !== null)
                    @if ($venta->despachado_por !== null)<br>@endif
                    {{ __('Entregó :usuario :momento', ['usuario' => $venta->entregador->name ?? '', 'momento' => HistorialEntrega::momento($venta->entregado_en)]) }}@if ($venta->entrega_recibio) · {{ __('Recibió: :nombre', ['nombre' => $venta->entrega_recibio]) }}@endif
                @endif
            </div>
        </div>
    @endif

    @if ($venta->eventosEntrega->isEmpty())
        <p class="subtle" style="margin:0">{{ $flujoEntrega === \App\Enums\Capacidad::Cocina
            ? __('Venta entregada al confirmarse, antes de activar Pedidos en cocina.')
            : __('Venta entregada al confirmarse, antes de activar Despachos.') }}</p>
    @else
        <ol class="entrega-linea-tiempo" aria-label="{{ __('Historial de entrega') }}">
            @foreach ($venta->eventosEntrega as $evento)
                <li class="{{ $evento->estado_nuevo->insignia() }}" wire:key="evento-entrega-{{ $evento->id }}">
                    @if ($evento->es_correccion)
                        <b>{{ __('Corrección: :desde → :hasta', ['desde' => $evento->estado_anterior?->etiqueta() ?? '—', 'hasta' => $evento->estado_nuevo->etiqueta()]) }}</b><span class="badge danger">{{ __('Corrección') }}</span>
                    @elseif ($evento->estado_anterior === null)
                        <b>{{ $evento->estado_nuevo === EstadoEntrega::Entregado ? __('Entregado al vender') : __('Venta confirmada: :estado', ['estado' => $evento->estado_nuevo->etiqueta()]) }}</b>
                    @else
                        <b>{{ $evento->estado_nuevo->etiqueta() }}</b>
                    @endif
                    <small>{{ $evento->usuario->name ?? '' }} · {{ $evento->created_at?->copy()->setTimezone($zona)->format('d/m/Y H:i') }}</small>
                    @if ($evento->nota)<p class="nota" style="margin:3px 0 0">{{ $evento->nota }}</p>@endif
                </li>
            @endforeach
        </ol>
    @endif

    @include('livewire.despachos.dialogo')

    <x-aviso :mensaje="$aviso" :numero="$avisoNumero" :tipo="$avisoTipo" />
    <x-aviso-deshacer :deshacer="$deshacer" />
</section>
