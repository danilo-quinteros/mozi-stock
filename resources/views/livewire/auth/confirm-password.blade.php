<x-layouts::auth title="{{ __('Confirmar contraseña') }}">
    <h1>{{ __('Confirma tu acceso') }}<span class="accent-text">.</span></h1>
    <p class="lead" style="margin-bottom:27px">{{ __('Ingresa tu contraseña para continuar.') }}</p>
    <form method="POST" action="{{ route('password.confirm.store') }}" class="stack">
        @csrf
        <x-form-field name="password" label="{{ __('Contraseña') }}" type="password" autocomplete="current-password" autofocus required />
        <button class="btn primary" type="submit">{{ __('Confirmar') }}</button>
    </form>
</x-layouts::auth>
