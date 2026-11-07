<x-sitio.layout :pagina="$pagina" :seo="$seo" :esquema="$esquema">
    <x-sitio.hero :pagina="$pagina"
        etiqueta="Créditos y cobranzas"
        titulo="Ventas a crédito y cobranzas sin cuaderno en <span class='hl'>Bolivia</span>"
        lead="Vende a crédito con cuota inicial, registra cada abono el día que lo cobras y sabe en todo momento quién te debe, cuánto y desde cuándo. Tus cobros del día muestran lo que realmente entró a caja."
        :puntos="['Cuota inicial desde Bs 0', 'Historial de pagos por venta', 'Saldo por cliente']">
        <x-slot:visual>
            <div class="visual-card">
                <div class="visual-title">Crédito V-01231<small>Juan Mamani</small></div>
                <div class="demo">
                    <div class="demo-row"><span><b>Lunes · cuota inicial</b><small>Efectivo</small></span><strong>Bs 50,00</strong></div>
                    <div class="demo-row"><span><b>Martes · abono</b><small>QR</small></span><strong>Bs 30,00</strong></div>
                    <div class="meter"><span style="--p:40%"></span></div>
                    <div class="demo-foot"><span>Pagado <b>Bs 80,00</b> de Bs 200,00</span><span>Saldo <b class="accent">Bs 120,00</b></span></div>
                </div>
            </div>
        </x-slot:visual>
    </x-sitio.hero>

    <section class="section" aria-labelledby="cr-funciones">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Qué incluye</p>
                <h2 id="cr-funciones">Fía con orden y cobra a tiempo</h2>
                <p class="lead">Todo lo que antes anotabas en un cuaderno, ahora claro, seguro y en tu celular.</p>
            </header>
            <x-sitio.funciones :items="[
                ['wallet', 'Venta a crédito', 'Confirma la venta con una cuota inicial, incluso de Bs 0. El stock sale en el momento y el saldo queda pendiente.'],
                ['calendar', 'Plan de cuotas', 'Define las fechas y montos de las cuotas para que tú y tu cliente sepan cuándo toca pagar.'],
                ['cash', 'Abonos en la misma pantalla', 'Registra el abono desde el historial de pagos de la venta: efectivo, electrónico o mixto.'],
                ['users', 'Saldo por cliente', 'Mira cuánto te debe cada cliente, en cuántas ventas y desde cuándo, y cóbrale todo en un solo pago.'],
                ['doc', 'Historial imprimible', 'Imprime o descarga el historial de pagos de una venta para entregárselo a tu cliente.'],
                ['sparkles', 'Cobranza priorizada', 'El asistente Mozi te dice a quién conviene cobrar primero según el monto y la antigüedad de la deuda.'],
            ]" />
        </div>
    </section>

    <section class="section section-alt" aria-labelledby="cr-reglas">
        <div class="wrap">
            <div class="split">
                <div class="split-copy reveal">
                    <p class="eyebrow">Cuentas claras</p>
                    <h2 id="cr-reglas">Lo vendido no es lo cobrado</h2>
                    <p>Si vendes Bs 200 a crédito el lunes con Bs 50 de inicial, tu cliente abona Bs 30 el martes y Bs 120 el jueves, tus reportes de cobros dicen exactamente eso: Bs 50 el lunes, Bs 30 el martes y Bs 120 el jueves. Nunca Bs 200 el lunes.</p>
                    <ul class="checks">
                        <li><x-sitio.i n="check" />Cada abono descuenta solo el saldo pendiente: no se puede cobrar de más.</li>
                        <li><x-sitio.i n="check" />Cada cobro se suma una sola vez a los ingresos del día en que se recibió.</li>
                        <li><x-sitio.i n="check" />En pagos mixtos se guarda cuánto fue en efectivo y cuánto electrónico.</li>
                    </ul>
                </div>
                <div class="visual-card reveal" aria-hidden="true">
                    <div class="visual-title">Cobros de la semana<small>Crédito V-01231</small></div>
                    <div class="demo">
                        <div class="hbar"><span>Lunes</span><i><s class="alt" style="--w:42%"></s></i><b>Bs 50</b></div>
                        <div class="hbar"><span>Martes</span><i><s class="alt" style="--w:25%"></s></i><b>Bs 30</b></div>
                        <div class="hbar"><span>Jueves</span><i><s class="alt" style="--w:100%"></s></i><b>Bs 120</b></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="cr-faq">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Preguntas</p>
                <h2 id="cr-faq">Preguntas sobre ventas a crédito</h2>
            </header>
            <x-sitio.faq :preguntas="[
                ['¿Puedo vender a crédito sin cuota inicial?', 'Sí. La cuota inicial puede ser de Bs 0. La venta se confirma, el stock sale y todo el monto queda como saldo pendiente.'],
                ['¿Cómo registro un abono?', 'Abres el historial de pagos de la venta y registras el abono en la misma página, en efectivo, electrónico o mixto.'],
                ['¿El abono aparece en mi caja del día?', 'Sí. Cada abono se suma una sola vez a los ingresos del día en que lo recibiste, no del día de la venta.'],
                ['¿Puedo ver cuánto me debe un cliente en total?', 'Sí. La ficha del cliente muestra su saldo pendiente en todas sus ventas a crédito y puedes cobrarle en un solo pago.'],
            ]" />
        </div>
    </section>

    <x-sitio.relacionados actual="creditos" :claves="['punto-de-venta', 'caja', 'reportes']" />
    <x-sitio.cta titulo="Deja el cuaderno de fiados" :mensaje="$seo->mensajeWhatsapp" />
</x-sitio.layout>
