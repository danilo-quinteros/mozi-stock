@props(['titulo', 'descripcion' => null, 'cerrar'])
{{-- Modal pequeño y contextual. La acción de cierre es un método Livewire del componente. --}}
<div class="modal-backdrop open" wire:keydown.escape.window="{{ $cerrar }}">
    <div {{ $attributes->class('modal') }} role="dialog" aria-modal="true" aria-labelledby="modal-titulo">
        <div class="modal-head">
            <div>
                <h2 id="modal-titulo">{{ __($titulo) }}</h2>
                @if ($descripcion)<p>{{ __($descripcion) }}</p>@endif
            </div>
            <button type="button" class="icon-action" wire:click="{{ $cerrar }}" aria-label="{{ __('Cerrar') }}"><x-icon name="x" size="sm" /></button>
        </div>
        {{ $slot }}
    </div>
</div>
