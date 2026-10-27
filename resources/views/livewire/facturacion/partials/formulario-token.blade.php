{{-- Token delegado (al guardarlo se sincronizan los catálogos): lo usan Facturación → Datos fiscales y el popup de la guía. --}}
@php($zona = config('mozistock.business_timezone'))
@if ($configuracion?->tieneToken())
    <div class="list-line">
        <span class="subtle">{{ __('Token cargado') }}</span>
        <b>…{{ $configuracion->token_sufijo }}</b>
    </div>
    <div class="list-line">
        <span class="subtle">{{ __('Vence') }}</span>
        <span>
            <b>{{ $configuracion->token_vence_en?->timezone($zona)->format('d/m/Y') ?? __('Sin fecha') }}</b>
            @if ($configuracion->tokenVencido())
                <span class="badge danger">{{ __('Vencido') }}</span>
            @elseif ($configuracion->tokenPorVencer())
                <span class="badge warning">{{ __('Por vencer') }}</span>
            @endif
        </span>
    </div>
    <div class="list-line">
        <span class="subtle">{{ __('Actualizado') }}</span>
        <span>{{ $configuracion->token_actualizado_en?->timezone($zona)->format('d/m/Y H:i') }}@if ($actualizadoPor) · {{ $actualizadoPor }}@endif</span>
    </div>
@endif
@if ($puedeConfigurar)
    <form wire:submit="guardarToken" style="margin-top:14px">
        <x-campo :etiqueta="$configuracion?->tieneToken() ? 'Reemplazar token' : 'Token delegado'" campo="token">
            <input id="token" class="input" type="password" wire:model="token" autocomplete="off" spellcheck="false" placeholder="{{ __('Pega aquí el token del portal SIAT') }}">
        </x-campo>
        <p class="subtle" style="margin:12px 0 0">{{ __('Genéralo en el portal SIAT para el sistema :sistema. Se guarda cifrado y nunca se vuelve a mostrar; solo verás sus últimos 4 caracteres.', ['sistema' => $sistema?->codigoSistema ?? '—']) }}</p>
        <div class="row" style="margin-top:18px;justify-content:flex-end">
            <button class="btn primary" type="submit" wire:loading.attr="disabled" wire:target="guardarToken"><x-icon name="lock" /> {{ __('Guardar token') }}</button>
        </div>
    </form>
@elseif (! $configuracion?->tieneToken())
    <div class="empty">{{ __('Aún no hay un token cargado.') }}</div>
@endif
