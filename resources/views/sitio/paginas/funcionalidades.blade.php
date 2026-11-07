<x-sitio.layout :pagina="$pagina" :seo="$seo" :esquema="$esquema">
    <x-sitio.hero :pagina="$pagina"
        etiqueta="Funcionalidades"
        titulo="Todo lo que hace MoziStock por tu negocio en <span class='hl'>Bolivia</span>"
        lead="Un sistema de gestión completo para comercios: vende, controla tu inventario por sucursal, cobra tus créditos, cuadra tu caja, factura con el SIAT y pregúntale a la inteligencia artificial cómo va tu negocio."
        :puntos="['Precio fijo mensual en Bs', 'Sin instalar nada', 'En el celular o la computadora']" />

    <section class="section" style="padding-top:0" aria-labelledby="fu-modulos">
        <div class="wrap">
            <h2 class="sr-only" id="fu-modulos">Módulos de MoziStock</h2>
            <div class="features">
                @foreach (\App\Domain\SitioWeb\MenuSitio::modulos() as $clave => $modulo)
                    @php($destino = \App\Domain\SitioWeb\CatalogoPaginas::buscar($clave))
                    <article class="feature reveal" @if ($loop->index % 3) style="--d:{{ ($loop->index % 3) * 80 }}ms" @endif>
                        <span class="module-icon"><x-sitio.i :n="$modulo['icono']" /></span>
                        <h3>{{ $destino->nombre }}</h3>
                        <p>{{ $destino->descripcion }}</p>
                        <a class="module-link" href="{{ route($destino->nombreRuta()) }}">Más sobre {{ $destino->nombre }}<x-sitio.i n="arrow" /></a>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section class="section section-alt" aria-labelledby="fu-base">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">En todos los planes</p>
                <h2 id="fu-base">La base que tu negocio necesita</h2>
            </header>
            <x-sitio.funciones :items="[
                ['users', 'Clientes y proveedores', 'Fichas con datos de contacto, historial de compras o ventas y saldos pendientes.'],
                ['lock', 'Usuarios y permisos', 'Administradores y vendedores con lo que cada uno necesita, y su sucursal asignada.'],
                ['shield', 'Datos separados por empresa', 'Tu información nunca se mezcla con la de otra empresa, ni en pantallas, descargas ni archivos.'],
                ['devices', 'Web y responsive', 'Funciona en el navegador de cualquier computadora, tablet o celular, con modo claro y oscuro.'],
                ['clock', 'Guía de inicio', 'Una guía te acompaña en tus primeros pasos: productos, inventario inicial y primera venta.'],
                ['qr', 'Pago de tu plan con QR', 'Pagas la mensualidad con QR desde el mismo sistema y subes tu comprobante.'],
            ]" />
        </div>
    </section>

    <x-sitio.cta titulo="Pruébalo todo, gratis" :mensaje="$seo->mensajeWhatsapp" />
</x-sitio.layout>
