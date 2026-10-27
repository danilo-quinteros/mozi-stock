<x-layouts::auth title="{{ __('Restablecer contraseña') }}">
    <h1>{{ __('Nueva contraseña') }}<span class="accent-text">.</span></h1>
    <p class="lead" style="margin-bottom:27px">{{ __('Elige una contraseña segura para tu cuenta.') }}</p>
    <form method="POST" action="{{ route('password.update') }}" class="stack">
        @csrf
        <input type="hidden" name="token" value="{{ request()->route('token') }}">
        <x-form-field name="email" label="{{ __('Correo electrónico') }}" type="email" :value="old('email', request('email'))" autocomplete="email" required />
        <x-form-field name="password" label="{{ __('Nueva contraseña') }}" type="password" autocomplete="new-password" required />
        <x-form-field name="password_confirmation" label="{{ __('Confirmar contraseña') }}" type="password" autocomplete="new-password" required />
        <button class="btn primary" type="submit">{{ __('Restablecer contraseña') }}</button>
    </form>
</x-layouts::auth>
