{{-- Tarjetas de planes con el precio real de la tabla planes. --}}
@props(['planes'])
@php
    $dias = config('mozistock.prueba_gratis_dias');
    $incluye = [
        false => ['Inventario por sucursal, ajustes y traspasos', 'Pedidos, punto de venta y devoluciones', 'Créditos, abonos y cuentas por pagar', 'Caja por turno, arqueo y egresos', 'Reportes con descarga en CSV', 'Asistente de IA Mozi', 'Sucursales y usuarios'],
        true => ['Todo el plan Inventario', 'Facturación en línea con el SIAT', 'Facturas desde tus ventas confirmadas', 'Modalidad computarizada o electrónica', 'Estado, impresión y XML de cada factura', 'Anulación de facturas desde el sistema', 'Guía para configurar tus datos fiscales'],
    ];
@endphp
@if ($planes->isEmpty())
    <div class="callout" style="max-width:640px;margin:0 auto"><x-sitio.i n="wa" /><span>Escríbenos por WhatsApp y te enviamos los precios actualizados.</span></div>
@else
    <div class="plans">
        @foreach ($planes as $plan)
            <article @class(['plan', 'reveal', 'plan-featured' => $plan['siat']]) @if ($plan['siat']) style="--d:100ms" @endif>
                @if ($plan['siat'])<span class="plan-ribbon">Más completo</span>@endif
                <div>
                    <h3>{{ $plan['nombre'] }}</h3>
                    <p class="plan-desc">{{ $plan['siat'] ? 'Todo lo del plan Inventario, más facturación en línea con el SIAT.' : 'Para controlar stock, ventas y cobros sin emitir facturas.' }}</p>
                </div>
                <p class="price"><small>Bs</small><b>{{ $plan['entero'] }}</b>@if ($plan['centavos'] !== '00')<small class="cents">,{{ $plan['centavos'] }}</small>@endif<span>/ mes</span></p>
                <ul class="checks">
                    @foreach ($incluye[$plan['siat']] as $linea)
                        <li><x-sitio.i n="check" />@if ($loop->first && $plan['siat'])<strong>{{ $linea }}</strong>@else{{ $linea }}@endif</li>
                    @endforeach
                </ul>
                <x-sitio.prueba :plan="$plan['codigo']" :class="'btn btn-lg btn-block '.($plan['siat'] ? 'btn-primary' : 'btn-ghost')">Probar gratis {{ $dias }} días</x-sitio.prueba>
            </article>
        @endforeach
    </div>
    <div class="plan-notes">
        <p class="note"><x-sitio.i n="gift" /><span>{{ $dias }} días de prueba gratis, sin tarjeta. Al terminar eliges tu plan.</span></p>
        <p class="note"><x-sitio.i n="swap" /><span>Cambia de plan cuando quieras. Si subes, pagas solo la diferencia por los días que faltan.</span></p>
        <p class="note"><x-sitio.i n="qr" /><span>Pagas tu mensualidad con QR, desde el mismo sistema.</span></p>
    </div>
@endif
