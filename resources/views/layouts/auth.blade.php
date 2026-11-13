@props(['title' => null, 'panel' => 'acceso', 'desde' => null])
{{--
    Acceso y registro: información a la izquierda y formulario a la derecha. En el celular la
    información se reduce a la marca y una línea, para que el formulario quede arriba.
    Las páginas de error usan layouts/centrado.
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark" class="dark">
<head>@include('partials.head')</head>
<body>
    <main class="acceso" id="contenido">
        <aside class="acceso-info">
            <a class="acceso-marca" href="{{ url('/') }}"><x-brand /></a>

            <div class="acceso-mensaje">
                @if ($panel === 'registro')
                    <span class="acceso-etiqueta"><x-icon name="sparkles" size="sm" /> {{ __(':dias días gratis', ['dias' => config('mozistock.prueba_gratis_dias')]) }}</span>
                    <h2>{{ __('Prueba MoziStock en tu negocio, sin pagar nada') }}<span class="accent-text">.</span></h2>
                    <p>{{ __('Crea tu cuenta en un par de minutos y empieza a cargar tus productos hoy mismo.') }}</p>
                    <ul class="acceso-lista">
                        <li><x-icon name="check" /><span><b>{{ __('Sin tarjeta ni pagos por adelantado') }}</b>{{ __('Usas el sistema completo durante la prueba.') }}</span></li>
                        <li><x-icon name="box" /><span><b>{{ __('Inventario, ventas y caja desde el primer día') }}</b>{{ __('Con tu sucursal principal y tu caja ya creadas.') }}</span></li>
                        <li><x-icon name="shield" /><span><b>{{ __('Tus datos son solo de tu empresa') }}</b>{{ __('Nadie más ve tus productos, ventas ni clientes.') }}</span></li>
                        <li><x-icon name="calendar" /><span><b>{{ __('Al terminar, tú decides') }}</b>{{ $desde !== null
                            ? __('Si continúas, pagas desde Bs :precio al mes. Tus datos se conservan.', ['precio' => $desde])
                            : __('Si continúas, eliges tu plan mensual. Tus datos se conservan.') }}</span></li>
                    </ul>
                @else
                    <span class="acceso-etiqueta"><x-icon name="store" size="sm" /> {{ __('Hecho en Bolivia') }}</span>
                    <h2>{{ __('Tu negocio en orden, desde cualquier lugar') }}<span class="accent-text">.</span></h2>
                    <p>{{ __('Inventario por sucursal, ventas, facturas SIAT, cobros y caja en un solo sistema.') }}</p>
                    <ul class="acceso-lista">
                        <li><x-icon name="box" /><span><b>{{ __('Stock al día en cada sucursal') }}</b>{{ __('Compras, ajustes y traspasos con historial.') }}</span></li>
                        <li><x-icon name="receipt" /><span><b>{{ __('Ventas y facturación SIAT') }}</b>{{ __('Del pedido a la factura sin salir del sistema.') }}</span></li>
                        <li><x-icon name="wallet" /><span><b>{{ __('Cobros y créditos claros') }}</b>{{ __('Cada abono cuenta el día en que lo recibes.') }}</span></li>
                        <li><x-icon name="sparkles" /><span><b>{{ __('Mozi, tu agente de IA') }}</b>{{ __('Pregunta por tus ventas y tu stock en lenguaje natural.') }}</span></li>
                    </ul>
                @endif
            </div>

            <p class="acceso-pie">© {{ now()->year }} MoziStudios · {{ __('Hecho en Bolivia') }}</p>
        </aside>

        <section class="acceso-form" aria-label="{{ $panel === 'registro' ? __('Crear cuenta en MoziStock') : __('Acceso a MoziStock') }}">
            <div class="acceso-barra">
                @if ($panel === 'registro')
                    <span class="acceso-cambio">{{ __('¿Ya tienes cuenta?') }} <a class="link" href="{{ route('login') }}">{{ __('Inicia sesión') }}</a></span>
                @elseif (Route::has('registro') && ! request()->routeIs('verification.*'))
                    <span class="acceso-cambio">{{ __('¿Aún no tienes cuenta?') }} <a class="link" href="{{ route('registro') }}">{{ __('Prueba gratis') }}</a></span>
                @else
                    <span></span>
                @endif
                <span class="row" style="gap:6px"><x-idioma-selector />
                    <button type="button" class="btn sm ghost" data-theme-toggle data-label-light="{{ __('Modo claro') }}" data-label-dark="{{ __('Modo oscuro') }}">{{ __('Cambiar tema') }}</button></span>
            </div>

            <div class="acceso-caja {{ $panel === 'registro' ? 'is-ancha' : '' }}">
                {{ $slot }}
            </div>
        </section>
    </main>
    @fluxScripts
</body>
</html>
