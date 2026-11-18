{{-- Aviso de EligeCaja::avisoCajaNegativa: la salida de efectivo deja el turno elegido en negativo. No bloquea. --}}
@use('App\Support\Decimal')
<div class="notice caja-alerta" role="alert" data-test="caja-negativa" style="grid-column:1/-1">
    <x-icon name="info" />
    <div>
        <b>{{ __('La caja quedaría en negativo') }}</b>
        <p>{{ __('Según el sistema, en :caja hay :disponible en efectivo; esta salida de :salida la dejaría en :quedaria.', [
            'caja' => $avisoCaja['caja'], 'disponible' => Decimal::bs($avisoCaja['disponible']),
            'salida' => Decimal::bs($avisoCaja['salida']), 'quedaria' => Decimal::bs($avisoCaja['quedaria']),
        ]) }}
        {{ $avisoCaja['externo']
            ? __('Si el dinero no sale de esa caja (por ejemplo, lo pone el administrador), elige «Fuera de caja (externo)». Se puede registrar igual, pero el arqueo de ese turno no cuadrará.')
            : __('Revisa el importe. Si el dinero no sale de esa caja, pide a un administrador que lo registre fuera de caja.') }}</p>
    </div>
</div>
