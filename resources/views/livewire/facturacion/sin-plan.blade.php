<div>
    <div class="page-head">
        <div>
            <div class="eyebrow">{{ __('Mi empresa') }}</div>
            <h1>{{ __('Facturación') }}<span class="accent-text">.</span></h1>
            <p class="lead">{{ __('Datos fiscales, sucursales y puntos de venta del SIN.') }}</p>
        </div>
    </div>

    <x-empresa-nav />

    <x-plan-requerido icono="receipt"
        :titulo="__('La facturación SIAT no está incluida en tu plan')"
        :descripcion="__('Cambia a un plan con facturación SIAT para emitir facturas desde tus ventas, sin salir de MoziStock.')"
        :beneficios="[
            __('Factura cada venta con los datos de tu cliente.'),
            __('Conexión con el SIN: token, sucursales y puntos de venta.'),
            __('Consulta y reimprime tus facturas cuando las necesites.'),
        ]" />
</div>
