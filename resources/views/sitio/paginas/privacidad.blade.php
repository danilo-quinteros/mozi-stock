<x-sitio.layout :pagina="$pagina" :seo="$seo" :esquema="$esquema">
    <x-sitio.hero :pagina="$pagina"
        etiqueta="Legal"
        titulo="Política de privacidad"
        lead="Cómo MoziStock recopila, usa y protege la información de tu empresa, de tus usuarios y de tus clientes."
        :ctas="false" />

    <section class="section" style="padding-top:0">
        <div class="wrap">
            <article class="prose">
                <p class="updated">Última actualización: 6 de noviembre de 2026.</p>

                <h2>1. Quién es responsable</h2>
                <p>MoziStudios es responsable del sistema MoziStock y del tratamiento de los datos descritos en esta política. Tu empresa es responsable de la información de sus clientes y proveedores que registra en el sistema.</p>

                <h2>2. Qué información tratamos</h2>
                <ul>
                    <li><strong>De tu empresa:</strong> nombre, NIT, sucursales, datos fiscales y de contacto, y la información comercial que registras (productos, precios, stock, compras, ventas, cobros, caja y facturas).</li>
                    <li><strong>De tus usuarios:</strong> nombre, correo electrónico, rol, sucursal asignada y registros de actividad dentro del sistema.</li>
                    <li><strong>De tus clientes y proveedores:</strong> los datos que tu empresa decida registrar, como nombre, NIT o carnet, teléfono y saldos.</li>
                    <li><strong>Comprobantes y archivos:</strong> imágenes de productos, comprobantes de pago y certificados de firma digital que subas.</li>
                    <li><strong>Datos técnicos:</strong> dirección IP, navegador y registros de acceso necesarios para la seguridad del servicio.</li>
                </ul>

                <h2>3. Para qué la usamos</h2>
                <ul>
                    <li>Prestar el servicio: mostrar tu información, calcular reportes y emitir facturas con el SIAT cuando corresponde.</li>
                    <li>Responder las consultas del agente de IA Mozi sobre tu propio negocio.</li>
                    <li>Gestionar tu suscripción, revisar tus pagos y enviarte avisos sobre el servicio.</li>
                    <li>Proteger el sistema, prevenir accesos indebidos y auditar operaciones de dinero y stock.</li>
                </ul>

                <h2>4. Separación entre empresas</h2>
                <p>La información de cada empresa está aislada: otra empresa usuaria no puede verla en pantallas, descargas, archivos ni notificaciones. Dentro de tu empresa, cada usuario accede solo a lo que su rol permite.</p>

                <h2>5. Con quién la compartimos</h2>
                <ul>
                    <li><strong>Servicio de Impuestos Nacionales (SIN):</strong> los datos de las facturas que emites, solo si usas la facturación SIAT.</li>
                    <li><strong>Proveedores de inteligencia artificial:</strong> para responder tus preguntas a Mozi se envían los datos necesarios para esa consulta, sin compartirlos con otras empresas usuarias.</li>
                    <li><strong>Proveedores de infraestructura:</strong> servicios de alojamiento y correo que nos permiten operar el sistema.</li>
                </ul>
                <p>No vendemos tu información ni la usamos para publicidad de terceros.</p>

                <h2>6. Seguridad</h2>
                <p>Usamos conexiones cifradas, contraseñas protegidas, almacenamiento privado de comprobantes y certificados, permisos por rol y registros de auditoría. Ningún sistema es infalible, por eso también te pedimos proteger tus contraseñas.</p>

                <h2>7. Conservación y descarga</h2>
                <p>Conservamos la información mientras tu empresa tenga una cuenta activa y durante el tiempo necesario para cumplir obligaciones legales y tributarias. Puedes descargar tu información en CSV desde el sistema.</p>

                <h2>8. Tus derechos</h2>
                <p>Puedes pedirnos acceder, corregir o actualizar tus datos personales, conforme a la legislación del Estado Plurinacional de Bolivia. Escríbenos desde la página de <a href="{{ route('sitio.contacto') }}">contacto</a>@if (filled($sitioAjustes->correo_contacto)) o a <a href="mailto:{{ $sitioAjustes->correo_contacto }}">{{ $sitioAjustes->correo_contacto }}</a>@endif.</p>

                <h2>9. Este sitio web</h2>
                <p>El sitio público de MoziStock guarda en tu navegador solo tu preferencia de tema claro u oscuro. Si en el futuro usamos herramientas de medición o publicidad, lo indicaremos en esta página.</p>

                <h2>10. Cambios</h2>
                <p>Si actualizamos esta política, publicaremos la nueva versión en esta página con su fecha de actualización.</p>
            </article>
        </div>
    </section>
</x-sitio.layout>
