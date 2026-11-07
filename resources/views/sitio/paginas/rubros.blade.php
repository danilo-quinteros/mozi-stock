<x-sitio.layout :pagina="$pagina" :seo="$seo" :esquema="$esquema">
    <x-sitio.hero :pagina="$pagina"
        etiqueta="Por rubro"
        titulo="Un sistema de ventas e inventario para tu rubro en <span class='hl'>Bolivia</span>"
        lead="Cada negocio vende distinto. Mira cómo MoziStock se adapta a ferreterías, farmacias, tiendas de ropa y calzado, minimarkets y distribuidoras, con los mismos módulos y la facturación SIAT incluida en el plan completo." />

    <section class="section" style="padding-top:0" aria-labelledby="ru-lista">
        <div class="wrap">
            <h2 class="sr-only" id="ru-lista">Rubros</h2>
            <div class="features">
                @foreach (\App\Domain\SitioWeb\MenuSitio::rubros() as $clave => $rubro)
                    @php($destino = \App\Domain\SitioWeb\CatalogoPaginas::buscar($clave))
                    <article class="feature reveal" @if ($loop->index % 3) style="--d:{{ ($loop->index % 3) * 80 }}ms" @endif>
                        <span class="module-icon"><x-sitio.i :n="$rubro['icono']" /></span>
                        <h3>Sistema para {{ mb_strtolower($destino->nombre) }}</h3>
                        <p>{{ $destino->descripcion }}</p>
                        <a class="module-link" href="{{ route($destino->nombreRuta()) }}">Ver {{ mb_strtolower($destino->nombre) }}<x-sitio.i n="arrow" /></a>
                    </article>
                @endforeach
                <article class="feature reveal" style="--d:160ms">
                    <span class="module-icon"><x-sitio.i n="store" /></span>
                    <h3>¿Tu rubro no está en la lista?</h3>
                    <p>Librerías, repuestos, bazares, tiendas de celulares, agropecuarias… Si vendes productos, MoziStock te sirve. Cuéntanos de tu negocio y te mostramos cómo.</p>
                    <x-sitio.wa class="module-link" :mensaje="$seo->mensajeWhatsapp">Consultar por WhatsApp<x-sitio.i n="arrow" /></x-sitio.wa>
                </article>
            </div>
        </div>
    </section>

    <x-sitio.cta titulo="Pruébalo con los productos de tu negocio" :mensaje="$seo->mensajeWhatsapp" />
</x-sitio.layout>
