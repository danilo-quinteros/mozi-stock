@use('App\Support\Decimal')
{{-- Insignia del resultado guardado al cerrar un turno. --}}
@switch($turno->resultado())
    @case('abierto')
        <span class="badge {{ $turno->esDelDiaAnterior() ? 'warning' : 'success' }}"><span class="caja-dot"></span>{{ $turno->esDelDiaAnterior() ? __('Desde un día anterior') : __('Abierta') }}</span>
        @break
    @case('cuadrada')
        <span class="badge success">{{ __('Cuadrada') }}</span>
        @break
    @case('sobrante')
        <span class="badge info">{{ __('Sobrante + :monto', ['monto' => Decimal::formatear((string) $turno->diferencia, 2, 2)]) }}</span>
        @break
    @case('faltante')
        <span class="badge danger">{{ __('Faltante − :monto', ['monto' => Decimal::formatear(ltrim((string) $turno->diferencia, '-'), 2, 2)]) }}</span>
        @break
    @default
        <span class="badge">{{ __('Sin conteo') }}</span>
@endswitch
