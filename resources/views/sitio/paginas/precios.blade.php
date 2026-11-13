<x-sitio.layout :pagina="$pagina" :seo="$seo" :esquema="$esquema">
    @php
        $dias = config('mozistock.prueba_gratis_dias');
    @endphp
    <x-sitio.hero :pagina="$pagina"
        etiqueta="Precios en bolivianos"
        titulo="Precios de MoziStock en <span class='hl'>Bolivia</span>"
        lead="Planes mensuales en bolivianos: empieza con inventario y ventas, suma la facturación SIAT y, si quieres, a Mozi, tu agente de IA. Pruébalo gratis {{ $dias }} días, sin tarjeta, y paga tu mensualidad con QR."
        :ctas="false" />

    <section class="section" style="padding-top:0" aria-labelledby="pr-planes">
        <div class="wrap">
            <h2 class="sr-only" id="pr-planes">Planes</h2>
            <x-sitio.planes :planes="$planes" />
        </div>
    </section>

    @if ($planes->count() > 1)
        <section class="section section-alt" aria-labelledby="pr-comparar">
            <div class="wrap">
                <header class="section-head">
                    <p class="eyebrow">Comparar</p>
                    <h2 id="pr-comparar">¿Qué incluye cada plan?</h2>
                </header>
                @php
                    // Segundo valor: en qué planes está (todos, los que tienen SIAT o los que tienen el agente).
                    $filas = [
                        ['Inventario por sucursal, ajustes y traspasos', 'todos'],
                        ['Punto de venta, pedidos y cotizaciones', 'todos'],
                        ['Devoluciones', 'todos'],
                        ['Ventas a crédito, abonos y plan de cuotas', 'todos'],
                        ['Compras y cuentas por pagar', 'todos'],
                        ['Caja por turno, arqueo y egresos', 'todos'],
                        ['Reportes y descarga en CSV', 'todos'],
                        ['Sucursales, cajas y usuarios', 'todos'],
                        ['Facturación en línea con el SIAT', 'siat'],
                        ['Impresión con QR del SIN, XML y anulación', 'siat'],
                        ['Mozi, agente de IA: análisis, alertas y sugerencias', 'ia'],
                    ];
                @endphp
                <div class="table-wrap" style="max-width:1000px;margin:0 auto">
                    <table class="compare">
                        <thead>
                            <tr><th scope="col">Funcionalidad</th>@foreach ($planes as $plan)<th scope="col">{{ $plan['nombre'] }}</th>@endforeach</tr>
                        </thead>
                        <tbody>
                            @foreach ($filas as [$funcion, $donde])
                                <tr>
                                    <th scope="row">{{ $funcion }}</th>
                                    @foreach ($planes as $plan)
                                        <td>
                                            @if ($donde === 'todos' || ($donde === 'siat' && $plan['siat']) || ($donde === 'ia' && $plan['ia']))
                                                <x-sitio.i n="check" /><span class="sr-only">Incluido</span>
                                            @else
                                                <x-sitio.i n="minus" class="no" /><span class="sr-only">No incluido</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @endif

    <section class="section" aria-labelledby="pr-faq">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Preguntas</p>
                <h2 id="pr-faq">Preguntas sobre precios y pagos</h2>
            </header>
            <x-sitio.faq :preguntas="[
                ['¿Cuánto dura la prueba gratis?', 'La prueba dura '.$dias.' días con el plan que elijas. No pedimos tarjeta. Al terminar decides si continúas y sigues con la misma información.'],
                ['¿Cómo pago la mensualidad?', 'Con QR desde la sección Suscripción del sistema. Subes tu comprobante y lo revisamos.'],
                ['¿Puedo cambiar de plan?', 'Sí, cuando quieras. Si subes de plan, pagas solo la diferencia por los días que faltan de tu mes.'],
                ['¿Qué diferencia hay entre los planes?', 'El plan Inventario tiene inventario, ventas, créditos, compras, caja y reportes. Inventario + SIAT suma la facturación en línea. SIAT + Agente IA suma además a Mozi, el agente de IA que analiza tu negocio.'],
                ['¿Y si tengo más sucursales o más personas?', 'Sumas sucursales o paquetes de usuarios a tu plan desde Mi suscripción, cuando quieras. Tu mensualidad es el plan más tus extras. Si los agregas a mitad del mes pagas solo la parte que falta; para muchas sucursales tenemos el plan Empresa con precio especial.'],
                ['¿Qué pasa si me atraso con el pago?', 'Te enviamos recordatorios antes y después del vencimiento. Tu cuenta no se bloquea de forma automática: siempre conversamos contigo primero.'],
            ]" />
        </div>
    </section>

    <x-sitio.cta titulo="Empieza gratis hoy" :mensaje="$seo->mensajeWhatsapp" />
</x-sitio.layout>
