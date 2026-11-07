{{-- Caja del efectivo (EligeCaja). Un pago solo electrónico no mueve efectivo y no la pide. --}}
@if ($metodo !== 'electronico')
    <x-campo etiqueta="Caja del efectivo" campo="caja">
        @if ($this->opcionesCaja === [])
            <input id="caja" class="input" type="text" readonly value="{{ __('Sin turno abierto: abre tu caja en la barra superior') }}">
        @else
            <select id="caja" class="select" wire:model="caja">
                @foreach ($this->opcionesCaja as $valor => $texto)
                    <option value="{{ $valor }}">{{ $texto }}</option>
                @endforeach
            </select>
            @if ($caja === 'externo')<small class="subtle">{{ __('No entra ni sale del arqueo de ninguna caja.') }}</small>@endif
        @endif
    </x-campo>
    @error('externo')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
@endif
