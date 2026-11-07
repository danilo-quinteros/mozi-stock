{{-- Campos de FormularioPago. Recibe $administra, $sucursales, $sumaMixta y $etiquetaMonto. --}}
@use('App\Support\Decimal')
<div class="field">
    <span class="field-label">{{ __('Fecha') }}</span>
    <input class="input" type="text" readonly value="{{ __('Hoy, :fecha (al registrar)', ['fecha' => now()->timezone(config('mozistock.business_timezone'))->format('d/m/Y')]) }}">
</div>
<x-campo etiqueta="Sucursal" campo="sucursal_id">
    <select id="sucursal_id" class="select" wire:model.live="sucursal_id" @disabled(! $administra)>
        @foreach ($sucursales as $opcion)
            <option value="{{ $opcion->id }}">{{ $opcion->nombre }}</option>
        @endforeach
    </select>
</x-campo>
<x-campo etiqueta="Método de pago" campo="metodo">
    <select id="metodo" class="select" wire:model.live="metodo">
        <option value="efectivo">{{ __('Efectivo') }}</option>
        <option value="electronico">{{ __('Electrónico') }}</option>
        <option value="mixto">{{ __('Mixto · efectivo + electrónico') }}</option>
    </select>
</x-campo>
@if ($metodo === 'mixto')
    <div>
        <div class="credit-split">
            <x-campo etiqueta="Efectivo (Bs)" campo="efectivo">
                <input id="efectivo" class="input" type="text" inputmode="decimal" wire:model.live.debounce.300ms="efectivo" placeholder="0,00">
            </x-campo>
            <x-campo etiqueta="Electrónico (Bs)" campo="electronico">
                <input id="electronico" class="input" type="text" inputmode="decimal" wire:model.live.debounce.300ms="electronico" placeholder="0,00">
            </x-campo>
        </div>
        <div class="summary-count"><span>{{ __('Total mixto') }}</span><b>{{ Decimal::bs($sumaMixta) }}</b></div>
    </div>
@else
    <x-campo :etiqueta="$etiquetaMonto" campo="monto">
        <input id="monto" class="input" type="text" inputmode="decimal" wire:model.live.debounce.300ms="monto" placeholder="0,00" required>
    </x-campo>
@endif
@include('livewire.partials.caja')
<x-campo etiqueta="Referencia (opcional)" campo="referencia">
    <input id="referencia" class="input" type="text" maxlength="100" wire:model="referencia" placeholder="{{ __('N.º de transferencia, recibo...') }}">
</x-campo>
<x-campo etiqueta="Nota (opcional)" campo="nota">
    <input id="nota" class="input" type="text" maxlength="150" wire:model="nota">
</x-campo>
<x-campo etiqueta="Comprobante (opcional)" campo="comprobante">
    <input id="comprobante" class="input" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" wire:model="comprobante">
    <small class="subtle" wire:loading wire:target="comprobante">{{ __('Subiendo…') }}</small>
</x-campo>
