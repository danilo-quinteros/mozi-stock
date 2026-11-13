<x-sitio.layout :pagina="$pagina" :seo="$seo" :esquema="$esquema">
    <x-sitio.hero :pagina="$pagina"
        etiqueta="Control de inventario"
        titulo="Sistema de control de inventario en <span class='hl'>Bolivia</span>"
        lead="Sabe cuánto tienes de cada producto en cada sucursal, recibe avisos de lo que se está acabando y registra cada entrada, salida, ajuste y traspaso con su motivo. Tu stock deja de ser una suposición."
        :puntos="['Stock por sucursal', 'Alertas de stock bajo', 'Historial de cada movimiento']">
        <x-slot:visual>
            <div class="visual-card">
                <div class="visual-title">Inventario<small>Todas las sucursales</small></div>
                <div class="demo">
                    <div class="demo-row"><span><b>Arroz 5 kg</b><small>Casa Matriz</small></span><strong>48 u.</strong></div>
                    <div class="demo-row"><span><b>Arroz 5 kg</b><small>Sucursal Norte</small></span><strong>6 u. <em class="badge warn">Bajo</em></strong></div>
                    <div class="demo-row"><span><b>Aceite 1 L</b><small>Sucursal Norte</small></span><strong>3 u. <em class="badge warn">Bajo</em></strong></div>
                    <div class="demo-row"><span><b>Azúcar 1 kg</b><small>Casa Matriz</small></span><strong>120 u.</strong></div>
                </div>
            </div>
        </x-slot:visual>
    </x-sitio.hero>

    <section class="section" aria-labelledby="i-funciones">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Qué incluye</p>
                <h2 id="i-funciones">Tu inventario claro, al día y con historia</h2>
                <p class="lead">Cada unidad que entra o sale tiene un motivo, una sucursal, un usuario y una fecha.</p>
            </header>
            <x-sitio.funciones :items="[
                ['box', 'Catálogo de productos', 'Productos con código, código de barras, categoría, unidad de medida, foto y tres listas de precios. Importa tu catálogo desde una planilla.'],
                ['store', 'Stock por sucursal', 'Cada sucursal tiene su propio stock. Ves el total de la empresa o el detalle de cada tienda o almacén.'],
                ['bell', 'Stock mínimo', 'Define el mínimo de cada producto y MoziStock te avisa cuando una sucursal está por quedarse sin existencias.'],
                ['plus', 'Entradas, salidas y ajustes', 'Registra mercadería que entra o sale por motivos distintos a la venta, con motivo y nota obligatoria.'],
                ['swap', 'Traspasos entre sucursales', 'Mueve productos de una sucursal a otra en una sola operación: sale de una y entra en la otra, sin quedar a medias.'],
                ['search', 'Historial completo', 'Consulta todos los movimientos por producto, sucursal, tipo o fecha y descárgalos en CSV.'],
            ]" />
        </div>
    </section>

    <section class="section section-alt" aria-labelledby="i-ventas">
        <div class="wrap">
            <div class="split">
                <div class="split-copy reveal">
                    <p class="eyebrow">Conectado</p>
                    <h2 id="i-ventas">El stock se mueve solo con tus ventas y compras</h2>
                    <p>No necesitas actualizar el inventario a mano. Una venta confirmada descuenta lo vendido de la sucursal; una compra suma lo que llegó; una devolución lo regresa.</p>
                    <ul class="checks">
                        <li><x-sitio.i n="check" />El sistema no deja vender más de lo que hay en la sucursal.</li>
                        <li><x-sitio.i n="check" />Productos por unidad o por medida (kilo, litro, metro) según su tipo de venta.</li>
                        <li><x-sitio.i n="check" />Captura rápida con buscador o escáner para cargar muchos productos a la vez.</li>
                    </ul>
                </div>
                <div class="visual-card reveal" aria-hidden="true">
                    <div class="visual-title">Historial · Aceite 1 L<small>Sucursal Norte</small></div>
                    <div class="demo">
                        <div class="demo-row"><span><b>Compra C-0087</b><small>Hoy 09:10</small></span><strong style="color:var(--ok)">+24</strong></div>
                        <div class="demo-row"><span><b>Venta V-01248</b><small>Hoy 11:32</small></span><strong>−2</strong></div>
                        <div class="demo-row"><span><b>Traspaso a Casa Matriz</b><small>Hoy 15:05</small></span><strong>−6</strong></div>
                        <div class="demo-row"><span><b>Ajuste · producto dañado</b><small>Hoy 18:40</small></span><strong>−1</strong></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="i-faq">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Preguntas</p>
                <h2 id="i-faq">Preguntas sobre el control de inventario</h2>
            </header>
            <x-sitio.faq :preguntas="[
                ['¿Puedo cargar mis productos desde Excel?', 'Sí. Puedes importar tu catálogo de productos desde una planilla y revisar cada fila antes de confirmar la importación.'],
                ['¿Cómo sé qué productos se están acabando?', 'Defines un stock mínimo por producto y MoziStock marca como bajo el stock de cada sucursal que llega a ese mínimo.'],
                ['¿Puedo mover mercadería entre sucursales?', 'Sí, con un traspaso. El producto sale de una sucursal y entra en la otra en una sola operación, con su historial.'],
                ['¿Qué pasa si se daña o se pierde un producto?', 'Registras un ajuste de salida con su motivo y una nota. El stock se corrige y queda constancia de quién lo hizo y cuándo.'],
                ['¿Sirve para productos que se venden por kilo o por metro?', 'Sí. Cada producto tiene su unidad de medida y su tipo de venta: por unidad o por medida, con decimales cuando corresponde.'],
            ]" />
        </div>
    </section>

    <x-sitio.relacionados actual="inventario" :claves="['multisucursal', 'compras', 'punto-de-venta', 'reportes', 'agente-ia', 'facturacion']" />
    <x-sitio.cta titulo="Ten tu inventario bajo control" :mensaje="$seo->mensajeWhatsapp" />
</x-sitio.layout>
