{{-- Otras cajas que el administrador tiene abiertas (un turno por caja): se ven y se cierran en Caja y arqueo. --}}
@if ($otrosTurnos->isNotEmpty())
    <a href="{{ route('caja') }}" class="badge soft sm top-caja-mas" style="font-size:11px;padding:2px 7px;text-decoration:none"
        title="{{ __('También tienes abiertas: :cajas', ['cajas' => $listaTurnos($otrosTurnos)]) }}"
        aria-label="{{ trans_choice('Tienes 1 caja más abierta|Tienes :count cajas más abiertas', $otrosTurnos->count()) }}">+{{ $otrosTurnos->count() }}</a>
@endif
