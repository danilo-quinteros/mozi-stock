<x-sitio.layout :pagina="$pagina" :seo="$seo" :esquema="$esquema">
    @php($dias = config('mozistock.prueba_gratis_dias'))

    {{-- 1 · Portada --}}
    <section class="hero" id="inicio">
        <div class="wrap hero-in">
            <div class="hero-copy">
                <p class="pill"><span class="pulse" aria-hidden="true"></span>Sistema de inventario, ventas y facturación · Bolivia</p>
                <h1>Sistema de inventario y facturación SIAT para tu negocio en <span class="hl">Bolivia</span></h1>
                <p class="hero-lead">Ten claro qué tienes, qué vendiste y cuánto cobraste. MoziStock ordena tu inventario por sucursal, tus ventas, créditos, caja y facturas del SIAT en un solo sistema web. Lo usas desde el celular o la computadora, sin instalar nada.</p>
                <div class="hero-cta">
                    <x-sitio.prueba class="btn btn-primary btn-lg"><x-sitio.i n="gift" />Probar gratis {{ $dias }} días</x-sitio.prueba>
                    <x-sitio.wa class="btn btn-ghost btn-lg" mensaje="Hola, quiero una demostración de MoziStock."><x-sitio.i n="wa" />Pedir una demostración</x-sitio.wa>
                </div>
                <ul class="hero-points">
                    <li><x-sitio.i n="check" />Facturación SIAT incluida</li>
                    <li><x-sitio.i n="check" />Varias sucursales</li>
                    <li><x-sitio.i n="check" />Efectivo, QR o mixto</li>
                    <li><x-sitio.i n="check" />Asistente de IA</li>
                </ul>
            </div>

            <div class="hero-visual" role="img" aria-label="Pantalla de Resumen de MoziStock con lo cobrado y lo vendido hoy, el saldo por cobrar y los cobros de la semana.">
                <div class="mock" aria-hidden="true">
                    <div class="mock-top">
                        <span class="dots"><i></i><i></i><i></i></span>
                        <span class="mock-title">MoziStock · Resumen</span>
                        <span class="mock-branch"><x-sitio.i n="store" />Casa Matriz</span>
                    </div>
                    <div class="mock-body">
                        <div class="mock-side">
                            <span class="brand-mark sm"><span></span><span></span><span></span></span>
                            <i class="on"><x-sitio.i n="dashboard" /></i>
                            <i><x-sitio.i n="cart" /></i>
                            <i><x-sitio.i n="wallet" /></i>
                            <i><x-sitio.i n="box" /></i>
                            <i><x-sitio.i n="receipt" /></i>
                            <i><x-sitio.i n="sparkles" /></i>
                        </div>
                        <div class="mock-main">
                            <div class="mock-head">
                                <div><span class="mock-eyebrow">Panorama general</span><b>Hoy en tu negocio</b></div>
                                <span class="mock-btn"><x-sitio.i n="plus" />Nueva venta</span>
                            </div>
                            <div class="mock-kpis">
                                <div class="kpi"><span>Cobrado hoy</span><b data-cobrado>Bs&nbsp;1.930,00</b><small>Incluye abonos</small></div>
                                <div class="kpi"><span>Vendido hoy</span><b data-vendido>Bs&nbsp;2.480,00</b><small>Valor comercial</small></div>
                                <div class="kpi kpi-3"><span>Por cobrar</span><b>Bs&nbsp;550,00</b><small>3 créditos con saldo</small></div>
                            </div>
                            <div class="mock-grid">
                                <div class="mock-card">
                                    <b class="mock-card-title">Cobros de los últimos 7 días</b>
                                    <div class="bars">
                                        <span style="--h:44%" data-d="Vie"></span>
                                        <span style="--h:62%" data-d="Sáb"></span>
                                        <span style="--h:40%" data-d="Dom"></span>
                                        <span style="--h:78%" data-d="Lun"></span>
                                        <span style="--h:52%" data-d="Mar"></span>
                                        <span style="--h:88%" data-d="Mié"></span>
                                        <span style="--h:70%" data-d="Hoy" data-today></span>
                                    </div>
                                </div>
                                <div class="mock-card mock-ops">
                                    <b class="mock-card-title">Últimas operaciones</b>
                                    <ul>
                                        <li><span><b>Factura SIAT</b><small>V-01248 · Bs 85,00</small></span><em class="badge ok">Validada</em></li>
                                        <li><span><b>Abono de crédito</b><small>V-01231 · Bs 30,00</small></span><em class="badge info">Cobrado</em></li>
                                        <li><span><b>Cotización</b><small>COT-0042 · Bs 140,00</small></span><em class="badge warn">Pendiente</em></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="float float-sale" aria-hidden="true" data-sale>
                    <span class="float-icon ok"><x-sitio.i n="check" /></span>
                    <span><b>Venta confirmada</b><small data-sale-text>V-01248 · Bs 85,00 · Efectivo</small></span>
                </div>
                <div class="float float-stock" aria-hidden="true">
                    <span class="float-icon warn"><x-sitio.i n="box" /></span>
                    <span><b>Stock bajo</b><small>Aceite 1 L · quedan 3 en Sucursal Norte</small></span>
                </div>
            </div>
        </div>
    </section>

    {{-- 2 · Para quién --}}
    <section class="fit" aria-labelledby="fit-title">
        <div class="wrap">
            <h2 class="fit-title" id="fit-title">Hecho para negocios que venden productos</h2>
            <ul class="fit-list">
                <li><a href="{{ route('sitio.rubro-ferreterias') }}">Ferreterías</a></li>
                <li><a href="{{ route('sitio.rubro-farmacias') }}">Farmacias</a></li>
                <li><a href="{{ route('sitio.rubro-ropa-calzado') }}">Ropa y calzado</a></li>
                <li><a href="{{ route('sitio.rubro-minimarkets') }}">Minimarkets y tiendas</a></li>
                <li><a href="{{ route('sitio.rubro-distribuidoras') }}">Distribuidoras</a></li>
            </ul>
        </div>
    </section>

    {{-- 3 · Módulos --}}
    <section class="section" id="modulos" aria-labelledby="modulos-title">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Funcionalidades</p>
                <h2 id="modulos-title">Todo tu negocio en un solo sistema</h2>
                <p class="lead">Inventario, ventas, cobros, caja, reportes, facturación SIAT y un asistente de inteligencia artificial que conoce tu negocio.</p>
            </header>

            <div class="modules">
                <article class="module reveal">
                    <div class="module-top"><span class="module-icon"><x-sitio.i n="box" /></span><h3>Control de inventario</h3></div>
                    <p>Sabe cuánto tienes de cada producto en cada sucursal y ve al instante lo que se está acabando.</p>
                    <ul class="tags" aria-label="Incluye"><li>Productos</li><li>Ajustes</li><li>Traspasos</li></ul>
                    <div class="demo" aria-hidden="true">
                        <div class="demo-row"><span><b>Arroz 5 kg</b><small>Casa Matriz</small></span><strong>48 u.</strong></div>
                        <div class="demo-row"><span><b>Arroz 5 kg</b><small>Sucursal Norte</small></span><strong>6 u. <em class="badge warn">Bajo</em></strong></div>
                    </div>
                    <a class="module-link" href="{{ route('sitio.inventario') }}">Ver control de inventario<x-sitio.i n="arrow" /></a>
                </article>

                <article class="module reveal" style="--d:80ms">
                    <div class="module-top"><span class="module-icon"><x-sitio.i n="cart" /></span><h3>Punto de venta</h3></div>
                    <p>Cotiza, vende y cobra rápido. Elige precio consumidor, minorista o mayorista y usa tu lector de código de barras.</p>
                    <ul class="tags" aria-label="Incluye"><li>Pedidos</li><li>Venta rápida</li><li>Devoluciones</li></ul>
                    <div class="demo" aria-hidden="true">
                        <div class="demo-row"><span><b>Aceite 1 L × 2</b><small>Precio consumidor</small></span><strong>Bs 26,00</strong></div>
                        <div class="seg"><span>Efectivo</span><span>Electrónico</span><span class="on">Mixto</span></div>
                    </div>
                    <a class="module-link" href="{{ route('sitio.punto-de-venta') }}">Ver punto de venta<x-sitio.i n="arrow" /></a>
                </article>

                <article class="module reveal" style="--d:160ms">
                    <div class="module-top"><span class="module-icon"><x-sitio.i n="wallet" /></span><h3>Créditos y cobranzas</h3></div>
                    <p>Vende a crédito con cuota inicial, incluso de Bs 0. Registra cada abono y mira cuánto te debe cada cliente.</p>
                    <ul class="tags" aria-label="Incluye"><li>Créditos</li><li>Abonos</li><li>Plan de cuotas</li></ul>
                    <div class="demo" aria-hidden="true">
                        <div class="demo-row"><span><b>Crédito de Bs 200,00</b><small>Inicial Bs 50 · abono Bs 30</small></span><strong>40 %</strong></div>
                        <div class="meter"><span style="--p:40%"></span></div>
                        <div class="demo-foot"><span>Pagado <b>Bs 80,00</b></span><span>Saldo <b class="accent">Bs 120,00</b></span></div>
                    </div>
                    <a class="module-link" href="{{ route('sitio.creditos') }}">Ver créditos y cobranzas<x-sitio.i n="arrow" /></a>
                </article>

                <article class="module reveal">
                    <div class="module-top"><span class="module-icon"><x-sitio.i n="cash" /></span><h3>Caja y gastos</h3></div>
                    <p>Abre y cierra tu caja por turno y haz el arqueo al final del día. Registra gastos y lo que debes a tus proveedores.</p>
                    <ul class="tags" aria-label="Incluye"><li>Caja y arqueo</li><li>Egresos</li><li>Cuentas por pagar</li></ul>
                    <div class="demo" aria-hidden="true">
                        <div class="demo-row"><span>Efectivo esperado</span><strong>Bs 1.240,00</strong></div>
                        <div class="demo-row"><span>Efectivo contado</span><strong>Bs 1.240,00</strong></div>
                        <div class="demo-foot"><span>Arqueo del turno</span><em class="badge ok">Cuadrada</em></div>
                    </div>
                    <a class="module-link" href="{{ route('sitio.caja') }}">Ver caja y gastos<x-sitio.i n="arrow" /></a>
                </article>

                <article class="module reveal" style="--d:80ms">
                    <div class="module-top"><span class="module-icon"><x-sitio.i n="chart" /></span><h3>Reportes</h3></div>
                    <p>Si vendes Bs 200 a crédito y hoy te pagan Bs 50, tus cobros de hoy dicen Bs 50. Lo vendido y lo cobrado, siempre por separado.</p>
                    <ul class="tags" aria-label="Incluye"><li>Ventas</li><li>Cobros</li><li>Inventario</li><li>CSV</li></ul>
                    <div class="demo" aria-hidden="true">
                        <div class="hbar"><span>Vendido</span><i><s style="--w:100%"></s></i><b>Bs 2.480</b></div>
                        <div class="hbar"><span>Cobrado</span><i><s class="alt" style="--w:78%"></s></i><b>Bs 1.930</b></div>
                    </div>
                    <a class="module-link" href="{{ route('sitio.reportes') }}">Ver reportes<x-sitio.i n="arrow" /></a>
                </article>

                <article class="module reveal" style="--d:160ms">
                    <div class="module-top"><span class="module-icon"><x-sitio.i n="store" /></span><h3>Sucursales y equipo</h3></div>
                    <p>Maneja varias sucursales y usuarios. Cada vendedor trabaja solo en su sucursal y su caja; tú ves todo.</p>
                    <ul class="tags" aria-label="Incluye"><li>Sucursales</li><li>Usuarios</li><li>Clientes</li><li>Proveedores</li></ul>
                    <div class="demo" aria-hidden="true">
                        <div class="demo-row"><span class="who"><i class="av">AD</i><span><b>Administrador</b><small>Todas las sucursales</small></span></span></div>
                        <div class="demo-row"><span class="who"><i class="av alt">VE</i><span><b>Vendedor</b><small>Sucursal Norte · Caja 2</small></span></span><x-sitio.i n="lock" class="lock" /></div>
                    </div>
                    <a class="module-link" href="{{ route('sitio.multisucursal') }}">Ver sistema multisucursal<x-sitio.i n="arrow" /></a>
                </article>

                <article class="module module-siat reveal">
                    <div class="siat-copy">
                        <div class="module-top"><span class="module-icon"><x-sitio.i n="receipt" /></span><h3>Facturación electrónica SIAT</h3></div>
                        <p class="flags"><span class="flag">Plan Inventario + SIAT</span></p>
                        <p>Emite tus facturas en línea con el SIAT del Servicio de Impuestos Nacionales desde tus ventas confirmadas, en modalidad computarizada o electrónica. Consulta el estado de cada factura, imprímela o descarga su XML cuando la necesites.</p>
                        <a class="module-link" href="{{ route('sitio.facturacion') }}">Ver facturación SIAT<x-sitio.i n="arrow" /></a>
                    </div>
                    <div class="invoice" aria-hidden="true">
                        <div class="invoice-head"><b>Factura</b><em class="badge ok">Validada por el SIN</em></div>
                        <div class="invoice-meta"><span>Venta V-01248</span><span>Casa Matriz</span></div>
                        <div class="invoice-lines">
                            <div><span>Aceite 1 L × 2</span><b>Bs 26,00</b></div>
                            <div><span>Arroz 5 kg × 1</span><b>Bs 59,00</b></div>
                        </div>
                        <div class="invoice-total"><span>Total</span><b>Bs 85,00</b></div>
                        <div class="invoice-foot"><x-sitio.i n="qr" /><small>Lista para imprimir o descargar</small></div>
                    </div>
                </article>

                <article class="module module-ai reveal" style="--d:80ms">
                    <div class="module-top"><span class="module-icon"><x-sitio.i n="sparkles" /></span><h3>Asistente de IA Mozi</h3></div>
                    <p>Pregúntale «¿qué productos se están por acabar?» o «¿quién me debe más?» y te responde con los datos reales de tu negocio, en tablas y gráficos.</p>
                    <div class="chat" aria-hidden="true">
                        <span class="bubble me">¿Qué se está por acabar?</span>
                        <span class="bubble bot"><i></i><i></i><i></i></span>
                    </div>
                    <a class="module-link" href="{{ route('sitio.asistente-ia') }}">Conocer a Mozi<x-sitio.i n="arrow" /></a>
                </article>
            </div>
        </div>
    </section>

    {{-- 4 · Por qué --}}
    <section class="section section-alt" aria-labelledby="porque-title">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Por qué MoziStock</p>
                <h2 id="porque-title">Pensado para cómo se trabaja en Bolivia</h2>
                <p class="lead">Bolivianos, QR, ventas al fiado, varias sucursales y facturación con el SIN. Sin adaptar un sistema de otro país.</p>
            </header>
            <x-sitio.funciones :items="[
                ['qr', 'Efectivo, QR o mixto', 'Cobra como te pagan tus clientes. En un pago mixto registras cuánto fue en efectivo y cuánto por QR o transferencia.'],
                ['receipt', 'Facturación con el SIN', 'Conectado al SIAT para emitir facturas en línea. Tu inventario, tus ventas y tus facturas en el mismo sistema.'],
                ['wallet', 'Créditos sin cuaderno', 'Cada abono se registra el día que lo cobras. Sabes quién te debe, cuánto y desde cuándo.'],
                ['devices', 'Desde cualquier equipo', 'Es un sistema web: entras desde el navegador del celular, tablet o computadora. Sin instalar nada.'],
                ['shield', 'Tus datos, solo tuyos', 'Cada empresa trabaja solo con su información y cada usuario ve lo que le corresponde según su rol.'],
                ['clock', 'Listo en un día', 'Una guía te lleva paso a paso: productos, inventario inicial y tu primera venta.'],
            ]" />
        </div>
    </section>

    {{-- 5 · Planes --}}
    <section class="section" id="planes" aria-labelledby="planes-title">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Precios</p>
                <h2 id="planes-title">Elige tu plan</h2>
                <p class="lead">Los dos planes incluyen todos los módulos. La única diferencia es la facturación SIAT. Prueba gratis {{ $dias }} días.</p>
            </header>
            <x-sitio.planes :planes="$planes" />
        </div>
    </section>

    {{-- 6 · Preguntas --}}
    <section class="section section-alt" id="preguntas" aria-labelledby="preguntas-title">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Preguntas</p>
                <h2 id="preguntas-title">Lo que más nos preguntan</h2>
            </header>
            <x-sitio.faq :preguntas="[
                ['¿Qué es MoziStock?', 'Es un sistema web de inventario, ventas, cobros, caja, reportes y facturación SIAT para negocios de Bolivia. Funciona desde el navegador del celular, tablet o computadora.'],
                ['¿Cómo funciona la prueba gratis?', 'Tienes '.$dias.' días para usar MoziStock con todos los módulos y tus propios productos. No pedimos tarjeta. Al terminar eliges tu plan y sigues trabajando con la misma información.'],
                ['¿MoziStock emite facturas del SIAT?', 'Sí. Con el plan Inventario + SIAT emites facturas en línea con el Servicio de Impuestos Nacionales desde tus ventas confirmadas, en modalidad computarizada o electrónica.'],
                ['¿Sirve si tengo varias sucursales?', 'Sí. Cada sucursal tiene su propio stock y su caja, y puedes traspasar productos entre ellas. Tú ves todo; cada vendedor, solo su sucursal.'],
                ['¿Otra empresa puede ver mis datos?', 'No. Cada empresa trabaja solo con su propia información: productos, clientes, ventas y usuarios. Y dentro de tu empresa, cada usuario ve lo que le corresponde.'],
            ]" />
            <p style="margin-top:22px;text-align:center"><a class="module-link" href="{{ route('sitio.preguntas-frecuentes') }}" style="justify-content:center">Ver todas las preguntas frecuentes<x-sitio.i n="arrow" /></a></p>
        </div>
    </section>

    <x-sitio.cta titulo="Ordena tu negocio en tres pasos" />
</x-sitio.layout>
