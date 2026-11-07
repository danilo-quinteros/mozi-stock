{{-- Enlace de WhatsApp con mensaje propio; sin número configurado lleva a la página de contacto. --}}
@props(['mensaje'])
@php($enlace = \App\Domain\SitioWeb\Whatsapp::enlace($sitioAjustes->sitio_whatsapp, $mensaje))
<a {{ $attributes }} href="{{ $enlace ?? route('sitio.contacto') }}" @if ($enlace) target="_blank" rel="noopener" @endif data-wa>{{ $slot }}</a>
