@props(['icono', 'titulo', 'descripcion', 'beneficios' => []])
{{-- Aviso de una función que el plan de la empresa no incluye (Agente Mozi, Facturación SIAT). Solo informa y lleva
     a Mi suscripción con el selector de planes abierto; el acceso real lo deciden los Gates del servidor. --}}
<section class="plan-requerido" data-test="plan-requerido">
    <span class="plan-requerido-icono" aria-hidden="true"><x-icon :name="$icono" /></span>
    <div class="plan-requerido-cuerpo">
        <span class="plan-requerido-tag"><x-icon name="lock" size="sm" /> {{ __('No incluido en tu plan') }}</span>
        <h2>{{ $titulo }}</h2>
        <p>{{ $descripcion }}</p>
        @if ($beneficios !== [])
            <ul class="plan-requerido-lista">
                @foreach ($beneficios as $beneficio)
                    <li><x-icon name="check" size="sm" /> {{ $beneficio }}</li>
                @endforeach
            </ul>
        @endif
        <div class="plan-requerido-acciones">
            <a class="btn primary" href="{{ route('suscripcion', ['planes' => 1]) }}" data-test="plan-requerido-cambiar"><x-icon name="subscription" /> {{ __('Cambiar de plan') }}</a>
            <a class="btn ghost" href="{{ route('dashboard') }}">{{ __('Volver al inicio') }}</a>
        </div>
    </div>
</section>
