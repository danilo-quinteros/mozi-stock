@props(['pagina'])
@php
    $modulos = \App\Domain\SitioWeb\MenuSitio::modulos();
    $rubros = \App\Domain\SitioWeb\MenuSitio::rubros();
    $actual = $pagina->clave;
    $enModulos = $pagina->grupo === 'modulo' || $actual === 'funcionalidades';
    $enRubros = $pagina->grupo === 'rubro';
    $conSesion = auth()->check();
@endphp
<header class="site-header" data-header>
    <div class="wrap header-in">
        <a class="brand" href="{{ route('home') }}" aria-label="MoziStock, ir al inicio">
            <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
            <span class="brand-text"><b>MoziStock</b><small>by MoziStudios</small></span>
        </a>

        <nav class="nav" id="menu-principal" aria-label="Principal">
            <div class="nav-group" data-dropdown>
                <button class="nav-link" type="button" aria-expanded="false" aria-controls="dd-funcionalidades" @if ($enModulos) aria-current="page" @endif>Funcionalidades<x-sitio.i n="chevron" class="chev" /></button>
                <div class="dropdown" id="dd-funcionalidades">
                    <div class="dropdown-grid">
                        @foreach ($modulos as $clave => $modulo)
                            <a class="dd-item" href="{{ route('sitio.'.$clave) }}" @if ($actual === $clave) aria-current="page" @endif>
                                <span class="dd-icon"><x-sitio.i :n="$modulo['icono']" /></span>
                                <span><b>{{ \App\Domain\SitioWeb\CatalogoPaginas::buscar($clave)->nombre }}</b><small>{{ $modulo['resumen'] }}</small></span>
                            </a>
                        @endforeach
                    </div>
                    <a class="dd-foot" href="{{ route('sitio.funcionalidades') }}">Ver todas las funcionalidades<x-sitio.i n="arrow" /></a>
                </div>
            </div>
            <div class="nav-group" data-dropdown>
                <button class="nav-link" type="button" aria-expanded="false" aria-controls="dd-rubros" @if ($enRubros) aria-current="page" @endif>Rubros<x-sitio.i n="chevron" class="chev" /></button>
                <div class="dropdown dropdown-sm" id="dd-rubros">
                    <div class="dropdown-grid">
                        @foreach ($rubros as $clave => $rubro)
                            <a class="dd-item" href="{{ route('sitio.'.$clave) }}" @if ($actual === $clave) aria-current="page" @endif>
                                <span class="dd-icon"><x-sitio.i :n="$rubro['icono']" /></span>
                                <span><b>{{ \App\Domain\SitioWeb\CatalogoPaginas::buscar($clave)->nombre }}</b><small>{{ $rubro['resumen'] }}</small></span>
                            </a>
                        @endforeach
                    </div>
                    <a class="dd-foot" href="{{ route('sitio.rubros') }}">Ver todos los rubros<x-sitio.i n="arrow" /></a>
                </div>
            </div>
            <a class="nav-link" href="{{ route('sitio.precios') }}" @if ($actual === 'precios') aria-current="page" @endif>Precios</a>
            <a class="nav-link" href="{{ route('sitio.preguntas-frecuentes') }}" @if ($actual === 'preguntas-frecuentes') aria-current="page" @endif>Preguntas</a>
            <a class="nav-link" href="{{ route('sitio.contacto') }}" @if ($actual === 'contacto') aria-current="page" @endif>Contacto</a>

            <div class="nav-mobile-actions">
                <x-sitio.prueba class="btn btn-primary btn-lg btn-block">Probar gratis {{ config('mozistock.prueba_gratis_dias') }} días</x-sitio.prueba>
                @if ($conSesion)
                    <a class="btn btn-ghost btn-lg btn-block" href="{{ route('dashboard') }}"><x-sitio.i n="dashboard" />Ir a mi panel</a>
                @else
                    <a class="btn btn-ghost btn-lg btn-block" href="{{ route('login') }}" rel="nofollow"><x-sitio.i n="login" />Iniciar sesión</a>
                @endif
            </div>
        </nav>

        <div class="header-actions">
            <button class="icon-btn" type="button" data-theme-toggle aria-label="Cambiar a modo claro">
                <x-sitio.i n="sun" class="ico-sun" />
                <x-sitio.i n="moon" class="ico-moon" />
            </button>
            @if ($conSesion)
                <a class="btn btn-ghost btn-sm header-login" href="{{ route('dashboard') }}">Ir a mi panel</a>
            @else
                <a class="btn btn-ghost btn-sm header-login" href="{{ route('login') }}" rel="nofollow">Iniciar sesión</a>
            @endif
            <x-sitio.prueba class="btn btn-primary btn-sm header-trial" aria-label="Prueba gratis"><x-sitio.i n="gift" /><span>Prueba gratis</span></x-sitio.prueba>
            <button class="icon-btn menu-btn" type="button" data-menu-toggle aria-expanded="false" aria-controls="menu-principal" aria-label="Abrir menú">
                <x-sitio.i n="menu" class="ico-menu" />
                <x-sitio.i n="close" class="ico-close" />
            </button>
        </div>
    </div>
</header>
