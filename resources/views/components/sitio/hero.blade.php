{{--
    Encabezado de página interna: migas, etiqueta, H1, texto y botones. El slot «visual» (opcional)
    va a la derecha; sin él, el encabezado ocupa una sola columna.
--}}
@props(['pagina', 'etiqueta', 'titulo', 'lead', 'puntos' => [], 'mensaje' => null, 'ctas' => true])
@php($migas = \App\Domain\SitioWeb\CatalogoPaginas::migas($pagina))
<section @class(['page-hero', 'solo' => ! isset($visual)])>
    <div class="wrap hero-in">
        <div class="hero-copy">
            <nav class="migas" aria-label="Ruta de navegación">
                <ol>
                    @foreach ($migas as $miga)
                        @if ($loop->last)
                            <li><span aria-current="page">{{ $miga->nombre }}</span></li>
                        @else
                            <li><a href="{{ route($miga->nombreRuta()) }}">{{ $miga->nombre }}</a></li>
                        @endif
                    @endforeach
                </ol>
            </nav>
            <p class="pill"><span class="pulse" aria-hidden="true"></span>{{ $etiqueta }}</p>
            <h1>{!! $titulo !!}</h1>
            <p class="hero-lead">{{ $lead }}</p>
            @if ($ctas)
                <div class="hero-cta">
                    <x-sitio.prueba class="btn btn-primary btn-lg"><x-sitio.i n="gift" />Probar gratis {{ config('mozistock.prueba_gratis_dias') }} días</x-sitio.prueba>
                    <x-sitio.wa class="btn btn-ghost btn-lg" :mensaje="$mensaje ?? $pagina->mensajeWhatsapp"><x-sitio.i n="wa" />Pedir una demostración</x-sitio.wa>
                </div>
            @endif
            @if ($puntos !== [])
                <ul class="hero-points">
                    @foreach ($puntos as $punto)
                        <li><x-sitio.i n="check" />{{ $punto }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
        @isset($visual)
            <div class="hero-visual">{{ $visual }}</div>
        @endisset
    </div>
</section>
