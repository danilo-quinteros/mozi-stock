@use('App\Support\Decimal')
{{-- Rendimiento de una orden o producto: «96 %» con la merma o el excedente (reportes de producción). --}}
@if ($r === null)
    —
@else
    @php
        $signo = Decimal::comparar($r['diferencia'], '0');
    @endphp
    <b>{{ Decimal::formatear($r['valor'], 1, 1) }} %</b>
    @if ($signo !== 0)
        <small @class(['danger-text' => $signo < 0, 'success-text' => $signo > 0]) style="display:block;margin-top:4px">
            {{ $signo < 0 ? __('Merma :porcentaje %', ['porcentaje' => Decimal::formatear(ltrim($r['diferencia'], '-'), 1, 1)]) : __('Excedente :porcentaje %', ['porcentaje' => Decimal::formatear($r['diferencia'], 1, 1)]) }}
        </small>
    @endif
@endif
