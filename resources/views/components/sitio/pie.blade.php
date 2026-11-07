@php
    $paginas = \App\Domain\SitioWeb\CatalogoPaginas::todas();
@endphp
<footer class="site-footer">
    <div class="wrap">
        <div class="footer-in">
            <div class="footer-brand">
                <a class="brand" href="{{ route('home') }}" aria-label="MoziStock, ir al inicio">
                    <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
                    <span class="brand-text"><b>MoziStock</b><small>by MoziStudios</small></span>
                </a>
                <p>Sistema de inventario, ventas, cobros y facturación SIAT para negocios en Bolivia. Lo usas desde el celular o la computadora.</p>
                <x-sitio.wa class="btn btn-primary btn-sm" mensaje="Hola, quiero información sobre MoziStock." style="justify-self:start"><x-sitio.i n="wa" />Escríbenos</x-sitio.wa>
            </div>
            <div class="footer-col">
                <h2>Funcionalidades</h2>
                <ul>
                    @foreach (array_keys(\App\Domain\SitioWeb\MenuSitio::modulos()) as $clave)
                        <li><a href="{{ route('sitio.'.$clave) }}">{{ $paginas[$clave]->nombre }}</a></li>
                    @endforeach
                </ul>
            </div>
            <div class="footer-col">
                <h2>Rubros</h2>
                <ul>
                    @foreach (array_keys(\App\Domain\SitioWeb\MenuSitio::rubros()) as $clave)
                        <li><a href="{{ route('sitio.'.$clave) }}">{{ $paginas[$clave]->nombre }}</a></li>
                    @endforeach
                </ul>
            </div>
            <div class="footer-col">
                <h2>MoziStock</h2>
                <ul>
                    <li><a href="{{ route('sitio.precios') }}">Precios</a></li>
                    <li><a href="{{ route('sitio.funcionalidades') }}">Todas las funcionalidades</a></li>
                    <li><a href="{{ route('sitio.preguntas-frecuentes') }}">Preguntas frecuentes</a></li>
                    <li><a href="{{ route('sitio.nosotros') }}">Nosotros</a></li>
                    <li><a href="{{ route('sitio.contacto') }}">Contacto</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h2>Tu cuenta</h2>
                <ul>
                    <li><a href="{{ route('login') }}" rel="nofollow">Iniciar sesión</a></li>
                    <li><x-sitio.prueba>Prueba gratis {{ config('mozistock.prueba_gratis_dias') }} días</x-sitio.prueba></li>
                    @if (filled($sitioAjustes->correo_contacto))
                        <li><a href="mailto:{{ $sitioAjustes->correo_contacto }}">{{ $sitioAjustes->correo_contacto }}</a></li>
                    @endif
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <p>© {{ now()->year }} MoziStudios · Hecho en Bolivia para negocios de Bolivia.</p>
            <nav aria-label="Legal">
                <a href="{{ route('sitio.terminos') }}">Términos y condiciones</a>
                <a href="{{ route('sitio.privacidad') }}">Política de privacidad</a>
            </nav>
        </div>
    </div>
</footer>
