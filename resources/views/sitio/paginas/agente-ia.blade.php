<x-sitio.layout :pagina="$pagina" :seo="$seo" :esquema="$esquema">
    <x-sitio.hero :pagina="$pagina"
        etiqueta="Inteligencia artificial para tu negocio"
        titulo="Mozi, el agente de IA para negocios en <span class='hl'>Bolivia</span>"
        lead="Pregúntale a Mozi como le preguntarías a tu mejor empleado: qué se está por acabar, qué conviene comprar, quién te debe más o qué sucursal vende mejor. Responde con los datos reales de tu negocio, con tablas y gráficos."
        :puntos="['Usa tus datos reales', 'Respuestas con gráficos', 'Tú confirmas cada acción']">
        <x-slot:visual>
            <div class="visual-card">
                <div class="visual-title"><span style="display:inline-flex;align-items:center;gap:8px"><x-sitio.i n="sparkles" style="color:var(--info)" />Mozi</span><small>Agente de IA</small></div>
                <div class="chat">
                    <span class="bubble me">¿Qué se está por acabar en Sucursal Norte?</span>
                    <span class="bubble bot" style="display:block;font-weight:600">Hay 5 productos que se agotarían esta semana al ritmo de venta actual. Aceite 1 L es el más urgente: quedan 3 y vendes unos 4 por día.</span>
                </div>
                <div class="demo">
                    <div class="demo-row"><span><b>Aceite 1 L</b><small>Se agota en 1 día</small></span><em class="badge warn">Urgente</em></div>
                    <div class="demo-row"><span><b>Arroz 5 kg</b><small>Se agota en 3 días</small></span><em class="badge info">Reponer</em></div>
                </div>
            </div>
        </x-slot:visual>
    </x-sitio.hero>

    <section class="section" aria-labelledby="ia-casos">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Qué puede hacer</p>
                <h2 id="ia-casos">Un analista para tu negocio, disponible todo el día</h2>
                <p class="lead">Mozi entiende tus preguntas en español, consulta la información de tu empresa con los mismos permisos que tu usuario y te explica la respuesta.</p>
            </header>
            <x-sitio.funciones :items="[
                ['box', 'Predicción de agotamiento', 'Calcula en cuántos días se acabaría cada producto en cada sucursal según su ritmo de venta.'],
                ['truck', 'Compras sugeridas', 'Te propone qué reponer y en qué cantidad, y prepara la compra para que tú la revises y confirmes.'],
                ['tag', 'Productos estancados', 'Encuentra lo que no se vende y cuánto dinero tienes inmovilizado en esa mercadería.'],
                ['chart', 'Pronóstico de ventas', 'Estima cuánto venderás en las próximas semanas a partir de tu historial.'],
                ['heart', 'Rentabilidad', 'Te muestra qué productos y sucursales te dejan más margen, usando tus costos de compra.'],
                ['swap', 'Equilibrio entre sucursales', 'Detecta stock que sobra en una sucursal y falta en otra, y prepara el traspaso.'],
                ['wallet', 'Cobranza priorizada', 'Ordena a tus clientes con saldo por monto y antigüedad, para saber a quién cobrar primero.'],
                ['calendar', 'Pagos y flujo de caja', 'Te recuerda los pagos a proveedores que vencen y proyecta tu flujo de caja.'],
                ['search', 'Anomalías', 'Señala ventas, ajustes o arqueos fuera de lo normal para que los revises a tiempo.'],
            ]" />
        </div>
    </section>

    <section class="section section-alt" aria-labelledby="ia-seguro">
        <div class="wrap">
            <div class="split">
                <div class="split-copy reveal">
                    <p class="eyebrow">Confiable</p>
                    <h2 id="ia-seguro">La IA interpreta, MoziStock calcula</h2>
                    <p>Mozi no adivina importes ni existencias: cada número sale de las consultas que hace el sistema sobre los datos de tu empresa, con los permisos de tu usuario. La inteligencia artificial interpreta tu pregunta y te explica el resultado.</p>
                    <ul class="checks">
                        <li><x-sitio.i n="check" />Solo ve la información de tu empresa y de lo que tu usuario puede ver.</li>
                        <li><x-sitio.i n="check" />Nunca ejecuta una operación sin tu confirmación explícita.</li>
                        <li><x-sitio.i n="check" />No emite, firma ni anula facturas del SIAT por su cuenta.</li>
                        <li><x-sitio.i n="check" />Respuestas en el chat y gráficos interactivos en el lienzo, también en el celular.</li>
                    </ul>
                </div>
                <div class="visual-card reveal" aria-hidden="true">
                    <div class="visual-title">Cobranza sugerida<small>Hoy</small></div>
                    <div class="demo">
                        <div class="demo-row"><span><b>Comercial Illimani</b><small>Debe hace 42 días</small></span><strong>Bs 1.850,00</strong></div>
                        <div class="demo-row"><span><b>Juan Mamani</b><small>Debe hace 9 días</small></span><strong>Bs 120,00</strong></div>
                        <div class="demo-foot"><span>Total por cobrar</span><b class="accent">Bs 1.970,00</b></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="ia-faq">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Preguntas</p>
                <h2 id="ia-faq">Preguntas sobre el agente de IA</h2>
            </header>
            <x-sitio.faq :preguntas="[
                ['¿Qué es Mozi?', 'Mozi es el agente de IA de MoziStock: un asistente inteligente que analiza tu negocio y responde preguntas sobre tu inventario, ventas, compras, clientes, proveedores, sucursales y cobros usando los datos reales de tu empresa.'],
                ['¿Mozi puede ver los datos de otras empresas?', 'No. Mozi consulta solo la información de tu empresa y respeta los permisos de tu usuario: un vendedor no ve lo mismo que un administrador.'],
                ['¿Mozi puede hacer cambios en mi sistema?', 'Puede preparar acciones como una compra o un traspaso, pero nada se ejecuta sin tu confirmación explícita. Tampoco emite ni anula facturas del SIAT.'],
                ['¿Tengo que saber de tecnología para usarlo?', 'No. Le escribes tu pregunta en español, como si hablaras con una persona, y te responde con texto, tablas y gráficos.'],
                ['¿En qué plan está Mozi?', 'Mozi viene en el plan SIAT + Agente IA, con un límite de consultas diarias por empresa. Puedes probarlo gratis '.config('mozistock.prueba_gratis_dias').' días eligiendo ese plan al registrarte.'],
            ]" />
        </div>
    </section>

    <x-sitio.relacionados actual="agente-ia" :claves="['reportes', 'inventario', 'creditos']" />
    <x-sitio.cta titulo="Pregúntale a Mozi por tu negocio" :mensaje="$seo->mensajeWhatsapp" />
</x-sitio.layout>
