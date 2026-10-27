<x-layouts::auth title="{{ __('Recuperar acceso') }}">
    <h1>{{ __('Recuperar acceso') }}<span class="accent-text">.</span></h1>
    <p class="lead" style="margin-bottom:27px">{{ __('Te enviaremos un enlace para restablecer tu contraseña.') }}</p>
    <x-auth-session-status :status="session('status')" />
    <form method="POST" action="{{ route('password.email') }}" class="stack">
        @csrf
        <x-form-field name="email" label="{{ __('Correo electrónico') }}" type="email" :value="old('email')" autocomplete="email" autofocus required />
        <button class="btn primary" type="submit">{{ __('Enviar enlace') }}</button>
    </form>
    <div class="login-links"><a class="link" href="{{ route('login') }}">{{ __('Volver al acceso') }}</a></div>
</x-layouts::auth>
