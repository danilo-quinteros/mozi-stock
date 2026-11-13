{{-- Estado con el que quedará la venta al confirmar; lo decide el servidor con la misma casilla. --}}
@php($resultado = $entregado ? \App\Enums\EstadoEntrega::Entregado : \App\Enums\EstadoEntrega::inicial($flujoEntrega))
<span class="entrega-resultado" aria-live="polite">{{ __('Quedará') }} <span class="badge {{ $resultado->insignia() }}" data-estado-resultante>{{ $resultado->etiqueta() }}</span></span>
