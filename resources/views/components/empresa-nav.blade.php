@php
    // «Mi empresa» agrupa la configuración general, plan de suscripción, facturación electrónica SIAT y, con la
    // capacidad «variantes», las listas de tallas y colores.
    $usuario = auth()->user();
    $pestanas = array_filter([
        ['Empresa', 'configuracion', 'configuracion', 'store', $usuario->esAdministrador()],
        ['Mi suscripción', 'suscripcion', 'suscripcion|suscripcion.*', 'subscription', $usuario->esAdministrador() && config('mozistock.deployment_mode') === 'saas'],
        ['Facturación', 'facturacion', 'facturacion', 'receipt', $usuario->esAdministrador() && $usuario->empresa?->siat_habilitado],
        // Tallas y colores (V1): solo con la capacidad «variantes».
        ['Tallas y colores', 'configuracion.tallas-colores', 'configuracion.tallas-colores', 'tag', $usuario->esAdministrador() && \Illuminate\Support\Facades\Gate::allows('capacidad', \App\Enums\Capacidad::Variantes)],
    ], fn ($p) => $p[4]);
@endphp
<nav class="tabs account-tabs" aria-label="{{ __('Mi empresa') }}">
    @foreach ($pestanas as [$etiqueta, $ruta, $patron, $icono])
        @php($activa = request()->routeIs(...explode('|', $patron)))
        <a class="tab {{ $activa ? 'active' : '' }}" href="{{ route($ruta) }}" @if ($activa) aria-current="page" @endif><x-icon :name="$icono" size="sm" /> {{ __($etiqueta) }}</a>
    @endforeach
</nav>
