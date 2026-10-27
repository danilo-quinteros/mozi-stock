@props(['name', 'label', 'type' => 'text', 'value' => null])
<div class="field">
    <label class="field-label" for="{{ $name }}">{{ __($label) }}</label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" @if($type !== 'password') value="{{ $value }}" @endif
        {{ $attributes->class(['input']) }}
        @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror>
    @error($name)<p class="auth-error" id="{{ $name }}-error" role="alert">{{ $message }}</p>@enderror
</div>
