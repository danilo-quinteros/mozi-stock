@php
    // «Mi empresa» agrupa la configuración general, plan de suscripción y facturación electrónica SIAT.
    $usuario = auth()->user();
    $pestanas = array_filter([
        ['Empresa', 'configuracion', 'configuracion', 'store', $usuario->esAdministrador()],
        ['Mi suscripción', 'suscripcion', 'suscripcion|suscripcion.*', 'subscription', $usuario->esAdministrador() && config('mozistock.deployment_mode') === 'saas'],
        ['Facturación', 'facturacion', 'facturacion', 'receipt', $usuario->esAdministrador() && $usuario->empresa?->siat_habilitado],
    ], fn ($p) => $p[4]);
@endphp
<nav class="tabs account-tabs" aria-label="{{ __('Mi empresa') }}">
    @foreach ($pestanas as [$etiqueta, $ruta, $patron, $icono])
        @php($activa = request()->routeIs(...explode('|', $patron)))
        <a class="tab {{ $activa ? 'active' : '' }}" href="{{ route($ruta) }}" @if ($activa) aria-current="page" @endif><x-icon :name="$icono" size="sm" /> {{ __($etiqueta) }}</a>
    @endforeach
</nav>
