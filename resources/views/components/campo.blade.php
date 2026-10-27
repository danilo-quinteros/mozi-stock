@props(['etiqueta', 'campo'])
<div {{ $attributes->class('field') }}>
    <label class="field-label" for="{{ $campo }}">{{ __($etiqueta) }}</label>
    {{ $slot }}
    @error($campo)<p class="auth-error" id="{{ $campo }}-error" role="alert">{{ $message }}</p>@enderror
</div>
