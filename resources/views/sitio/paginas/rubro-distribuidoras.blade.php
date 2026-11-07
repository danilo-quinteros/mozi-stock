<x-sitio.rubro :pagina="$pagina" :seo="$seo" :esquema="$esquema"
    etiqueta="Rubro · Distribuidoras y mayoristas"
    titulo="Sistema para distribuidoras y mayoristas en <span class='hl'>Bolivia</span>"
    lead="Toma pedidos y cotizaciones, vende con precio mayorista, da crédito con plan de cuotas, cobra a cada cliente lo que te debe y controla el stock de cada almacén. Con facturación SIAT para tus clientes con NIT."
    :puntos="['Pedidos y cotizaciones', 'Crédito con plan de cuotas', 'Stock por almacén']"
    :dolores="[
        ['Los pedidos se pierden en WhatsApp', 'Registra cada pedido en el sistema. Mientras está pendiente no descuenta stock; al confirmarlo se convierte en venta.'],
        ['Mis clientes me deben de varias ventas', 'Ve el saldo total de cada cliente en todas sus ventas a crédito y cóbrale en un solo pago.'],
        ['No sé qué tengo en cada almacén', 'Cada almacén o sucursal tiene su stock. Traspasa mercadería entre ellos con historial completo.'],
        ['Los proveedores me suben los costos', 'Cada compra guarda el costo unitario. Mozi te avisa qué proveedores cambiaron sus precios.'],
    ]"
    :funciones="[
        ['doc', 'Pedidos y cotizaciones', 'Prepara pedidos e imprímelos; se venden solo al confirmar.'],
        ['tag', 'Precio mayorista', 'Tres listas de precios por producto para cada tipo de cliente.'],
        ['calendar', 'Plan de cuotas', 'Crédito con cuotas y fechas para tus clientes frecuentes.'],
        ['wallet', 'Cobranza', 'Saldo por cliente y cobranza priorizada con ayuda de Mozi.'],
        ['store', 'Varios almacenes', 'Stock por almacén y traspasos trazables.'],
        ['receipt', 'Factura SIAT', 'Factura en línea a tus clientes con NIT desde la venta.'],
    ]"
    :modulos="['punto-de-venta', 'creditos', 'multisucursal', 'compras', 'asistente-ia', 'facturacion']"
    :preguntas="[
        ['¿Puedo registrar pedidos antes de entregar?', 'Sí. El pedido queda pendiente sin descontar stock ni contar como cobro. Al confirmarlo se convierte en venta.'],
        ['¿Puedo dar crédito con cuotas?', 'Sí. Defines la cuota inicial y el plan de cuotas, y registras cada abono el día que lo recibes.'],
        ['¿Puedo cobrar varias ventas de un cliente en un solo pago?', 'Sí. Registras el cobro al cliente y se aplica a sus ventas con saldo pendiente.'],
        ['¿Puedo facturar a mis clientes con NIT?', 'Sí. Con el plan Inventario + SIAT emites facturas en línea desde las ventas confirmadas.'],
    ]"
    cierre="Ordena tu distribuidora desde hoy">
    <x-slot:visual>
        <div class="visual-card">
            <div class="visual-title">Pedido P-0318<small>Comercial Illimani</small></div>
            <div class="demo">
                <div class="demo-row"><span><b>Aceite 1 L × 120</b><small>Precio mayorista</small></span><strong>Bs 1.440,00</strong></div>
                <div class="demo-row"><span><b>Azúcar 50 kg × 10</b><small>Precio mayorista</small></span><strong>Bs 2.900,00</strong></div>
                <div class="demo-foot"><span>Crédito · 3 cuotas</span><b class="accent">Bs 4.340,00</b></div>
            </div>
        </div>
    </x-slot:visual>
</x-sitio.rubro>
