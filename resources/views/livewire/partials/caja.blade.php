{{-- Caja del efectivo (EligeCaja). Un pago solo electrónico no mueve efectivo y no la pide.
     Con salidas de efectivo, el componente pasa $avisoCaja (EligeCaja::avisoCajaNegativa). --}}
@if ($metodo !== 'electronico')
    <x-campo etiqueta="Caja del efectivo" campo="caja">
        @if ($this->opcionesCaja === [])
            <input id="caja" class="input" type="text" readonly value="{{ __('Sin turno abierto: abre tu caja en la barra superior') }}">
        @else
            <select id="caja" class="select" wire:model.live="caja">
                @foreach ($this->opcionesCaja as $valor => $texto)
                    <option value="{{ $valor }}">{{ $texto }}</option>
                @endforeach
            </select>
            @if ($caja === 'externo')<small class="subtle">{{ __('No entra ni sale del arqueo de ninguna caja.') }}</small>@endif
        @endif
    </x-campo>
    @error('externo')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
    @if (! empty($avisoCaja))
        @include('livewire.partials.caja-negativa')
    @endif
@endif
