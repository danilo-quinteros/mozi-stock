{{--
    Tarjetas de planes con el precio real de la tabla planes. Qué incluye cada tarjeta sale de sus
    banderas (siat_habilitado, agente_ia); se destaca el más completo. Con 3 planes van en 3 columnas.
--}}
@props(['planes'])
@php
    $dias = config('mozistock.prueba_gratis_dias');
    $lineas = [
        'base' => ['Inventario por sucursal, ajustes y traspasos', 'Pedidos, punto de venta y devoluciones', 'Créditos, abonos y cuentas por pagar', 'Caja por turno, arqueo y egresos', 'Reportes con descarga en CSV', 'Sucursales y usuarios'],
        'siat' => ['Facturación en línea con el SIAT', 'Facturas desde tus ventas confirmadas', 'Modalidad computarizada o electrónica', 'Estado, impresión y XML de cada factura', 'Anulación de facturas desde el sistema'],
        'ia' => ['Mozi, tu agente de IA, dentro del sistema', 'Pregúntale por ventas, compras y stock en tus palabras', 'Qué se va a agotar y qué conviene reponer', 'A quién cobrar primero y qué no se vende', 'Respuestas con tablas y gráficos de tus datos reales'],
    ];
    $extras = \App\Domain\SitioWeb\PlanesSitio::extras($planes);
    $bs = fn (string $monto): string => \App\Support\Decimal::bs($monto);
    $destacado = $planes->last(fn (array $p): bool => $p['ia'])['codigo'] ?? $planes->last(fn (array $p): bool => $p['siat'])['codigo'] ?? null;
@endphp
@if ($planes->isEmpty())
    <div class="callout" style="max-width:640px;margin:0 auto"><x-sitio.i n="wa" /><span>Escríbenos por WhatsApp y te enviamos los precios actualizados.</span></div>
@else
    <div @class(['plans', 'is-3' => $planes->count() >= 3])>
        @foreach ($planes as $plan)
            @php
                $anterior = $loop->first ? null : $planes[$loop->index - 1]['nombre'];
                $incluye = match (true) {
                    $plan['ia'] => [...($anterior ? ['Todo el plan '.$anterior] : $lineas['base']), ...$lineas['ia']],
                    $plan['siat'] => [...($anterior ? ['Todo el plan '.$anterior] : $lineas['base']), ...$lineas['siat']],
                    default => $lineas['base'],
                };
                $descripcion = match (true) {
                    $plan['ia'] => 'Todo lo anterior, más Mozi: tu agente de IA que analiza tu negocio y te dice qué hacer.',
                    $plan['siat'] => 'Todo lo del plan Inventario, más facturación en línea con el SIAT.',
                    default => 'Para controlar stock, ventas y cobros sin emitir facturas.',
                };
                $esDestacado = $plan['codigo'] === $destacado;
            @endphp
            <article @class(['plan', 'reveal', 'plan-featured' => $esDestacado, 'plan-ia' => $plan['ia']]) style="--d:{{ $loop->index * 100 }}ms">
                @if ($esDestacado)<span class="plan-ribbon">{{ $plan['ia'] ? 'Con agente de IA' : 'Más completo' }}</span>@endif
                <div>
                    <h3>@if ($plan['ia'])<x-sitio.i n="sparkles" class="plan-ia-ico" />@endif{{ $plan['nombre'] }}</h3>
                    <p class="plan-desc">{{ $descripcion }}</p>
                </div>
                <p class="price"><small>Bs</small><b>{{ $plan['entero'] }}</b>@if ($plan['centavos'] !== '00')<small class="cents">,{{ $plan['centavos'] }}</small>@endif<span>/ mes</span></p>
                @if ($plan['sucursales'] !== null || $plan['usuarios'] !== null)
                    <p class="plan-cupo"><x-sitio.i n="store" /><span>Incluye <b>{{ $plan['sucursales'] === null ? 'sucursales sin límite' : trans_choice(':count sucursal|:count sucursales', $plan['sucursales']) }}</b> y <b>{{ $plan['usuarios'] === null ? 'usuarios sin límite' : trans_choice(':count usuario|:count usuarios', $plan['usuarios']) }}</b></span></p>
                @endif
                <ul class="checks">
                    @foreach ($incluye as $linea)
                        <li><x-sitio.i n="check" />@if ($loop->first && $anterior !== null && ($plan['siat'] || $plan['ia']))<strong>{{ $linea }}</strong>@else{{ $linea }}@endif</li>
                    @endforeach
                </ul>
                <x-sitio.prueba :plan="$plan['codigo']" :class="'btn btn-lg btn-block '.($esDestacado ? 'btn-primary' : 'btn-ghost')">Probar gratis {{ $dias }} días</x-sitio.prueba>
            </article>
        @endforeach
    </div>
    @if ($extras)
        <div class="plan-extras reveal" aria-label="Extras">
            <div class="plan-extras-items">
                <b>¿Necesitas más?</b>
                @if ($extras['sucursal'] !== null)
                    <span><x-sitio.i n="store" />Sucursal adicional: {{ $extras['sucursal_desde'] ? 'desde ' : '' }}<strong>{{ $bs($extras['sucursal']) }}</strong> / mes</span>
                @endif
                @if ($extras['paquete'] !== null)
                    <span><x-sitio.i n="users" />{{ $extras['usuarios_paquete'] }} usuarios más: {{ $extras['paquete_desde'] ? 'desde ' : '' }}<strong>{{ $bs($extras['paquete']) }}</strong> / mes</span>
                @endif
            </div>
            <div class="plan-extras-empresa">
                <span><strong>Plan Empresa</strong> · muchas sucursales o una cadena: precio especial.</span>
                <x-sitio.wa class="btn btn-ghost" mensaje="Hola, me interesa el plan Empresa de MoziStock para mi negocio con varias sucursales."><x-sitio.i n="wa" />Escríbenos</x-sitio.wa>
            </div>
        </div>
    @endif
    <div class="plan-notes">
        <p class="note"><x-sitio.i n="gift" /><span>{{ $dias }} días de prueba gratis, sin tarjeta. Al terminar eliges tu plan.</span></p>
        <p class="note"><x-sitio.i n="swap" /><span>Cambia de plan cuando quieras. Si subes, pagas solo la diferencia por los días que faltan.</span></p>
        <p class="note"><x-sitio.i n="qr" /><span>Pagas tu mensualidad con QR, desde el mismo sistema.</span></p>
    </div>
@endif
