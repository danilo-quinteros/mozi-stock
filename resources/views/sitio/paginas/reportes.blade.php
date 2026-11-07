<x-sitio.layout :pagina="$pagina" :seo="$seo" :esquema="$esquema">
    <x-sitio.hero :pagina="$pagina"
        etiqueta="Reportes del negocio"
        titulo="Reportes de ventas, cobros e inventario en <span class='hl'>Bolivia</span>"
        lead="Mira cuánto vendiste, cuánto cobraste de verdad y cuánto tienes en cada sucursal. Filtra por fechas y sucursal, compara periodos y descarga todo en CSV para abrirlo en Excel."
        :puntos="['Vendido y cobrado por separado', 'Por sucursal y fecha', 'Descarga en CSV']">
        <x-slot:visual>
            <div class="visual-card">
                <div class="visual-title">Resumen de la semana<small>Todas las sucursales</small></div>
                <div class="demo">
                    <div class="hbar"><span>Vendido</span><i><s style="--w:100%"></s></i><b>Bs 18.420</b></div>
                    <div class="hbar"><span>Cobrado</span><i><s class="alt" style="--w:81%"></s></i><b>Bs 14.950</b></div>
                </div>
                <div class="demo">
                    <div class="demo-row"><span><b>Más vendido</b><small>Arroz 5 kg</small></span><strong>142 u.</strong></div>
                    <div class="demo-row"><span><b>Por cobrar</b><small>12 créditos</small></span><strong>Bs 3.470</strong></div>
                </div>
            </div>
        </x-slot:visual>
    </x-sitio.hero>

    <section class="section" aria-labelledby="r-funciones">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Qué incluye</p>
                <h2 id="r-funciones">Decide con números claros</h2>
                <p class="lead">Reportes simples, pensados para el dueño del negocio y no para un contador.</p>
            </header>
            <x-sitio.funciones :items="[
                ['chart', 'Ventas del periodo', 'Ventas confirmadas por día, sucursal, vendedor o producto. Las cotizaciones y pedidos pendientes no se cuentan.'],
                ['wallet', 'Cobros reales', 'Lo que entró en efectivo y electrónico según la fecha de cobro, incluidas cuotas iniciales y abonos de créditos.'],
                ['box', 'Inventario por sucursal', 'Existencias y valor del inventario en cada sucursal, con los productos bajo el stock mínimo.'],
                ['return', 'Salidas y devoluciones', 'Salidas de stock por motivo y devoluciones por producto, para detectar pérdidas a tiempo.'],
                ['dashboard', 'Panel de inicio', 'Al entrar ves lo cobrado y lo vendido hoy, lo que tienes por cobrar y tus últimas operaciones.'],
                ['download', 'Descarga en CSV', 'Descarga cualquier reporte en CSV y ábrelo en Excel o envíalo a tu contador.'],
            ]" />
        </div>
    </section>

    <section class="section section-alt" aria-labelledby="r-ia">
        <div class="wrap">
            <div class="split">
                <div class="split-copy reveal">
                    <p class="eyebrow">Pregúntale a Mozi</p>
                    <h2 id="r-ia">Reportes a medida con inteligencia artificial</h2>
                    <p>¿Necesitas algo que no está en un reporte? Pregúntale al asistente Mozi: «¿qué productos no se vendieron este mes?», «¿cuál es mi sucursal más rentable?». Te responde con tus datos reales, en tablas y gráficos.</p>
                    <a class="module-link" href="{{ route('sitio.asistente-ia') }}">Conocer el asistente de IA<x-sitio.i n="arrow" /></a>
                </div>
                <div class="visual-card reveal" aria-hidden="true">
                    <div class="chat">
                        <span class="bubble me">¿Qué productos no se vendieron este mes?</span>
                        <span class="bubble bot" style="display:block;font-weight:600">Encontré 14 productos sin ventas en 30 días, con Bs 3.200 inmovilizados. Te los muestro en el lienzo.</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="r-faq">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Preguntas</p>
                <h2 id="r-faq">Preguntas sobre los reportes</h2>
            </header>
            <x-sitio.faq :preguntas="[
                ['¿Por qué separar lo vendido de lo cobrado?', 'Porque una venta a crédito no es dinero en tu caja. MoziStock muestra las ventas por su valor comercial y los cobros por la fecha en que realmente recibiste el dinero.'],
                ['¿Puedo exportar los reportes a Excel?', 'Sí. Todos los reportes se descargan en formato CSV, que se abre directamente en Excel o Google Sheets.'],
                ['¿Puedo ver los reportes de una sola sucursal?', 'Sí. Filtras por sucursal y por rango de fechas, o ves toda la empresa junta.'],
                ['¿Quién puede ver los reportes?', 'Los administradores de tu empresa. Los vendedores no acceden a los reportes generales.'],
            ]" />
        </div>
    </section>

    <x-sitio.relacionados actual="reportes" :claves="['asistente-ia', 'caja', 'inventario']" />
    <x-sitio.cta titulo="Conoce tus números de verdad" :mensaje="$seo->mensajeWhatsapp" />
</x-sitio.layout>
