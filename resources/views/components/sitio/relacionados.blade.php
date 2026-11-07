{{-- Enlaces internos a otros módulos o rubros (ayudan a Google a recorrer el sitio). --}}
@props(['actual', 'claves' => null, 'titulo' => 'Funciona junto con', 'grupo' => 'modulos'])
@php
    $fuente = $grupo === 'rubros' ? \App\Domain\SitioWeb\MenuSitio::rubros() : \App\Domain\SitioWeb\MenuSitio::modulos();
    $claves ??= array_slice(array_values(array_diff(array_keys($fuente), [$actual])), 0, 6);
@endphp
<section class="section section-alt" aria-labelledby="relacionados-{{ $actual }}">
    <div class="wrap">
        <header class="section-head">
            <p class="eyebrow">Todo conectado</p>
            <h2 id="relacionados-{{ $actual }}">{{ $titulo }}</h2>
        </header>
        <div class="link-cards">
            @foreach ($claves as $clave)
                @php($item = $fuente[$clave] ?? \App\Domain\SitioWeb\MenuSitio::modulos()[$clave] ?? \App\Domain\SitioWeb\MenuSitio::rubros()[$clave])
                <a class="link-card reveal" href="{{ route('sitio.'.$clave) }}">
                    <span class="dd-icon"><x-sitio.i :n="$item['icono']" /></span>
                    <span><b>{{ \App\Domain\SitioWeb\CatalogoPaginas::buscar($clave)->nombre }}</b><small>{{ $item['resumen'] }}</small></span>
                    <x-sitio.i n="arrow" />
                </a>
            @endforeach
        </div>
    </div>
</section>
