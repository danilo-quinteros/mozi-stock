@props(['name', 'size' => null])
{{-- Los trazos viven en App\Support\Iconos: un solo catálogo para todo el sistema. --}}
<svg viewBox="0 0 24 24" aria-hidden="true" {{ $attributes->class(['ico', $size]) }}>{!! \App\Support\Iconos::TRAZOS[$name] ?? \App\Support\Iconos::TRAZOS['info'] !!}</svg>
