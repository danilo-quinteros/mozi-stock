<x-sitio.layout :pagina="$pagina" :seo="$seo" :esquema="$esquema">
    <x-sitio.hero :pagina="$pagina"
        etiqueta="Varias sucursales · un solo sistema"
        titulo="Sistema multisucursal para negocios en <span class='hl'>Bolivia</span>"
        lead="Administra todas tus tiendas y almacenes desde un solo lugar. Cada sucursal tiene su stock y su caja; tú ves todo el negocio y cada vendedor trabaja solo en la sucursal que le asignaste."
        :puntos="['Stock y caja por sucursal', 'Traspasos entre sucursales', 'Permisos por usuario']">
        <x-slot:visual>
            <div class="visual-card">
                <div class="visual-title">Sucursales<small>Hoy</small></div>
                <div class="demo">
                    <div class="hbar"><span>Matriz</span><i><s style="--w:100%"></s></i><b>Bs 4.820</b></div>
                    <div class="hbar"><span>Norte</span><i><s style="--w:64%"></s></i><b>Bs 3.090</b></div>
                    <div class="hbar"><span>Sur</span><i><s style="--w:41%"></s></i><b>Bs 1.975</b></div>
                </div>
                <div class="demo">
                    <div class="demo-row"><span class="who"><i class="av">AD</i><span><b>Administrador</b><small>Todas las sucursales</small></span></span></div>
                    <div class="demo-row"><span class="who"><i class="av alt">VE</i><span><b>Vendedor</b><small>Sucursal Norte · Caja 2</small></span></span><x-sitio.i n="lock" class="lock" /></div>
                </div>
            </div>
        </x-slot:visual>
    </x-sitio.hero>

    <section class="section" aria-labelledby="m-funciones">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Qué incluye</p>
                <h2 id="m-funciones">Crece a varias sucursales sin perder el control</h2>
                <p class="lead">Una sola cuenta para tu empresa, con la información de cada sucursal separada y ordenada.</p>
            </header>
            <x-sitio.funciones :items="[
                ['store', 'Tiendas y almacenes', 'Registra cada sucursal, tienda o almacén de tu empresa con sus propios datos, stock y cajas.'],
                ['box', 'Stock independiente', 'Cada sucursal vende lo que tiene. Ves el stock de una sucursal o el de toda la empresa.'],
                ['swap', 'Traspasos trazables', 'Envía mercadería de una sucursal a otra en una operación atómica: sale de una y entra en la otra, con historial.'],
                ['cash', 'Una o varias cajas', 'Cada sucursal tiene su caja principal y puedes agregar más cajas, cada una con sus turnos y arqueos.'],
                ['lock', 'Vendedor en su sucursal', 'El administrador asigna la sucursal de cada vendedor. El vendedor no puede cambiarla, ni siquiera manipulando el sistema.'],
                ['chart', 'Comparativa de sucursales', 'Compara ventas, cobros e inventario entre sucursales y descubre dónde vendes más y dónde se acumula stock.'],
            ]" />
        </div>
    </section>

    <section class="section section-alt" aria-labelledby="m-roles">
        <div class="wrap">
            <div class="split">
                <div class="split-copy reveal">
                    <p class="eyebrow">Usuarios y roles</p>
                    <h2 id="m-roles">Cada persona ve lo que le corresponde</h2>
                    <p>El administrador ve y gestiona todas las sucursales. El vendedor trabaja en su sucursal, con su caja, y solo ve lo necesario para vender y cobrar. Cada operación queda registrada con el usuario que la hizo.</p>
                    <ul class="checks">
                        <li><x-sitio.i n="check" />Alta y baja de usuarios desde el panel de tu empresa.</li>
                        <li><x-sitio.i n="check" />Sucursal asignada por el administrador, no por el vendedor.</li>
                        <li><x-sitio.i n="check" />Auditoría de operaciones de stock y dinero.</li>
                    </ul>
                </div>
                <div class="visual-card reveal" aria-hidden="true">
                    <div class="visual-title">Traspaso T-0019<small>Casa Matriz → Sucursal Norte</small></div>
                    <div class="demo">
                        <div class="demo-row"><span><b>Arroz 5 kg</b><small>Sale de Casa Matriz</small></span><strong>20 u.</strong></div>
                        <div class="demo-row"><span><b>Aceite 1 L</b><small>Sale de Casa Matriz</small></span><strong>12 u.</strong></div>
                        <div class="demo-foot"><span>Estado</span><em class="badge ok">Recibido en Sucursal Norte</em></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="m-faq">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Preguntas</p>
                <h2 id="m-faq">Preguntas sobre varias sucursales</h2>
            </header>
            <x-sitio.faq :preguntas="[
                ['¿Puedo manejar sucursales en distintas ciudades?', 'Sí. MoziStock es un sistema web: tus sucursales pueden estar en cualquier ciudad de Bolivia y todas trabajan sobre la misma información en tiempo real.'],
                ['¿Un vendedor puede ver otras sucursales?', 'No. El vendedor trabaja solo en la sucursal que le asigna el administrador y no puede cambiarla.'],
                ['¿Cómo paso productos de una sucursal a otra?', 'Con un traspaso: eliges origen, destino y productos. El stock sale de una y entra en la otra en una sola operación.'],
                ['¿Cada sucursal tiene su propia caja?', 'Sí. Cada sucursal tiene su caja principal y puedes agregar más cajas, cada una con sus turnos y su arqueo.'],
            ]" />
        </div>
    </section>

    <x-sitio.relacionados actual="multisucursal" :claves="['inventario', 'caja', 'reportes']" />
    <x-sitio.cta titulo="Todas tus sucursales en un solo sistema" :mensaje="$seo->mensajeWhatsapp" />
</x-sitio.layout>
