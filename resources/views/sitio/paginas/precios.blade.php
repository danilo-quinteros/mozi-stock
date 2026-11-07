<x-sitio.layout :pagina="$pagina" :seo="$seo" :esquema="$esquema">
    @php
        $dias = config('mozistock.prueba_gratis_dias');
    @endphp
    <x-sitio.hero :pagina="$pagina"
        etiqueta="Precios en bolivianos"
        titulo="Precios de MoziStock en <span class='hl'>Bolivia</span>"
        lead="Dos planes mensuales con todos los módulos. La única diferencia es la facturación SIAT. Pruébalo gratis {{ $dias }} días, sin tarjeta, y paga tu mensualidad con QR."
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
                    $filas = [
                        ['Inventario por sucursal, ajustes y traspasos', true],
                        ['Punto de venta, pedidos y cotizaciones', true],
                        ['Devoluciones', true],
                        ['Ventas a crédito, abonos y plan de cuotas', true],
                        ['Compras y cuentas por pagar', true],
                        ['Caja por turno, arqueo y egresos', true],
                        ['Reportes y descarga en CSV', true],
                        ['Asistente de IA Mozi', true],
                        ['Sucursales, cajas y usuarios', true],
                        ['Facturación en línea con el SIAT', false],
                        ['Impresión con QR del SIN, XML y anulación', false],
                    ];
                @endphp
                <div class="table-wrap" style="max-width:900px;margin:0 auto">
                    <table class="compare">
                        <thead>
                            <tr><th scope="col">Funcionalidad</th>@foreach ($planes as $plan)<th scope="col">{{ $plan['nombre'] }}</th>@endforeach</tr>
                        </thead>
                        <tbody>
                            @foreach ($filas as [$funcion, $enTodos])
                                <tr>
                                    <th scope="row">{{ $funcion }}</th>
                                    @foreach ($planes as $plan)
                                        <td>
                                            @if ($enTodos || $plan['siat'])
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
                ['¿Cuánto dura la prueba gratis?', 'La prueba dura '.$dias.' días con todos los módulos. No pedimos tarjeta. Al terminar eliges tu plan y sigues con la misma información.'],
                ['¿Cómo pago la mensualidad?', 'Con QR desde la sección Suscripción del sistema. Subes tu comprobante y lo revisamos.'],
                ['¿Puedo cambiar de plan?', 'Sí, cuando quieras. Si pasas al plan con SIAT, pagas solo la diferencia por los días que faltan de tu mes.'],
                ['¿Los precios incluyen la facturación SIAT?', 'El plan Inventario + SIAT incluye la emisión de facturas en línea. El plan Inventario incluye todos los demás módulos, sin facturación.'],
                ['¿Qué pasa si me atraso con el pago?', 'Te enviamos recordatorios antes y después del vencimiento. Tu cuenta no se bloquea de forma automática: siempre conversamos contigo primero.'],
            ]" />
        </div>
    </section>

    <x-sitio.cta titulo="Empieza gratis hoy" :mensaje="$seo->mensajeWhatsapp" />
</x-sitio.layout>
