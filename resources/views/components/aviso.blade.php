@props(['mensaje' => null, 'numero' => 0, 'tipo' => 'success'])
<div class="toast-host" role="status" aria-live="polite">
    @if ($mensaje)
        <div class="toast {{ $tipo }}" wire:key="aviso-{{ $numero }}" x-data="{ visible: true }" x-show="visible"
            x-init="setTimeout(() => visible = false, 3000)">
            @if ($tipo === 'success')
                <x-icon name="check" size="sm" />
            @endif
            <span>{{ $mensaje }}</span>
        </div>
    @endif
</div>
