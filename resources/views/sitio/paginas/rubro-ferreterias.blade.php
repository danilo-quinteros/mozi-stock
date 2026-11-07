<x-sitio.rubro :pagina="$pagina" :seo="$seo" :esquema="$esquema"
    etiqueta="Rubro · Ferreterías"
    titulo="Sistema para ferreterías en <span class='hl'>Bolivia</span>"
    lead="Miles de productos, ventas por unidad, metro o kilo, precios para maestros y constructoras, y créditos que no se pueden perder de vista. MoziStock ordena tu ferretería y factura con el SIAT."
    :puntos="['Precios minorista y mayorista', 'Ventas por medida', 'Créditos a clientes frecuentes']"
    :dolores="[
        ['No sé cuánto tengo de cada cosa', 'Con el stock por sucursal y el stock mínimo, ves al instante qué tienes, qué falta y qué está por acabarse, aunque manejes miles de productos.'],
        ['Cada cliente paga un precio distinto', 'Cada producto tiene precio consumidor, minorista y mayorista. El vendedor elige el que corresponde al maestro, a la constructora o al cliente de paso.'],
        ['Fío a maestros y pierdo la cuenta', 'Cada venta a crédito tiene su historial de pagos. Ves el saldo de cada cliente y registras sus abonos el día que pagan.'],
        ['Vendo por metro, kilo o unidad', 'Cada producto tiene su unidad de medida. Vende cable por metro, clavos por kilo y herramientas por unidad, con decimales cuando corresponde.'],
    ]"
    :funciones="[
        ['barcode', 'Venta rápida en mostrador', 'Busca por código, código de barras o nombre, aunque tengas miles de productos.'],
        ['doc', 'Cotizaciones para obras', 'Prepara cotizaciones para constructoras. No descuentan stock hasta que se confirman como venta.'],
        ['box', 'Importa tu catálogo', 'Carga tus productos desde una planilla y revisa cada fila antes de confirmar.'],
        ['truck', 'Compras a distribuidores', 'Registra compras, costos y lo que debes a cada proveedor con su vencimiento.'],
        ['swap', 'Almacén y tienda', 'Traspasa material entre tu almacén y tus tiendas con historial completo.'],
        ['receipt', 'Factura SIAT', 'Emite la factura en línea desde la venta cuando el cliente la pide.'],
    ]"
    :modulos="['inventario', 'punto-de-venta', 'creditos', 'compras', 'multisucursal', 'facturacion']"
    :preguntas="[
        ['¿MoziStock aguanta el catálogo grande de una ferretería?', 'Sí. Está pensado para catálogos con miles de productos: buscas por código, código de barras o nombre, y puedes importar tu catálogo desde una planilla.'],
        ['¿Puedo vender cable por metro y clavos por kilo?', 'Sí. Cada producto tiene su unidad de medida y su tipo de venta, por unidad o por medida, con decimales cuando la unidad lo permite.'],
        ['¿Puedo dar precio mayorista a los maestros?', 'Sí. Cada producto puede tener precio consumidor, minorista y mayorista, y el vendedor elige cuál aplicar en cada venta.'],
        ['¿Puedo facturar a constructoras con NIT?', 'Sí. Con el plan Inventario + SIAT emites facturas en línea con el NIT de tu cliente desde la venta confirmada.'],
    ]"
    cierre="Ordena tu ferretería desde hoy">
    <x-slot:visual>
        <div class="visual-card">
            <div class="visual-title">Venta · Constructora Los Andes<small>Precio mayorista</small></div>
            <div class="demo">
                <div class="demo-row"><span><b>Cemento 50 kg × 20</b><small>Mayorista</small></span><strong>Bs 1.240,00</strong></div>
                <div class="demo-row"><span><b>Cable 2,5 mm × 50 m</b><small>Por metro</small></span><strong>Bs 225,00</strong></div>
                <div class="demo-row"><span><b>Clavos 2" × 3,5 kg</b><small>Por kilo</small></span><strong>Bs 63,00</strong></div>
                <div class="demo-foot"><span>A crédito · inicial Bs 500</span><b class="accent">Bs 1.528,00</b></div>
            </div>
        </div>
    </x-slot:visual>
</x-sitio.rubro>
