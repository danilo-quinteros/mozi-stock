<x-layouts::auth title="{{ __('Acceso') }}">
    <h1>{{ __('Bienvenido de nuevo') }}<span class="accent-text">.</span></h1>
    <p class="lead" style="margin-bottom:27px">{{ __('Accede a tu espacio de trabajo.') }}</p>
    <x-auth-session-status :status="session('status')" />
    @if (session('bloqueo'))
        <div class="notice" role="alert" style="margin-bottom:18px;text-align:left"><div><b>{{ __('Acceso desactivado') }}</b><p>{{ session('bloqueo') }}</p></div></div>
    @endif
    <form method="POST" action="{{ route('login.store') }}" class="stack">
        @csrf
        <x-form-field name="email" label="{{ __('Correo electrónico') }}" type="email" :value="old('email')" autocomplete="username" autofocus required />
        <x-form-field name="password" label="{{ __('Contraseña') }}" type="password" autocomplete="current-password" required />
        <label class="row"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> {{ __('Recordarme') }}</label>
        <button class="btn primary" type="submit" data-test="login-button">{{ __('Iniciar sesión') }} <x-icon name="arrow" size="sm" /></button>
    </form>
    <div class="login-links"><a class="link" href="{{ route('password.request') }}">{{ __('¿Olvidaste tu contraseña?') }}</a></div>
    <div class="login-links">
        <x-passkey-verify label="{{ __('Acceder con una clave de acceso') }}" loading-label="{{ __('Comprobando…') }}" separator="{{ __('O usa tu correo y contraseña') }}" />
    </div>
</x-layouts::auth>
