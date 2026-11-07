<x-sitio.layout :pagina="$pagina" :seo="$seo" :esquema="$esquema">
    <x-sitio.hero :pagina="$pagina"
        etiqueta="Punto de venta · POS"
        titulo="Sistema punto de venta para negocios en <span class='hl'>Bolivia</span>"
        lead="Vende rápido con tu lector de código de barras, elige el precio de cada cliente y cobra en efectivo, QR o mixto. Cada venta descuenta el stock de tu sucursal y queda registrada en tu caja."
        :puntos="['Lector de código de barras', 'Precio consumidor, minorista o mayorista', 'Pedidos y cotizaciones']">
        <x-slot:visual>
            <div class="visual-card">
                <div class="visual-title">Venta rápida<small>Sucursal Norte · Caja 2</small></div>
                <div class="demo">
                    <div class="demo-row"><span><b>Aceite 1 L × 2</b><small>Precio consumidor</small></span><strong>Bs 26,00</strong></div>
                    <div class="demo-row"><span><b>Arroz 5 kg × 1</b><small>Precio consumidor</small></span><strong>Bs 59,00</strong></div>
                    <div class="demo-row"><span><b>Detergente 1 kg × 1</b><small>Escaneado</small></span><strong>Bs 18,50</strong></div>
                    <div class="demo-foot"><span>Total</span><b class="accent">Bs 103,50</b></div>
                </div>
                <div class="seg"><span>Efectivo</span><span class="on">QR</span><span>Mixto</span></div>
            </div>
        </x-slot:visual>
    </x-sitio.hero>

    <section class="section" aria-labelledby="p-funciones">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Qué incluye</p>
                <h2 id="p-funciones">Vender, cobrar y facturar en una sola pantalla</h2>
                <p class="lead">Pensado para el mostrador: pocos clics, letra clara y funciona en la computadora, la tablet o el celular.</p>
            </header>
            <x-sitio.funciones :items="[
                ['barcode', 'Código de barras', 'Escanea con tu lector o busca por nombre. El producto entra a la venta con su precio y su stock disponible a la vista.'],
                ['tag', 'Tres listas de precios', 'Precio consumidor, minorista y mayorista por producto. Eliges el que corresponde a cada cliente al momento de vender.'],
                ['qr', 'Efectivo, QR o mixto', 'En un pago mixto registras los dos importes y el sistema verifica que sumen el total exacto, céntimo por céntimo.'],
                ['doc', 'Pedidos y cotizaciones', 'Prepara una cotización o un pedido para tu cliente. Mientras está pendiente no descuenta stock ni cuenta como cobro.'],
                ['return', 'Devoluciones', 'Registra la devolución de una venta por producto y motivo. El stock vuelve a la sucursal y todo queda en el historial.'],
                ['wallet', 'Ventas a crédito', 'Vende con cuota inicial, incluso de Bs 0, y registra los abonos después. El saldo de cada cliente siempre al día.'],
            ]" />
        </div>
    </section>

    <section class="section section-alt" aria-labelledby="p-seguro">
        <div class="wrap">
            <div class="split">
                <div class="split-copy reveal">
                    <p class="eyebrow">Sin errores</p>
                    <h2 id="p-seguro">Una venta es una venta, aunque hagan doble clic</h2>
                    <p>MoziStock protege cada venta contra los reintentos y el doble clic: la venta, su pago y su movimiento de stock se registran una sola vez. Si algo falla en el camino, no queda nada a medias.</p>
                    <ul class="checks">
                        <li><x-sitio.i n="check" />Solo la venta confirmada descuenta stock y registra el cobro.</li>
                        <li><x-sitio.i n="check" />El vendedor vende solo en su sucursal y su caja asignadas.</li>
                        <li><x-sitio.i n="check" />Comprobante de venta para imprimir y, con el plan SIAT, factura en línea.</li>
                    </ul>
                </div>
                <div class="visual-card reveal" aria-hidden="true">
                    <div class="visual-title">Pedido COT-0042<small>Pendiente</small></div>
                    <div class="demo">
                        <div class="demo-row"><span><b>Estado</b><small>Aún no es venta</small></span><em class="badge warn">Pendiente</em></div>
                        <div class="demo-row"><span>Descuenta stock</span><strong>No</strong></div>
                        <div class="demo-row"><span>Suma a cobros de hoy</span><strong>No</strong></div>
                        <div class="demo-foot"><span>Al confirmar</span><em class="badge ok">Venta + stock + cobro</em></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="p-faq">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Preguntas</p>
                <h2 id="p-faq">Preguntas sobre el punto de venta</h2>
            </header>
            <x-sitio.faq :preguntas="[
                ['¿Funciona con lector de código de barras?', 'Sí. Cualquier lector USB o inalámbrico que funcione como teclado sirve: escaneas el código y el producto entra a la venta.'],
                ['¿Puedo tener precios distintos para mayoristas?', 'Sí. Cada producto puede tener precio consumidor, minorista y mayorista, y eliges cuál usar en cada venta.'],
                ['¿Cómo registro un pago mixto?', 'Indicas cuánto fue en efectivo y cuánto por QR o transferencia. El sistema verifica que la suma sea exactamente el total de la venta.'],
                ['¿Una cotización descuenta stock?', 'No. Las cotizaciones y pedidos pendientes no venden, no descuentan stock y no cuentan como cobro. Solo la venta confirmada lo hace.'],
                ['¿Necesito una computadora especial?', 'No. MoziStock funciona en el navegador de cualquier computadora, tablet o celular con internet.'],
            ]" />
        </div>
    </section>

    <x-sitio.relacionados actual="punto-de-venta" :claves="['inventario', 'caja', 'creditos', 'facturacion', 'reportes', 'multisucursal']" />
    <x-sitio.cta titulo="Vende más rápido desde hoy" :mensaje="$seo->mensajeWhatsapp" />
</x-sitio.layout>
