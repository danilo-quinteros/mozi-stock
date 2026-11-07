<x-sitio.layout :pagina="$pagina" :seo="$seo" :esquema="$esquema">
    <x-sitio.hero :pagina="$pagina"
        etiqueta="Compras y proveedores"
        titulo="Control de compras y proveedores en <span class='hl'>Bolivia</span>"
        lead="Registra cada compra a tus proveedores, actualiza el stock de la sucursal que recibe la mercadería y controla lo que debes y cuándo vence. Sabes cuánto te cuesta cada producto y cuánto ganas."
        :puntos="['Stock actualizado al recibir', 'Cuentas por pagar', 'Historial por proveedor']">
        <x-slot:visual>
            <div class="visual-card">
                <div class="visual-title">Compra C-0087<small>Distribuidora Andina</small></div>
                <div class="demo">
                    <div class="demo-row"><span><b>Aceite 1 L × 24</b><small>Costo unitario Bs 10,50</small></span><strong>Bs 252,00</strong></div>
                    <div class="demo-row"><span><b>Arroz 5 kg × 10</b><small>Costo unitario Bs 48,00</small></span><strong>Bs 480,00</strong></div>
                    <div class="demo-foot"><span>Total · a 30 días</span><b class="accent">Bs 732,00</b></div>
                </div>
            </div>
        </x-slot:visual>
    </x-sitio.hero>

    <section class="section" aria-labelledby="c-funciones">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Qué incluye</p>
                <h2 id="c-funciones">Compra mejor y paga a tiempo</h2>
                <p class="lead">Tus compras, tus proveedores y tus deudas con ellos, conectados con el inventario.</p>
            </header>
            <x-sitio.funciones :items="[
                ['truck', 'Registro de compras', 'Carga la compra con buscador o escáner, indica la sucursal que recibe y el stock sube al confirmar.'],
                ['tag', 'Costo por producto', 'Cada compra registra el costo unitario, para que tus reportes y el asistente Mozi calculen tu margen real.'],
                ['users', 'Ficha de proveedor', 'Datos de contacto, compras realizadas y saldo pendiente de cada proveedor en un solo lugar.'],
                ['calendar', 'Cuentas por pagar', 'Registra compras al crédito y otras obligaciones con su fecha de vencimiento. Ve qué vence esta semana.'],
                ['cash', 'Pagos parciales', 'Registra cada pago a un proveedor, total o parcial, en efectivo o electrónico, con su comprobante.'],
                ['download', 'Impresión y CSV', 'Imprime el detalle de una compra o descarga el listado de compras en CSV para tu contador.'],
            ]" />
        </div>
    </section>

    <section class="section section-alt" aria-labelledby="c-ia">
        <div class="wrap">
            <div class="split">
                <div class="split-copy reveal">
                    <p class="eyebrow">Con ayuda de Mozi</p>
                    <h2 id="c-ia">Sabe qué comprar antes de que se acabe</h2>
                    <p>El asistente de IA Mozi analiza tus ventas y tu stock por sucursal y te sugiere qué reponer y en qué cantidad. También te muestra qué proveedores subieron sus costos y qué pagos vencen pronto.</p>
                    <a class="module-link" href="{{ route('sitio.asistente-ia') }}">Conocer el asistente de IA<x-sitio.i n="arrow" /></a>
                </div>
                <div class="visual-card reveal" aria-hidden="true">
                    <div class="visual-title">Pagos a proveedores<small>Próximos 7 días</small></div>
                    <div class="demo">
                        <div class="demo-row"><span><b>Distribuidora Andina</b><small>Vence el lunes</small></span><strong>Bs 732,00</strong></div>
                        <div class="demo-row"><span><b>Importadora del Sur</b><small>Vence el jueves</small></span><strong>Bs 1.150,00</strong></div>
                        <div class="demo-foot"><span>Total por pagar</span><b class="accent">Bs 1.882,00</b></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="c-faq">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Preguntas</p>
                <h2 id="c-faq">Preguntas sobre compras y proveedores</h2>
            </header>
            <x-sitio.faq :preguntas="[
                ['¿La compra actualiza el stock automáticamente?', 'Sí. Al confirmar la compra, las cantidades entran al stock de la sucursal que recibió la mercadería.'],
                ['¿Puedo registrar compras al crédito?', 'Sí. La compra queda como cuenta por pagar con su vencimiento, y registras cada pago al proveedor cuando lo haces.'],
                ['¿Puedo adjuntar el comprobante de pago?', 'Sí. Cada pago puede llevar su comprobante, que se guarda de forma privada y solo ve tu empresa.'],
                ['¿Cómo sé qué proveedor me vende más caro?', 'Cada compra guarda el costo unitario. Mozi compara los costos por proveedor y te avisa cuando cambian.'],
            ]" />
        </div>
    </section>

    <x-sitio.relacionados actual="compras" :claves="['inventario', 'caja', 'asistente-ia']" />
    <x-sitio.cta titulo="Ordena tus compras y tus pagos" :mensaje="$seo->mensajeWhatsapp" />
</x-sitio.layout>
