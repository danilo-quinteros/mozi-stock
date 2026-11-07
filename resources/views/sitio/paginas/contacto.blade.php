<x-sitio.layout :pagina="$pagina" :seo="$seo" :esquema="$esquema">
    @php($dias = config('mozistock.prueba_gratis_dias'))
    <x-sitio.hero :pagina="$pagina"
        etiqueta="Contacto"
        titulo="Hablemos por <span class='hl'>WhatsApp</span>"
        lead="Escríbenos y te mostramos MoziStock funcionando con un negocio como el tuyo. Te ayudamos a elegir tu plan, activar tu prueba gratis y configurar la facturación SIAT."
        :ctas="false" />

    <section class="section" style="padding-top:0" aria-label="Formas de contacto">
        <div class="wrap">
            <div class="contact-grid">
                <article class="contact-card reveal">
                    <span class="module-icon"><x-sitio.i n="wa" /></span>
                    <h2>Demostración</h2>
                    <p>Te mostramos el sistema funcionando, con ejemplos de tu rubro, y resolvemos tus dudas.</p>
                    <x-sitio.wa class="btn btn-primary" mensaje="Hola, quiero una demostración de MoziStock."><x-sitio.i n="wa" />Pedir demostración</x-sitio.wa>
                </article>
                <article class="contact-card reveal" style="--d:80ms">
                    <span class="module-icon"><x-sitio.i n="gift" /></span>
                    <h2>Prueba gratis</h2>
                    <p>Activa tus {{ $dias }} días de prueba con todos los módulos. Sin tarjeta y sin compromiso.</p>
                    <x-sitio.prueba class="btn btn-primary"><x-sitio.i n="gift" />Activar prueba gratis</x-sitio.prueba>
                </article>
                <article class="contact-card reveal" style="--d:160ms">
                    <span class="module-icon"><x-sitio.i n="receipt" /></span>
                    <h2>Facturación SIAT</h2>
                    <p>Resolvemos tus dudas sobre la facturación en línea y la configuración de tus datos fiscales.</p>
                    <x-sitio.wa class="btn btn-ghost" mensaje="Hola, tengo una consulta sobre la facturación SIAT de MoziStock."><x-sitio.i n="wa" />Consultar</x-sitio.wa>
                </article>
            </div>

            @if (blank($sitioAjustes->sitio_whatsapp))
                <div class="callout" style="max-width:760px;margin:28px auto 0"><x-sitio.i n="clock" /><span>Muy pronto publicaremos aquí nuestro número de WhatsApp.@if (filled($sitioAjustes->correo_contacto)) Mientras tanto, escríbenos a <a href="mailto:{{ $sitioAjustes->correo_contacto }}"><b>{{ $sitioAjustes->correo_contacto }}</b></a>.@endif</span></div>
            @endif

            <div class="text-block" style="margin-top:56px">
                <h2>¿Ya tienes una cuenta?</h2>
                <p>Ingresa a tu panel con tu correo y contraseña. Si necesitas ayuda con tu cuenta, escríbenos por WhatsApp indicando el nombre de tu empresa.</p>
                <p><a class="btn btn-ghost" href="{{ route('login') }}" rel="nofollow"><x-sitio.i n="login" />Iniciar sesión</a></p>
                @if (filled($sitioAjustes->correo_contacto))
                    <p>También puedes escribirnos a <a href="mailto:{{ $sitioAjustes->correo_contacto }}" style="color:var(--accent)">{{ $sitioAjustes->correo_contacto }}</a>.</p>
                @endif
            </div>
        </div>
    </section>
</x-sitio.layout>
