<x-sitio.layout :pagina="$pagina" :seo="$seo" :esquema="$esquema">
    <x-sitio.hero :pagina="$pagina"
        etiqueta="Quiénes somos"
        titulo="Un sistema de gestión hecho en <span class='hl'>Bolivia</span>, para negocios de Bolivia"
        lead="MoziStock es un producto de MoziStudios. Lo creamos para que el dueño de un comercio sepa, sin complicaciones, qué tiene, qué vendió y cuánto cobró."
        :ctas="false" />

    <section class="section" style="padding-top:0">
        <div class="wrap">
            <div class="text-block reveal">
                <h2>Por qué existe MoziStock</h2>
                <p>Muchos negocios en Bolivia todavía controlan su inventario en planillas, anotan los fiados en un cuaderno y cierran la caja contando billetes sin saber cuánto debería haber. Los sistemas que existen suelen ser difíciles de usar, se instalan en una sola computadora o no están pensados para cómo se trabaja aquí.</p>
                <p>MoziStock nació para resolver eso con un sistema web, en español, con bolivianos, cobros por QR, ventas a crédito, varias sucursales y <strong>facturación en línea con el SIAT</strong>. Sumamos a <strong>Mozi</strong>, un agente de inteligencia artificial que responde con los datos reales de tu negocio.</p>
                <h2>Cómo trabajamos</h2>
                <p><strong>Simplicidad primero.</strong> Pocos menús, formularios claros y una guía para empezar. Si algo no se entiende a la primera, lo mejoramos.</p>
                <p><strong>Números en los que puedes confiar.</strong> Una venta, un cobro o un movimiento de stock se registran una sola vez, aunque haya un doble clic. Lo vendido y lo cobrado siempre van por separado.</p>
                <p><strong>Tus datos, solo tuyos.</strong> Cada empresa trabaja aislada de las demás en cada pantalla, descarga y archivo.</p>
                <p><strong>Cerca de ti.</strong> Te atendemos por WhatsApp, en español y con personas que conocen el sistema.</p>
            </div>
        </div>
    </section>

    <section class="section section-alt">
        <div class="wrap">
            <x-sitio.funciones :items="[
                ['heart', 'Hecho en Bolivia', 'Diseñado para comercios bolivianos: bolivianos, QR, créditos, sucursales y SIAT.'],
                ['shield', 'Seguridad', 'Datos aislados por empresa, permisos por rol y auditoría de operaciones de dinero y stock.'],
                ['wa', 'Atención por WhatsApp', 'Respuestas directas de nuestro equipo para tus dudas y tu configuración.'],
            ]" />
        </div>
    </section>

    <x-sitio.cta titulo="Conversemos sobre tu negocio" :mensaje="$seo->mensajeWhatsapp" />
</x-sitio.layout>
