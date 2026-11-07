{{--
    Preguntas frecuentes con <details> nativo. Con esquema=true agrega el JSON-LD FAQPage al <head>
    (una sola vez por página: en Preguntas frecuentes lo emite la página con todas juntas).
--}}
@props(['preguntas', 'esquema' => true])
@if ($esquema)
    @push('esquemas')
        <script type="application/ld+json">{!! \App\Domain\SitioWeb\EsquemaJsonLd::json(\App\Domain\SitioWeb\EsquemaJsonLd::preguntas($preguntas)) !!}</script>
    @endpush
@endif
<div class="faq">
    @foreach ($preguntas as [$pregunta, $respuesta])
        <details class="reveal">
            <summary>{{ $pregunta }}<x-sitio.i n="chevron" class="chev" /></summary>
            <p>{{ $respuesta }}</p>
        </details>
    @endforeach
</div>
