@props(['deshacer' => null])
{{--
    Cocina § 4.3: «Mesa 4 entregado · Deshacer» después de «Entregar». Se oculta cuando vence el plazo que
    calculó el servidor; si alguien pulsa tarde, el servidor lo rechaza igual (DeshacerEntrega).
--}}
<div class="toast-host" role="status" aria-live="polite">
    @if ($deshacer)
        <div class="toast success toast-deshacer" wire:key="deshacer-{{ $deshacer['venta_id'] }}" x-data="{ visible: true }" x-show="visible"
            x-init="setTimeout(() => visible = false, {{ $deshacer['segundos'] * 1000 }})" data-aviso-deshacer>
            <x-icon name="check" size="sm" />
            <span>{{ $deshacer['texto'] }}</span>
            <button type="button" class="toast-accion" wire:click="deshacerEntregaPedido({{ $deshacer['venta_id'] }})"
                wire:loading.attr="disabled" wire:target="deshacerEntregaPedido" data-deshacer-entrega>{{ __('Deshacer') }}</button>
        </div>
    @endif
</div>
