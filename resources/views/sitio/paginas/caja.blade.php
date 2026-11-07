<x-sitio.layout :pagina="$pagina" :seo="$seo" :esquema="$esquema">
    <x-sitio.hero :pagina="$pagina"
        etiqueta="Caja, arqueo y gastos"
        titulo="Control de caja y gastos para tu negocio en <span class='hl'>Bolivia</span>"
        lead="Abre y cierra la caja por turno, cuenta el efectivo al final del día y compara con lo que el sistema espera. Registra tus gastos y separa siempre el efectivo de los pagos por QR o transferencia."
        :puntos="['Turnos por cajero', 'Arqueo al cierre', 'Egresos por categoría']">
        <x-slot:visual>
            <div class="visual-card">
                <div class="visual-title">Cierre de turno<small>Caja 1 · Casa Matriz</small></div>
                <div class="demo">
                    <div class="demo-row"><span>Apertura</span><strong>Bs 200,00</strong></div>
                    <div class="demo-row"><span>Cobros en efectivo</span><strong>Bs 1.180,00</strong></div>
                    <div class="demo-row"><span>Egresos</span><strong>− Bs 140,00</strong></div>
                    <div class="demo-row"><span><b>Efectivo esperado</b></span><strong>Bs 1.240,00</strong></div>
                    <div class="demo-row"><span><b>Efectivo contado</b></span><strong>Bs 1.240,00</strong></div>
                    <div class="demo-foot"><span>Arqueo del turno</span><em class="badge ok">Cuadrada</em></div>
                </div>
            </div>
        </x-slot:visual>
    </x-sitio.hero>

    <section class="section" aria-labelledby="ca-funciones">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Qué incluye</p>
                <h2 id="ca-funciones">Cierra cada día sabiendo cuánto entró y cuánto salió</h2>
                <p class="lead">Una caja ordenada evita faltantes, discusiones y sorpresas a fin de mes.</p>
            </header>
            <x-sitio.funciones :items="[
                ['clock', 'Turnos de caja', 'Cada cajero abre su turno con un monto inicial y lo cierra al terminar. Sabes quién manejó la caja y cuándo.'],
                ['cash', 'Arqueo al cierre', 'Cuenta el efectivo y el sistema lo compara con lo esperado. Si hay diferencia, queda registrada con su resultado.'],
                ['qr', 'Efectivo y electrónico separados', 'Los cobros por QR o transferencia no se mezclan con el efectivo que debe haber en el cajón.'],
                ['receipt', 'Egresos', 'Registra gastos del negocio por categoría: alquiler, servicios, transporte, sueldos y lo que necesites.'],
                ['wallet', 'Cobros y abonos incluidos', 'Las ventas al contado, las cuotas iniciales y los abonos de créditos entran a la caja del día en que se cobran.'],
                ['download', 'Impresión y CSV', 'Imprime el arqueo del turno o descárgalo en CSV para tu control o tu contador.'],
            ]" />
        </div>
    </section>

    <section class="section section-alt" aria-labelledby="ca-pagar">
        <div class="wrap">
            <div class="split">
                <div class="split-copy reveal">
                    <p class="eyebrow">Lo que debes</p>
                    <h2 id="ca-pagar">Cuentas por pagar bajo control</h2>
                    <p>Además de los gastos del día, registra lo que debes a proveedores y otras obligaciones con su fecha de vencimiento. Registra cada pago, parcial o total, y adjunta su comprobante.</p>
                    <a class="module-link" href="{{ route('sitio.compras') }}">Ver compras y proveedores<x-sitio.i n="arrow" /></a>
                </div>
                <div class="visual-card reveal" aria-hidden="true">
                    <div class="visual-title">Egresos del mes<small>Por categoría</small></div>
                    <div class="demo">
                        <div class="hbar"><span>Alquiler</span><i><s style="--w:100%"></s></i><b>Bs 2.500</b></div>
                        <div class="hbar"><span>Sueldos</span><i><s style="--w:84%"></s></i><b>Bs 2.100</b></div>
                        <div class="hbar"><span>Servicios</span><i><s style="--w:18%"></s></i><b>Bs 460</b></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="ca-faq">
        <div class="wrap">
            <header class="section-head">
                <p class="eyebrow">Preguntas</p>
                <h2 id="ca-faq">Preguntas sobre el control de caja</h2>
            </header>
            <x-sitio.faq :preguntas="[
                ['¿Puedo tener varias cajas en una sucursal?', 'Sí. Cada sucursal tiene una caja principal y puedes agregar más cajas, cada una con sus propios turnos.'],
                ['¿Qué pasa si al cerrar falta o sobra dinero?', 'El arqueo registra la diferencia entre el efectivo esperado y el contado, para que la revises con el cajero.'],
                ['¿Los pagos por QR entran en el arqueo?', 'Se registran en la caja, pero separados del efectivo: el arqueo compara solo el efectivo que debe haber en el cajón.'],
                ['¿Puedo registrar gastos pequeños del día?', 'Sí. Registra cada egreso con su categoría, monto y nota, y aparece en tu caja y en tus reportes.'],
            ]" />
        </div>
    </section>

    <x-sitio.relacionados actual="caja" :claves="['punto-de-venta', 'creditos', 'reportes']" />
    <x-sitio.cta titulo="Cierra tu caja cuadrada cada día" :mensaje="$seo->mensajeWhatsapp" />
</x-sitio.layout>
