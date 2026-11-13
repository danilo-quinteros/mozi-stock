<x-sitio.rubro :pagina="$pagina" :seo="$seo" :esquema="$esquema"
    etiqueta="Rubro · Minimarkets y tiendas"
    titulo="Sistema para minimarkets y tiendas de barrio en <span class='hl'>Bolivia</span>"
    lead="Cobra rápido con el lector de código de barras, en efectivo o QR, cierra la caja cuadrada en cada turno, deja el cuaderno de fiados y sabe qué productos reponer antes de que se acaben."
    :puntos="['Cobro en segundos', 'Efectivo y QR separados', 'Fiados ordenados']"
    :dolores="[
        ['La caja nunca me cuadra', 'Cada turno abre con un monto y cierra con un arqueo. El efectivo y los pagos por QR se registran por separado.'],
        ['Anoto los fiados en un cuaderno', 'Registra cada venta a crédito y sus abonos. Sabes quién te debe, cuánto y desde cuándo.'],
        ['Me quedo sin productos que se venden mucho', 'El stock mínimo te avisa qué está por acabarse y Mozi te sugiere cuánto comprar.'],
        ['No sé cuánto gano realmente', 'Con los costos de tus compras y tus ventas, los reportes y Mozi te muestran qué productos te dejan más margen.'],
    ]"
    :funciones="[
        ['barcode', 'Lector de código de barras', 'Escanea y cobra; el stock se descuenta solo.'],
        ['qr', 'Efectivo, QR o mixto', 'Cobra como te paguen y registra cada importe por separado.'],
        ['cash', 'Arqueo por turno', 'Cierra la caja cada turno y detecta faltantes a tiempo.'],
        ['wallet', 'Fiados con historial', 'Cuota inicial, abonos y saldo de cada cliente.'],
        ['truck', 'Compras y proveedores', 'Registra lo que compras y lo que debes a cada proveedor.'],
        ['devices', 'Desde el celular', 'Revisa tus ventas y tu caja desde el celular, estés donde estés.'],
    ]"
    :modulos="['punto-de-venta', 'caja', 'creditos', 'inventario', 'compras', 'agente-ia']"
    :preguntas="[
        ['¿Necesito comprar una computadora?', 'No necesariamente. MoziStock funciona en el navegador de una computadora, una tablet o un celular con internet.'],
        ['¿Puedo cobrar con QR?', 'Sí. Registras el cobro como electrónico o mixto, y se guarda separado del efectivo de tu caja.'],
        ['¿Cómo controlo los fiados?', 'Con las ventas a crédito: registras la venta con o sin cuota inicial y cada abono el día que te pagan.'],
        ['¿Es difícil de aprender?', 'No. Tiene pocos menús y una guía de inicio que te lleva paso a paso hasta tu primera venta.'],
    ]"
    cierre="Ordena tu minimarket desde hoy">
    <x-slot:visual>
        <div class="visual-card">
            <div class="visual-title">Turno de la mañana<small>Caja 1</small></div>
            <div class="demo">
                <div class="demo-row"><span>Ventas al contado</span><strong>Bs 1.380,00</strong></div>
                <div class="demo-row"><span>Cobros por QR</span><strong>Bs 640,00</strong></div>
                <div class="demo-row"><span>Abonos de fiados</span><strong>Bs 90,00</strong></div>
                <div class="demo-foot"><span>Arqueo</span><em class="badge ok">Cuadrada</em></div>
            </div>
        </div>
    </x-slot:visual>
</x-sitio.rubro>
