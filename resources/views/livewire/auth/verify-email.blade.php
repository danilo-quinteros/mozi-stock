<x-layouts::auth title="{{ __('Confirma tu correo') }}">
    <p class="registro-paso-texto">{{ __('Último paso') }}</p>
    <h1>{{ __('Confirma tu correo') }}<span class="accent-text">.</span></h1>
    <p class="lead" style="margin-bottom:24px">
        {{ __('Te enviamos un enlace a :correo. Ábrelo para entrar a tu cuenta y empezar tu prueba gratis.', ['correo' => auth()->user()?->email]) }}
    </p>

    @if (session('status') === 'verification-link-sent')
        <div class="notice" role="status" style="margin-bottom:18px;text-align:left"><div><b>{{ __('Enlace reenviado') }}</b><p>{{ __('Revisa tu bandeja de entrada y la carpeta de spam.') }}</p></div></div>
    @endif

    <div class="stack">
        <form method="POST" action="{{ route('verification.send') }}" class="stack">
            @csrf
            <button type="submit" class="btn primary">{{ __('Reenviar el enlace') }} <x-icon name="send" size="sm" /></button>
        </form>
        <p class="registro-ayuda" style="text-align:center">{{ __('¿No llega? Revisa la carpeta de spam o promociones, o pide que te lo reenviemos.') }}</p>
        <form method="POST" action="{{ route('logout') }}" class="stack">
            @csrf
            <button type="submit" class="btn ghost" data-test="logout-button">{{ __('Cerrar sesión') }}</button>
        </form>
    </div>
</x-layouts::auth>
