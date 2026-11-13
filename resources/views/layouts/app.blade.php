@php
    $usuario = auth()->user();
    $panelGlobal = $usuario->esSuperadmin();
    $empresaActual = $usuario->perteneceAEmpresa() ? $usuario->empresa : null;
    // Sucursal de trabajo: el administrador la elige aquí y filtra todas las pantallas.
    $sucursalActiva = $empresaActual ? $usuario->sucursalActivaId() : null;
    $sucursalesMenu = $usuario->esAdministrador() ? \App\Models\Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']) : collect();
    $impersonador = session()->has(\App\Http\Controllers\ImpersonacionController::SESION);
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark" class="dark" @isset($sidebarAuto) data-sidebar-auto="{{ $sidebarAuto }}" @endisset>
<head>@include('partials.head')</head>
<body>
<a href="#contenido" class="btn primary skip-link">{{ __('Saltar al contenido') }}</a>
<div class="shell" x-data="{ menuOpen: false, mobile: window.matchMedia('(max-width: 760px)').matches }"
    @resize.window="mobile = window.matchMedia('(max-width: 760px)').matches"
    @keydown.escape.window="menuOpen = false">
    <div class="scrim" :class="{ open: menuOpen }" @click="menuOpen = false"></div>
    <aside class="sidebar" id="sidebar" :class="{ open: menuOpen }" :inert="mobile && !menuOpen">
        <div class="brand"><x-brand />
            <button type="button" class="sidebar-toggle" data-sidebar-toggle aria-controls="sidebar" aria-expanded="true"
                data-label-collapse="{{ __('Contraer menú') }}" data-label-expand="{{ __('Expandir menú') }}"
                aria-label="{{ __('Contraer menú') }}" title="{{ __('Contraer menú') }}"><x-icon name="sidebar" size="sm" /></button>
        </div>
        <div class="workspace">
            @if ($panelGlobal)
                <span class="workspace-avatar">MS</span>
                <span class="workspace-info"><b>{{ __('MoziStudios') }}</b><small>{{ __('Control central · SaaS') }}</small></span>
            @elseif ($empresaActual)
                <span class="workspace-avatar">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($empresaActual->nombre, 0, 2)) }}</span>
                <span class="workspace-info"><b>{{ $empresaActual->nombre }}</b>
                    @if ($usuario->esAdministrador())
                        <form method="POST" action="{{ route('sucursal-activa') }}">@csrf
                            <select name="sucursal_id" class="workspace-select" aria-label="{{ __('Sucursal de trabajo') }}" onchange="this.form.submit()">
                                <option value="">{{ __('Todas las sucursales') }}</option>
                                @foreach ($sucursalesMenu as $opcion)
                                    <option value="{{ $opcion->id }}" @selected($opcion->id === $sucursalActiva)>{{ $opcion->nombre }}</option>
                                @endforeach
                            </select>
                            <noscript><button class="btn ghost sm" type="submit">{{ __('Cambiar') }}</button></noscript>
                        </form>
                    @else
                        <small>{{ $usuario->sucursal->nombre ?? __('Sin sucursal') }}</small>
                    @endif
                </span>
                <x-icon name="store" size="sm" />
            @else
                <span class="workspace-avatar">{{ $usuario->initials() }}</span>
                <span class="workspace-info"><b>{{ $usuario->name }}</b><small>{{ __('Mi cuenta') }}</small></span>
            @endif
        </div>
        @if ($panelGlobal)
            <x-admin-navigation />
        @else
            <x-navigation />
        @endif
        <div class="sidebar-footer" x-data="{ profileOpen: false }">
            <div class="profile-menu-container" @click.outside="profileOpen = false" @keydown.escape.stop="profileOpen = false">
                <button type="button"
                        class="mini-profile"
                        @click="profileOpen = !profileOpen"
                        :aria-expanded="profileOpen"
                        aria-haspopup="true"
                        aria-label="{{ __('Menú de usuario') }}">
                    <span class="avatar">{{ $usuario->initials() }}</span>
                    <span class="mini-profile-info">
                        <b>{{ $usuario->name }}</b>
                        <small>{{ $usuario->rol?->etiqueta() ?? __('Sin rol asignado') }}</small>
                    </span>
                    <x-icon name="chevron-down" size="sm" class="profile-arrow" />
                </button>

                <div class="profile-dropdown" x-show="profileOpen" x-transition.origin.bottom.left x-cloak>
                    <a href="{{ route('profile.edit') }}" class="profile-dropdown-item">
                        <x-icon name="user" size="sm" />
                        <span>{{ __('Mi cuenta') }}</span>
                    </a>
                    <div class="profile-dropdown-divider"></div>
                    <form method="POST" action="{{ route('logout') }}" class="profile-dropdown-form">
                        @csrf
                        <button type="submit" class="profile-dropdown-item logout-item">
                            <x-icon name="logout" size="sm" />
                            <span>{{ __('Cerrar sesión') }}</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </aside>
    <div class="main">
        <header class="topbar">
            <div class="top-group">
                <button type="button" class="top-icon menu-trigger" aria-label="{{ __('Abrir menú') }}" aria-controls="sidebar" :aria-expanded="menuOpen" @click="menuOpen = !menuOpen"><x-icon name="menu" /></button>
                <div class="crumbs"><span class="crumb-pre">{{ $panelGlobal ? __('MoziStudios') : __('MoziStock') }}</span><span class="slash crumb-pre">/</span><b>{{ isset($title) ? __($title) : __('Inicio') }}</b></div>
            </div>
            @if (! $panelGlobal && $usuario->perteneceAEmpresa())
                <livewire:caja.control-caja-topbar />
            @endif
            <div class="top-group">
                <x-idioma-selector />
                <button type="button" class="top-icon theme-toggle" data-theme-toggle data-label-light="{{ __('Modo claro') }}" data-label-dark="{{ __('Modo oscuro') }}" aria-label="{{ __('Cambiar modo claro u oscuro') }}"><x-icon name="sun" /><span>{{ __('Cambiar tema') }}</span></button>
                <span class="user-avatar" aria-label="{{ $usuario->name }}">{{ $usuario->initials() }}</span>
            </div>
        </header>
        @if ($impersonador)
            <div class="impersonation-bar" role="status">
                <x-icon name="eye" />
                <span>{!! __('Estás usando la cuenta de :nombre (:rol). Todo lo que hagas queda registrado a su nombre.', ['nombre' => '<b>'.e($usuario->name).'</b>', 'rol' => e($usuario->rol?->etiqueta())]) !!}</span>
                <form method="POST" action="{{ route('impersonar.salir') }}">@csrf
                    <button class="btn primary sm" type="submit"><x-icon name="logout" size="sm" /> {{ __('Volver a mi cuenta') }}</button>
                </form>
            </div>
        @endif
        <main class="content" id="contenido" tabindex="-1">{{ $slot }}</main>
    </div>
</div>
@if ($empresaActual && app(\App\Domain\Tenancy\CapacidadesEmpresa::class)->tiene($empresaActual->id, \App\Enums\Capacidad::Cocina))
    {{-- Pedidos en cocina (ESPECIFICACION_COCINA.md § 4.2): quién ve el botón lo deciden los componentes. --}}
    <livewire:cocina.boton-flotante />
    <livewire:cocina.panel />
@endif
<flux:toast />
@fluxScripts
</body>
</html>
