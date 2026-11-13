<x-sitio.rubro :pagina="$pagina" :seo="$seo" :esquema="$esquema"
    etiqueta="Rubro · Ropa y calzado"
    titulo="Sistema para tiendas de ropa y calzado en <span class='hl'>Bolivia</span>"
    lead="Controla cada modelo, talla y color, vende con código de barras, registra cambios y devoluciones, vende a crédito a tus clientas frecuentes y mueve mercadería entre tus tiendas."
    :puntos="['Cada talla y color con su stock', 'Devoluciones ordenadas', 'Varias tiendas']"
    :dolores="[
        ['No sé qué tallas me quedan', 'Registra cada modelo, talla y color como producto con su código. Ves el stock exacto de cada uno en cada tienda.'],
        ['Los cambios y devoluciones me descuadran', 'Registra la devolución sobre la venta original, por producto y motivo. El stock vuelve a la tienda y la caja queda clara.'],
        ['Vendo a crédito y se me olvida cobrar', 'Cada venta a crédito tiene su historial de pagos y saldo. Mozi te dice a quién cobrar primero.'],
        ['Tengo la talla en otra tienda', 'Consulta el stock de todas tus tiendas y haz un traspaso para llevar la prenda donde la piden.'],
    ]"
    :funciones="[
        ['barcode', 'Etiquetas con código', 'Usa el código de barras de cada prenda para vender rápido y sin errores.'],
        ['return', 'Devoluciones', 'Devoluciones por producto y motivo, con el stock de vuelta en la tienda.'],
        ['wallet', 'Créditos', 'Ventas a crédito con cuota inicial y abonos registrados el día que se cobran.'],
        ['swap', 'Traspasos entre tiendas', 'Lleva la talla que falta de una tienda a otra con historial.'],
        ['tag', 'Productos estancados', 'Mozi te muestra qué modelos no se venden y cuánto capital tienes parado.'],
        ['chart', 'Más vendidos', 'Reportes por producto para saber qué reponer en la próxima temporada.'],
    ]"
    :modulos="['punto-de-venta', 'inventario', 'creditos', 'multisucursal', 'agente-ia', 'reportes']"
    :preguntas="[
        ['¿Cómo manejo tallas y colores?', 'Registra cada combinación de modelo, talla y color como un producto con su propio código y código de barras. Así ves el stock exacto de cada una.'],
        ['¿Puedo registrar cambios de prenda?', 'Sí. Registras la devolución de la prenda sobre la venta original y haces una nueva venta con la prenda de cambio.'],
        ['¿Sirve si tengo tiendas en distintos lugares?', 'Sí. Cada tienda tiene su stock y su caja, y puedes traspasar prendas de una a otra.'],
        ['¿Puedo saber qué modelos no se venden?', 'Sí. Los reportes y el agente Mozi te muestran los productos sin movimiento y el dinero inmovilizado en ellos.'],
    ]"
    cierre="Ordena tu tienda de ropa y calzado">
    <x-slot:visual>
        <div class="visual-card">
            <div class="visual-title">Zapatilla urbana · Negro<small>Todas las tiendas</small></div>
            <div class="demo">
                <div class="demo-row"><span><b>Talla 38</b><small>Tienda Centro</small></span><strong>4 u.</strong></div>
                <div class="demo-row"><span><b>Talla 39</b><small>Tienda Centro</small></span><strong>1 u. <em class="badge warn">Bajo</em></strong></div>
                <div class="demo-row"><span><b>Talla 39</b><small>Tienda Norte</small></span><strong>6 u.</strong></div>
                <div class="demo-foot"><span>Traspaso sugerido</span><em class="badge info">Norte → Centro</em></div>
            </div>
        </div>
    </x-slot:visual>
</x-sitio.rubro>
