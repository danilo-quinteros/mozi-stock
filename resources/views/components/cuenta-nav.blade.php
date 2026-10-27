@php
    // «Mi cuenta» agrupa los datos personales y preferencias de acceso del usuario.
    $usuario = auth()->user();
    $impersonando = session()->has(\App\Http\Controllers\ImpersonacionController::SESION);
    $pestanas = array_filter([
        ['Perfil', 'profile.edit', 'profile.edit', 'user', ! $impersonando],
        ['Seguridad', 'security.edit', 'security.edit', 'lock', ! $impersonando],
        ['Apariencia', 'appearance.edit', 'appearance.edit', 'sun', true],
    ], fn ($p) => $p[4]);
@endphp
<nav class="tabs account-tabs" aria-label="{{ __('Mi cuenta') }}">
    @foreach ($pestanas as [$etiqueta, $ruta, $patron, $icono])
        @php($activa = request()->routeIs(...explode('|', $patron)))
        <a class="tab {{ $activa ? 'active' : '' }}" href="{{ route($ruta) }}" @if ($activa) aria-current="page" @endif><x-icon :name="$icono" size="sm" /> {{ __($etiqueta) }}</a>
    @endforeach
</nav>
