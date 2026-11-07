<x-sitio.layout :pagina="$pagina" :seo="$seo" :esquema="$esquema">
    @php
        $dias = config('mozistock.prueba_gratis_dias');
        $grupos = [
            'Sobre MoziStock' => [
                ['¿Qué es MoziStock?', 'Es un sistema web de inventario, ventas, cobros, caja, reportes y facturación SIAT para negocios de Bolivia, con un asistente de inteligencia artificial llamado Mozi.'],
                ['¿Tengo que instalar algo?', 'No. Entras desde el navegador de tu celular, tablet o computadora con conexión a internet.'],
                ['¿Para qué negocios sirve?', 'Para negocios que venden productos: ferreterías, farmacias, tiendas de ropa y calzado, minimarkets, distribuidoras, librerías, repuestos y más.'],
                ['¿Sirve si tengo varias sucursales?', 'Sí. Cada sucursal tiene su stock y su caja, puedes traspasar productos entre ellas y cada vendedor trabaja solo en su sucursal.'],
                ['¿Es difícil de usar?', 'No. Tiene pocos menús, formularios simples y una guía que te lleva paso a paso: productos, inventario inicial y primera venta.'],
            ],
            'Prueba gratis y precios' => [
                ['¿Cómo funciona la prueba gratis?', 'Tienes '.$dias.' días para usar MoziStock con todos los módulos y tus propios datos. No pedimos tarjeta.'],
                ['¿Qué pasa cuando termina la prueba?', 'Eliges tu plan, pagas con QR y sigues trabajando con toda la información que cargaste.'],
                ['¿Cómo pago?', 'Con QR desde la sección Suscripción del sistema. Subes el comprobante y lo revisamos.'],
                ['¿Puedo cambiar de plan?', 'Sí, cuando quieras. Si subes al plan con SIAT pagas solo la diferencia por los días que faltan.'],
            ],
            'Facturación SIAT' => [
                ['¿MoziStock emite facturas válidas ante el SIN?', 'Sí. Con el plan Inventario + SIAT emites facturas en línea con el SIAT del Servicio de Impuestos Nacionales desde tus ventas confirmadas.'],
                ['¿Qué modalidades soporta?', 'Facturación computarizada en línea y facturación electrónica en línea con firma digital.'],
                ['¿Puedo usar MoziStock sin facturar?', 'Sí. El plan Inventario incluye todos los módulos excepto la facturación SIAT.'],
            ],
            'Seguridad y datos' => [
                ['¿Otra empresa puede ver mis datos?', 'No. Cada empresa trabaja solo con su información: productos, clientes, ventas, archivos y usuarios. Ni siquiera cambiando direcciones o identificadores se puede ver la de otra.'],
                ['¿Quién puede ver la información dentro de mi empresa?', 'Depende del rol: el administrador ve todo; el vendedor trabaja solo en su sucursal y su caja.'],
                ['¿Puedo descargar mi información?', 'Sí. Productos, movimientos, ventas, clientes y reportes se descargan en CSV.'],
                ['¿El asistente de IA ve mis datos?', 'Mozi consulta solo la información de tu empresa, con los permisos de tu usuario, y no ejecuta ninguna acción sin tu confirmación.'],
            ],
            'Soporte' => [
                ['¿Cómo pido ayuda?', 'Escríbenos por WhatsApp y te ayudamos con la configuración, tus datos fiscales o cualquier duda del día a día.'],
                ['¿Me ayudan a cargar mis productos?', 'Sí. Puedes importar tu catálogo desde una planilla y te orientamos para hacerlo sin errores.'],
            ],
        ];
        $todas = array_merge(...array_values($grupos));
    @endphp
    @push('esquemas')
        <script type="application/ld+json">{!! \App\Domain\SitioWeb\EsquemaJsonLd::json(\App\Domain\SitioWeb\EsquemaJsonLd::preguntas($todas)) !!}</script>
    @endpush

    <x-sitio.hero :pagina="$pagina"
        etiqueta="Preguntas frecuentes"
        titulo="Preguntas frecuentes sobre MoziStock en <span class='hl'>Bolivia</span>"
        lead="Todo lo que necesitas saber sobre la prueba gratis, los planes, la facturación SIAT, la seguridad de tus datos y el soporte. Si no encuentras tu respuesta, escríbenos por WhatsApp." />

    <section class="section" style="padding-top:0">
        <div class="wrap">
            @foreach ($grupos as $titulo => $preguntas)
                <div class="faq-group">
                    <h2>{{ $titulo }}</h2>
                    <x-sitio.faq :preguntas="$preguntas" :esquema="false" />
                </div>
            @endforeach
        </div>
    </section>

    <x-sitio.cta titulo="¿Te quedó alguna duda?" texto="Escríbenos por WhatsApp y te respondemos, o pruébalo tú mismo gratis." :mensaje="$seo->mensajeWhatsapp" />
</x-sitio.layout>
