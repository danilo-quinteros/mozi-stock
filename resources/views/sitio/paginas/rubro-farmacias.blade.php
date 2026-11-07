<x-sitio.rubro :pagina="$pagina" :seo="$seo" :esquema="$esquema"
    etiqueta="Rubro · Farmacias"
    titulo="Sistema para farmacias en <span class='hl'>Bolivia</span>"
    lead="Atiende rápido con el código de barras, sabe qué medicamentos y productos se están acabando en cada sucursal, cuadra la caja de cada turno y emite facturas en línea con el SIAT."
    :puntos="['Venta con código de barras', 'Stock mínimo por sucursal', 'Caja por turno']"
    :dolores="[
        ['Se me acaban productos sin darme cuenta', 'Define el stock mínimo de cada producto y MoziStock marca lo que está por agotarse en cada sucursal. Mozi te dice qué reponer y cuánto.'],
        ['La fila avanza lento en horas pico', 'Escanea el código de barras y el producto entra a la venta con su precio. Cobra en efectivo, QR o mixto en segundos.'],
        ['No cuadra la caja entre turnos', 'Cada cajero abre y cierra su turno. El arqueo compara el efectivo contado con el esperado y registra cualquier diferencia.'],
        ['Tengo varias farmacias y no veo el total', 'Mira el stock y las ventas de cada farmacia o de todas juntas, y traspasa productos de una a otra cuando falten.'],
    ]"
    :funciones="[
        ['barcode', 'Código de barras', 'Busca y vende escaneando el código del producto o por nombre.'],
        ['bell', 'Alertas de stock bajo', 'Revisa en un solo lugar los productos bajo su stock mínimo en cada sucursal.'],
        ['clock', 'Turnos de caja', 'Abre y cierra caja por cajero y turno, con arqueo al cierre.'],
        ['swap', 'Traspasos', 'Mueve productos entre tus farmacias en una sola operación, con historial.'],
        ['truck', 'Compras a laboratorios', 'Registra compras a laboratorios y distribuidoras y lo que les debes.'],
        ['receipt', 'Factura SIAT', 'Emite la factura en línea con el NIT o carnet del cliente desde la venta.'],
    ]"
    :modulos="['punto-de-venta', 'inventario', 'caja', 'compras', 'multisucursal', 'facturacion']"
    :preguntas="[
        ['¿MoziStock sirve para una farmacia pequeña?', 'Sí. Empiezas con una sucursal y una caja, y agregas más sucursales o cajas cuando las necesites.'],
        ['¿Puedo vender con lector de código de barras?', 'Sí. Cualquier lector que funcione como teclado sirve para buscar y agregar productos a la venta.'],
        ['¿Puedo facturar cada venta con el SIAT?', 'Sí. Con el plan Inventario + SIAT emites la factura en línea desde cada venta confirmada.'],
        ['¿Controla lotes y fechas de vencimiento?', 'Hoy MoziStock controla el stock por producto y sucursal, con stock mínimo y alertas. El control por lote y vencimiento no está incluido; si lo necesitas, escríbenos y lo conversamos.'],
    ]"
    cierre="Ordena tu farmacia desde hoy">
    <x-slot:visual>
        <div class="visual-card">
            <div class="visual-title">Stock bajo<small>Farmacia Central</small></div>
            <div class="demo">
                <div class="demo-row"><span><b>Paracetamol 500 mg × 10</b><small>Mínimo 20</small></span><strong>8 u. <em class="badge warn">Bajo</em></strong></div>
                <div class="demo-row"><span><b>Alcohol en gel 250 ml</b><small>Mínimo 15</small></span><strong>5 u. <em class="badge warn">Bajo</em></strong></div>
                <div class="demo-row"><span><b>Suero oral 500 ml</b><small>Mínimo 12</small></span><strong>30 u.</strong></div>
            </div>
            <div class="seg"><span class="on">Efectivo</span><span>QR</span><span>Mixto</span></div>
        </div>
    </x-slot:visual>
</x-sitio.rubro>
