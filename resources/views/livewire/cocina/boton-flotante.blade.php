<div>
    {{-- Cocina § 4.2: siempre visible abajo a la derecha; gris con 0, color de alerta si hay demorados. --}}
    @if ($visible)
        <div class="cocina-fab-host" wire:poll.visible.10s data-cocina-boton>
            <button type="button" wire:click="$dispatch('abrir-panel-cocina')"
                @class(['cocina-fab', 'is-vacio' => $total === 0, 'is-demora' => $demorados > 0])
                aria-haspopup="dialog" aria-controls="cocina-panel"
                aria-label="{{ trans_choice('{0} En cocina: sin pedidos|{1} En cocina: 1 pedido|[2,*] En cocina: :n pedidos', $total, ['n' => $total]) }}{{ $demorados > 0 ? ' · '.trans_choice('{1} 1 con demora|[2,*] :n con demora', $demorados, ['n' => $demorados]) : '' }}">
                <x-icon name="cocina" />
                <span class="cocina-fab-texto">{{ __('En cocina') }}</span>
                <span class="cocina-fab-contador" data-cocina-contador>{{ $total }}</span>
            </button>
        </div>
    @endif
</div>
