{{--
    Botón «Prueba gratis»: lleva al registro público por pasos. Con `plan` llega con ese plan ya
    elegido (lo usan las tarjetas de precios).
--}}
@props(['plan' => null])
<a {{ $attributes }} href="{{ route('registro', $plan !== null ? ['plan' => $plan] : []) }}">{{ $slot }}</a>
