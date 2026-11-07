<x-sitio.layout :pagina="$pagina" :seo="$seo" :esquema="$esquema">
    <x-sitio.hero :pagina="$pagina"
        etiqueta="Facturación en línea · SIAT"
        titulo="Facturación electrónica SIAT en <span class='hl'>Bolivia</span>, desde tus ventas"
        lead="Emite facturas en línea con el Servicio de Impuestos Nacionales sin salir de tu sistema de ventas. Vendes, confirmas y MoziStock envía la factura al SIAT. Tu inventario, tus cobros y tus facturas, en el mismo lugar."
        :puntos="['Computarizada o electrónica en línea', 'Impresión con QR del SIN', 'Anulación desde el sistema']">
        <x-slot:visual>
            <div class="invoice" style="transform:none;padding:24px">
                <div class="invoice-head"><b>Factura</b><em class="badge ok">Validada por el SIN</em></div>
                <div class="invoice-meta"><span>N.º 1.248 · Casa Matriz</span><span>NIT 1234567</span></div>
                <div class="invoice-lines">
                    <div><span>Cemento 50 kg × 4</span><b>Bs 260,00</b></div>
                    <div><span>Fierro corrugado 3/8 × 10</span><b>Bs 450,00</b></div>
                    <div><span>Alambre de amarre 1 kg × 2</span><b>Bs 36,00</b></div>
                </div>
                <div class="invoice-total"><span>Total</span><b>Bs 746,00</b></div>
                <div class="invoice-foot"><x-sitio.i n="qr" /><small>Código de autorización y QR de verificación del SIN</small></div>
            </div>
        </x-slot:visual>
    </x-sitio.hero>

    <section class="section" aria-labelledby="f-funciones">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Qué incluye</p>
                <h2 id="f-funciones">Todo lo que necesitas para facturar con el SIAT</h2>
                <p class="lead">Un sistema de facturación conectado a tu inventario: no copias ventas de un programa a otro ni cuadras dos veces.</p>
            </header>
            <x-sitio.funciones :items="[
                ['receipt', 'Factura desde la venta', 'Al confirmar una venta emites su factura con los productos, cantidades y precios que ya registraste. Sin volver a escribir nada.'],
                ['shield', 'Dos modalidades', 'Facturación computarizada en línea o facturación electrónica en línea con firma digital, según lo que te corresponda ante el SIN.'],
                ['qr', 'Impresión con QR', 'Cada factura validada se imprime con su código de autorización y el QR de verificación del Servicio de Impuestos Nacionales.'],
                ['download', 'XML y estado', 'Consulta el estado de cada factura y descarga su archivo XML cuando lo necesites, por ejemplo para tu contador.'],
                ['return', 'Anulación ordenada', 'Anula una factura desde el sistema indicando el motivo, como exige el SIN. Queda registrado quién la anuló y cuándo.'],
                ['store', 'Por sucursal y punto de venta', 'Configura los datos fiscales de cada sucursal y punto de venta registrado ante el SIN.'],
            ]" />
        </div>
    </section>

    <section class="section section-alt" aria-labelledby="f-como">
        <div class="wrap">
            <div class="split">
                <div class="split-copy reveal">
                    <p class="eyebrow">Cómo funciona</p>
                    <h2 id="f-como">Vendes como siempre, la factura sale sola</h2>
                    <p>La facturación SIAT de MoziStock trabaja sobre tus ventas confirmadas. El vendedor cobra en efectivo, QR o mixto, ingresa el NIT o carnet del cliente y el sistema arma la factura con los códigos del SIN.</p>
                    <ul class="checks">
                        <li><x-sitio.i n="check" />Los productos llevan su unidad de medida y código del catálogo del SIN.</li>
                        <li><x-sitio.i n="check" />Los catálogos oficiales del SIAT se sincronizan desde el sistema.</li>
                        <li><x-sitio.i n="check" />Las cotizaciones y pedidos pendientes no se facturan: solo las ventas confirmadas.</li>
                        <li><x-sitio.i n="check" />Cada factura queda unida a su venta, a su cobro y al movimiento de stock.</li>
                    </ul>
                </div>
                <div class="visual-card reveal" aria-hidden="true">
                    <div class="visual-title">Venta V-01248<small>Casa Matriz · Caja 1</small></div>
                    <div class="demo">
                        <div class="demo-row"><span><b>Cliente</b><small>NIT 1234567</small></span><strong>Ferretería El Maestro</strong></div>
                        <div class="demo-row"><span><b>Cobro</b><small>Mixto</small></span><strong>Bs 746,00</strong></div>
                        <div class="demo-foot"><span>Factura SIAT</span><em class="badge ok">Validada</em></div>
                    </div>
                </div>
            </div>
            <div class="split reverse">
                <div class="split-copy reveal">
                    <p class="eyebrow">Te acompañamos</p>
                    <h2>Configuración guiada de tus datos fiscales</h2>
                    <p>Para facturar en línea tu empresa debe estar habilitada ante el Servicio de Impuestos Nacionales. Te guiamos para cargar tu NIT, tus sucursales y puntos de venta del SIN y, si usas facturación electrónica, tu certificado de firma digital.</p>
                    <p>Mientras tanto puedes usar MoziStock sin facturar: inventario, ventas, créditos, caja y reportes funcionan igual con el plan Inventario.</p>
                </div>
                <div class="visual-card reveal" aria-hidden="true">
                    <div class="visual-title">Datos fiscales<small>Paso 2 de 3</small></div>
                    <div class="demo">
                        <div class="demo-row"><span>NIT de la empresa</span><strong><x-sitio.i n="check" style="color:var(--ok)" /></strong></div>
                        <div class="demo-row"><span>Sucursal y punto de venta del SIN</span><strong><x-sitio.i n="check" style="color:var(--ok)" /></strong></div>
                        <div class="demo-row"><span>Modalidad</span><strong>Computarizada en línea</strong></div>
                        <div class="meter"><span style="--p:66%"></span></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="f-faq">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Preguntas</p>
                <h2 id="f-faq">Preguntas sobre la facturación SIAT</h2>
            </header>
            <x-sitio.faq :preguntas="[
                ['¿Qué es el SIAT?', 'El SIAT es el Sistema Integrado de Administración Tributaria del Servicio de Impuestos Nacionales (SIN) de Bolivia. Con la facturación en línea, cada factura se envía al SIN en el momento en que se emite.'],
                ['¿Qué modalidades de facturación soporta MoziStock?', 'Facturación computarizada en línea y facturación electrónica en línea. La electrónica firma cada factura con tu certificado digital; la computarizada no lo necesita.'],
                ['¿Necesito firma digital?', 'Solo si te corresponde la modalidad electrónica en línea. En la modalidad computarizada en línea no necesitas certificado de firma digital.'],
                ['¿Puedo anular una factura?', 'Sí. Desde el sistema anulas la factura indicando el motivo y el SIN la registra como anulada. Queda en el historial quién la anuló y cuándo.'],
                ['¿Qué necesito para empezar a facturar?', 'Tu NIT activo y la habilitación de tu empresa para facturación en línea ante el SIN. Te acompañamos en la configuración de tus datos fiscales, sucursales y puntos de venta.'],
                ['¿Puedo empezar sin facturar y activarlo después?', 'Sí. Empiezas con el plan Inventario y, cuando quieras facturar, pasas al plan Inventario + SIAT pagando solo la diferencia por los días que faltan de tu mes.'],
            ]" />
        </div>
    </section>

    <x-sitio.relacionados actual="facturacion" :claves="['punto-de-venta', 'inventario', 'reportes']" />
    <x-sitio.cta titulo="Factura con el SIAT desde tu sistema de ventas" :mensaje="$seo->mensajeWhatsapp" />
</x-sitio.layout>
