<x-sitio.layout :pagina="$pagina" :seo="$seo" :esquema="$esquema">
    @php($dias = config('mozistock.prueba_gratis_dias'))
    <x-sitio.hero :pagina="$pagina"
        etiqueta="Legal"
        titulo="Términos y condiciones"
        lead="Condiciones de uso del sistema MoziStock, ofrecido por MoziStudios a empresas y comercios de Bolivia."
        :ctas="false" />

    <section class="section" style="padding-top:0">
        <div class="wrap">
            <article class="prose">
                <p class="updated">Última actualización: 6 de noviembre de 2026.</p>

                <h2>1. El servicio</h2>
                <p>MoziStock es un sistema web de gestión comercial (inventario, ventas, cobros, caja, reportes, facturación en línea con el SIAT y asistente de inteligencia artificial) que MoziStudios ofrece como servicio por suscripción. Al crear una cuenta o usar el sistema aceptas estos términos en nombre de tu empresa.</p>

                <h2>2. Cuenta y usuarios</h2>
                <ul>
                    <li>La cuenta pertenece a la empresa registrada. Su administrador crea y da de baja a los demás usuarios y define su sucursal y su rol.</li>
                    <li>Cada usuario debe proteger su contraseña. Las operaciones hechas con un usuario se consideran hechas por esa persona.</li>
                    <li>La información que registres (productos, precios, clientes, ventas y demás) debe ser verídica y de tu responsabilidad.</li>
                </ul>

                <h2>3. Prueba gratis</h2>
                <p>Las empresas nuevas pueden usar MoziStock gratis durante {{ $dias }} días, sin necesidad de tarjeta. Al terminar la prueba, para seguir usando el sistema debes elegir un plan y pagar la mensualidad. Los datos cargados durante la prueba se conservan.</p>

                <h2>4. Planes, pagos y cambios de plan</h2>
                <ul>
                    <li>Los precios se publican en la página de <a href="{{ route('sitio.precios') }}">precios</a>, en bolivianos y por mes.</li>
                    <li>La mensualidad se paga por QR desde la sección Suscripción del sistema, adjuntando el comprobante para su revisión.</li>
                    <li>Puedes cambiar de plan en cualquier momento. Si pasas a un plan de mayor precio, pagas la diferencia proporcional a los días que faltan del periodo.</li>
                    <li>Enviamos recordatorios antes y después del vencimiento. La falta de pago no bloquea la cuenta de forma automática; MoziStudios puede suspenderla de forma manual tras comunicarse con la empresa.</li>
                </ul>

                <h2>5. Facturación SIAT</h2>
                <p>La facturación en línea está disponible en el plan que la incluye y requiere que tu empresa esté habilitada ante el Servicio de Impuestos Nacionales (SIN) y que sus datos fiscales sean correctos. Tu empresa es responsable del contenido de cada factura que emite y del cumplimiento de sus obligaciones tributarias. MoziStock transmite las facturas al SIAT según la normativa vigente, pero no reemplaza el asesoramiento de un contador.</p>

                <h2>6. Asistente de inteligencia artificial</h2>
                <p>El asistente Mozi responde a partir de los datos de tu empresa y de los permisos de tu usuario. Sus análisis, pronósticos y sugerencias son de apoyo: las decisiones comerciales son tuyas. Mozi no ejecuta operaciones sin tu confirmación ni realiza acciones fiscales ante el SIN. El uso puede tener límites diarios por empresa.</p>

                <h2>7. Tus datos</h2>
                <p>Los datos que registras son de tu empresa. MoziStudios los trata según la <a href="{{ route('sitio.privacidad') }}">política de privacidad</a>, solo para prestar el servicio, y nunca los comparte con otras empresas usuarias. Puedes descargar tu información en CSV desde el sistema.</p>

                <h2>8. Uso correcto</h2>
                <ul>
                    <li>No está permitido intentar acceder a información de otras empresas, vulnerar la seguridad del sistema ni usarlo para actividades ilícitas.</li>
                    <li>MoziStudios puede suspender usuarios o cuentas que incumplan estos términos.</li>
                </ul>

                <h2>9. Disponibilidad y soporte</h2>
                <p>Trabajamos para que MoziStock esté disponible de forma continua, pero puede haber interrupciones por mantenimiento, fallas de internet o de servicios externos, incluido el SIAT. Brindamos soporte por WhatsApp y correo electrónico.</p>

                <h2>10. Responsabilidad</h2>
                <p>MoziStudios no responde por pérdidas derivadas de información incorrecta registrada por los usuarios, del mal uso de las credenciales ni de decisiones comerciales tomadas a partir de los reportes o del asistente de IA.</p>

                <h2>11. Cambios a estos términos</h2>
                <p>Podemos actualizar estos términos. Publicaremos la nueva versión en esta página con su fecha de actualización y, si el cambio es importante, te avisaremos dentro del sistema.</p>

                <h2>12. Legislación aplicable y contacto</h2>
                <p>Estos términos se rigen por las leyes del Estado Plurinacional de Bolivia. Para cualquier consulta, escríbenos desde la página de <a href="{{ route('sitio.contacto') }}">contacto</a>@if (filled($sitioAjustes->correo_contacto)) o a <a href="mailto:{{ $sitioAjustes->correo_contacto }}">{{ $sitioAjustes->correo_contacto }}</a>@endif.</p>
            </article>
        </div>
    </section>
</x-sitio.layout>
