@php
    $enRevision = \Illuminate\Support\Facades\DB::table('pagos_saas')->where('estado', 'pendiente')->count();
    $entradas = [
        ['Panel general', 'admin.panel', 'admin.panel', 'dashboard', 'Panel'],
        ['Empresas', 'admin.empresas', 'admin.empresas*', 'building', 'Empresas'],
        ['Suscripciones', 'admin.suscripciones', 'admin.suscripciones*', 'subscription', 'Planes'],
        ['Comprobantes', 'admin.comprobantes', 'admin.comprobantes*', 'receipt', 'Pagos'],
        ['Catálogos SIN', 'admin.catalogos', 'admin.catalogos*', 'history', 'Catálogos'],
        ['Agente Mozi', 'admin.agente-mozi', 'admin.agente-mozi*', 'sparkles', 'Mozi'],
        ['Sitio web', 'admin.sitio-web', 'admin.sitio-web*', 'globe', 'Sitio'],
        ['Ajustes globales', 'admin.ajustes', 'admin.ajustes*', 'settings', 'Ajustes'],
    ];
@endphp
<nav class="nav-scroll nav-groups" aria-label="{{ __('Navegación de superadministración') }}">
    <div class="nav-label">{{ __('MoziStudios') }}</div>
    @foreach ($entradas as [$etiqueta, $ruta, $patron, $simbolo, $corto])
        @php($activo = request()->routeIs($patron))
        <a class="nav-item {{ $activo ? 'active' : '' }}" href="{{ route($ruta) }}" title="{{ __($etiqueta) }}" @if ($activo) aria-current="page" @endif><span class="nav-symbol"><x-icon :name="$simbolo" /></span><span class="nav-text">{{ __($etiqueta) }}</span><span class="nav-short">{{ __($corto) }}</span>@if ($ruta === 'admin.comprobantes' && $enRevision > 0)<span class="badge warning" style="margin-left:auto" aria-label="{{ __(':cantidad en revisión', ['cantidad' => $enRevision]) }}">{{ $enRevision }}</span>@endif</a>
    @endforeach
    <div class="nav-label">{{ __('Cuenta') }}</div>
    <a class="nav-item {{ request()->routeIs('*.edit') ? 'active' : '' }}" href="{{ route('profile.edit') }}" title="{{ __('Mi cuenta') }}"><span class="nav-symbol"><x-icon name="settings" /></span><span class="nav-text">{{ __('Mi cuenta') }}</span><span class="nav-short">{{ __('Cuenta') }}</span></a>
</nav>
