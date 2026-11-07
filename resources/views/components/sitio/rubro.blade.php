{{--
    Plantilla de página por rubro: encabezado, problemas que resuelve, funciones clave, módulos
    recomendados, preguntas y cierre. Cada página de rubro solo aporta su contenido.
--}}
@props(['pagina', 'seo', 'esquema', 'etiqueta', 'titulo', 'lead', 'puntos', 'dolores', 'funciones', 'modulos', 'preguntas', 'cierre'])
<x-sitio.layout :pagina="$pagina" :seo="$seo" :esquema="$esquema">
    <x-sitio.hero :pagina="$pagina" :etiqueta="$etiqueta" :titulo="$titulo" :lead="$lead" :puntos="$puntos">
        @isset($visual)
            <x-slot:visual>{{ $visual }}</x-slot:visual>
        @endisset
    </x-sitio.hero>

    <section class="section section-alt" aria-labelledby="ru-dolores">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Lo que resolvemos</p>
                <h2 id="ru-dolores">¿Te pasa esto en tu negocio?</h2>
            </header>
            <div class="features features-2">
                @foreach ($dolores as $i => [$problema, $solucion])
                    <article class="feature reveal" @if ($i % 2) style="--d:80ms" @endif>
                        <h3>{{ $problema }}</h3>
                        <p>{{ $solucion }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="ru-funciones">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Cómo te ayuda</p>
                <h2 id="ru-funciones">Lo que más vas a usar</h2>
            </header>
            <x-sitio.funciones :items="$funciones" />
        </div>
    </section>

    <section class="section section-alt" aria-labelledby="ru-faq">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Preguntas</p>
                <h2 id="ru-faq">Preguntas frecuentes</h2>
            </header>
            <x-sitio.faq :preguntas="$preguntas" />
        </div>
    </section>

    <x-sitio.relacionados :actual="$pagina->clave" :claves="$modulos" titulo="Módulos recomendados para tu rubro" />
    <x-sitio.cta :titulo="$cierre" :mensaje="$seo->mensajeWhatsapp" />
</x-sitio.layout>
