{{-- Cierre de página: prueba gratis + WhatsApp. --}}
@props(['titulo' => 'Ordena tu negocio desde hoy', 'texto' => null, 'mensaje' => 'Hola, quiero una demostración de MoziStock.'])
@php($dias = config('mozistock.prueba_gratis_dias'))
<section class="section section-cta" aria-labelledby="cta-titulo">
    <div class="wrap">
        <div class="cta-card reveal">
            <p class="eyebrow">Empieza hoy</p>
            <h2 id="cta-titulo">{{ $titulo }}</h2>
            <p class="lead" style="max-width:640px;margin-inline:auto">{{ $texto ?? "Prueba MoziStock gratis durante {$dias} días con todos los módulos. Si tienes dudas, te las resolvemos por WhatsApp." }}</p>
            <ol class="steps">
                <li><b>Escríbenos</b><span>Cuéntanos de tu negocio y te mostramos MoziStock funcionando.</span></li>
                <li><b>Activa tu prueba</b><span>{{ $dias }} días gratis con tu empresa y tu primera sucursal listas.</span></li>
                <li><b>Sigue la guía</b><span>El panel te lleva paso a paso: productos, inventario inicial y tu primera venta.</span></li>
            </ol>
            <div class="hero-cta" style="justify-content:center;margin-top:0">
                <x-sitio.prueba class="btn btn-primary btn-lg"><x-sitio.i n="gift" />Probar gratis {{ $dias }} días</x-sitio.prueba>
                <x-sitio.wa class="btn btn-ghost btn-lg" :mensaje="$mensaje"><x-sitio.i n="wa" />Escribir por WhatsApp</x-sitio.wa>
            </div>
            <p class="cta-fine">Sin tarjeta y sin compromiso. Te respondemos por WhatsApp.</p>
        </div>
    </div>
</section>
