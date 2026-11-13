@props(['pagina', 'seo', 'esquema'])
@php
    $ogEsHttps = str_starts_with($seo->ogImagen, 'https://');
    $robots = $seo->indexar ? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1' : 'noindex, follow';
@endphp
<!doctype html>
<html lang="es-BO" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>{{ $seo->titulo }}</title>
<meta name="description" content="{{ $seo->descripcion }}">
<meta name="robots" content="{{ $robots }}">
<link rel="canonical" href="{{ $seo->canonical }}">
@if (filled($sitioAjustes->sitio_verificacion_google))
<meta name="google-site-verification" content="{{ $sitioAjustes->sitio_verificacion_google }}">
@endif
<meta name="theme-color" content="#df8341">
<meta name="msapplication-TileColor" content="#df8341">
<meta name="author" content="MoziStudios">
<meta name="geo.region" content="BO">
<meta name="geo.placename" content="Bolivia">

<meta property="og:type" content="website">
<meta property="og:site_name" content="MoziStock">
<meta property="og:locale" content="es_BO">
<meta property="og:url" content="{{ $seo->canonical }}">
<meta property="og:title" content="{{ $seo->ogTitulo }}">
<meta property="og:description" content="{{ $seo->ogDescripcion }}">
<meta property="og:image" content="{{ $seo->ogImagen }}">
@if ($ogEsHttps)
<meta property="og:image:secure_url" content="{{ $seo->ogImagen }}">
@endif
@if ($seo->ogImagenPorDefecto)
<meta property="og:image:type" content="image/png">
<meta property="og:image:width" content="{{ \App\Domain\SitioWeb\Seo::ANCHO_IMAGEN_POR_DEFECTO }}">
<meta property="og:image:height" content="{{ \App\Domain\SitioWeb\Seo::ALTO_IMAGEN_POR_DEFECTO }}">
@endif
<meta property="og:image:alt" content="{{ $seo->ogTitulo }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seo->ogTitulo }}">
<meta name="twitter:description" content="{{ $seo->ogDescripcion }}">
<meta name="twitter:image" content="{{ $seo->ogImagen }}">
<meta name="twitter:image:alt" content="{{ $seo->ogTitulo }}">

<link rel="icon" href="{{ asset('img/sitio/logo-mozistock.png') }}" type="image/png">
<link rel="apple-touch-icon" href="{{ asset('img/sitio/logo-mozistock.png') }}">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400..800&amp;display=swap">
<script>
    // Tema antes de pintar: preferencia guardada (misma clave que la app) o la del sistema.
    (function () {
        var d = document.documentElement, t = null;
        try { t = localStorage.getItem('mozistock-theme'); } catch (e) {}
        if (t !== 'light' && t !== 'dark') {
            t = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
        }
        d.setAttribute('data-theme', t);
    })();
</script>
@vite(['resources/css/sitio.css', 'resources/js/sitio.js'])
<script type="application/ld+json">{!! \App\Domain\SitioWeb\EsquemaJsonLd::json($esquema) !!}</script>
@stack('esquemas')
</head>
<body>
<a class="skip" href="#contenido">Saltar al contenido</a>
<x-sitio.iconos />
<x-sitio.encabezado :pagina="$pagina" />

<main id="contenido">
    {{ $slot }}
</main>

<x-sitio.pie />

<a class="wa-fab" href="{{ \App\Domain\SitioWeb\Whatsapp::enlace($sitioAjustes->sitio_whatsapp, $seo->mensajeWhatsapp) ?? route('sitio.contacto') }}" @if (filled($sitioAjustes->sitio_whatsapp)) target="_blank" rel="noopener" @endif data-wa aria-label="Escríbenos por WhatsApp">
    <x-sitio.i n="wa" /><span>WhatsApp</span>
</a>
</body>
</html>
