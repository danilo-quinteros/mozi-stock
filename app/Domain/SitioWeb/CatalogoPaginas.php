<?php

namespace App\Domain\SitioWeb;

use InvalidArgumentException;

/**
 * Mapa del sitio público aprobado por el propietario (06/11/2026). Cada entrada genera su ruta,
 * su lugar en sitemap.xml y su ficha en Superadmin › Sitio web. Los textos SEO de aquí son los
 * valores por defecto: si el superadmin deja un campo vacío, se usa este.
 *
 * Slugs sin «bolivia»: la palabra va en el title, el H1 y la descripción. «/reportes» y «/caja»
 * ya son pantallas de la app, por eso el módulo de reportes usa /reportes-de-ventas.
 */
final class CatalogoPaginas
{
    /** @var array<string, Pagina>|null */
    private static ?array $paginas = null;

    /**
     * @return array<string, Pagina>
     */
    public static function todas(): array
    {
        return self::$paginas ??= self::definir();
    }

    public static function buscar(string $clave): Pagina
    {
        return self::todas()[$clave] ?? throw new InvalidArgumentException("Página desconocida: {$clave}");
    }

    public static function existe(string $clave): bool
    {
        return isset(self::todas()[$clave]);
    }

    /**
     * @return array<string, Pagina>
     */
    public static function delGrupo(string $grupo): array
    {
        return array_filter(self::todas(), fn (Pagina $pagina): bool => $pagina->grupo === $grupo);
    }

    /**
     * Migas desde el inicio hasta la página (incluida).
     *
     * @return list<Pagina>
     */
    public static function migas(Pagina $pagina): array
    {
        $cadena = [$pagina];

        while ($pagina->padre !== null && $pagina->clave !== 'inicio') {
            $pagina = self::buscar($pagina->padre);
            array_unshift($cadena, $pagina);
        }

        return $cadena;
    }

    /**
     * URL absoluta con el dominio de APP_URL (no el de la petición): canonical, sitemap y og:url
     * no dependen de si el visitante entró por www, por IP o por otro alias.
     */
    public static function urlAbsoluta(string $ruta): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return $ruta === '/' ? $base.'/' : $base.'/'.ltrim($ruta, '/');
    }

    /**
     * @return array<string, Pagina>
     */
    private static function definir(): array
    {
        $paginas = [
            // Principal
            new Pagina('inicio', '/', 'Inicio', 'principal',
                'Sistema de inventario y facturación SIAT en Bolivia | MoziStock',
                'Controla inventario por sucursal, ventas, créditos, caja y facturación SIAT en un solo sistema web para negocios en Bolivia. Prueba gratis 15 días.',
                'Hola, quiero información sobre MoziStock.',
                padre: null, prioridad: '1.0', frecuencia: 'weekly'),
            new Pagina('funcionalidades', 'funcionalidades', 'Funcionalidades', 'principal',
                'Funcionalidades: ventas, inventario y SIAT en Bolivia | MoziStock',
                'Conoce todos los módulos de MoziStock: punto de venta, inventario, sucursales, compras, créditos, caja, reportes, facturación SIAT y agente de IA.',
                'Hola, quiero conocer las funcionalidades de MoziStock.',
                prioridad: '0.9', tipo: 'CollectionPage'),

            // Módulos
            new Pagina('facturacion', 'facturacion-electronica-siat', 'Facturación SIAT', 'modulo',
                'Facturación electrónica SIAT en Bolivia | MoziStock',
                'Emite facturas en línea con el SIAT del Servicio de Impuestos Nacionales desde tus ventas. Modalidad computarizada o electrónica, con inventario incluido.',
                'Hola, quiero información sobre la facturación SIAT de MoziStock.',
                padre: 'funcionalidades', prioridad: '0.9'),
            new Pagina('punto-de-venta', 'punto-de-venta', 'Punto de venta', 'modulo',
                'Sistema punto de venta (POS) para negocios en Bolivia | MoziStock',
                'Vende rápido con lector de código de barras, precios por tipo de cliente y cobro en efectivo, QR o mixto. Pedidos, cotizaciones y devoluciones.',
                'Hola, quiero información sobre el punto de venta de MoziStock.',
                padre: 'funcionalidades', prioridad: '0.9'),
            new Pagina('inventario', 'control-de-inventario', 'Control de inventario', 'modulo',
                'Sistema de control de inventario en Bolivia | MoziStock',
                'Stock por sucursal, alertas de stock bajo, ajustes con motivo y traspasos entre sucursales. Historial completo de cada movimiento de inventario.',
                'Hola, quiero información sobre el control de inventario de MoziStock.',
                padre: 'funcionalidades', prioridad: '0.9'),
            new Pagina('multisucursal', 'sistema-multisucursal', 'Sistema multisucursal', 'modulo',
                'Sistema multisucursal para negocios en Bolivia | MoziStock',
                'Administra varias sucursales desde un solo sistema: stock y caja por sucursal, traspasos entre ellas y vendedores que solo ven su sucursal.',
                'Hola, tengo varias sucursales y quiero información sobre MoziStock.',
                padre: 'funcionalidades'),
            new Pagina('compras', 'compras-y-proveedores', 'Compras y proveedores', 'modulo',
                'Control de compras y proveedores en Bolivia | MoziStock',
                'Registra tus compras, actualiza el stock y el costo de tus productos, y controla lo que debes a cada proveedor con cuentas por pagar.',
                'Hola, quiero información sobre el módulo de compras de MoziStock.',
                padre: 'funcionalidades'),
            new Pagina('creditos', 'ventas-a-credito-y-cobranzas', 'Créditos y cobranzas', 'modulo',
                'Ventas a crédito y cobranzas en Bolivia | MoziStock',
                'Vende a crédito con cuota inicial, registra cada abono el día que lo cobras y sabe cuánto te debe cada cliente. Historial de pagos por venta.',
                'Hola, quiero información sobre las ventas a crédito en MoziStock.',
                padre: 'funcionalidades'),
            new Pagina('caja', 'caja-y-gastos', 'Caja y gastos', 'modulo',
                'Control de caja, arqueo y gastos en Bolivia | MoziStock',
                'Abre y cierra caja por turno, haz el arqueo al final del día y registra tus gastos. Efectivo y pagos electrónicos siempre separados.',
                'Hola, quiero información sobre el control de caja de MoziStock.',
                padre: 'funcionalidades'),
            new Pagina('reportes', 'reportes-de-ventas', 'Reportes', 'modulo',
                'Reportes de ventas, cobros e inventario en Bolivia | MoziStock',
                'Reportes de ventas, cobros reales por fecha, inventario por sucursal y salidas. Lo vendido y lo cobrado por separado, con descarga en CSV.',
                'Hola, quiero información sobre los reportes de MoziStock.',
                padre: 'funcionalidades'),
            new Pagina('agente-ia', 'agente-ia', 'Agente de IA Mozi', 'modulo',
                'Mozi, agente de IA para negocios en Bolivia | MoziStock',
                'Mozi, el agente de IA de MoziStock, te dice qué se está por acabar, qué conviene comprar y quién te debe, con los datos reales de tu negocio.',
                'Hola, quiero información sobre Mozi, el agente de IA de MoziStock.',
                padre: 'funcionalidades', prioridad: '0.9'),

            // Rubros
            new Pagina('rubros', 'rubros', 'Rubros', 'rubro',
                'Sistema de ventas e inventario por rubro en Bolivia | MoziStock',
                'MoziStock se adapta a ferreterías, farmacias, tiendas de ropa y calzado, minimarkets y distribuidoras de Bolivia. Mira cómo funciona en tu rubro.',
                'Hola, quiero saber si MoziStock sirve para mi negocio.',
                tipo: 'CollectionPage'),
            new Pagina('rubro-ferreterias', 'rubros/ferreterias', 'Ferreterías', 'rubro',
                'Sistema para ferreterías en Bolivia | MoziStock',
                'Controla miles de productos, ventas por unidad, metro o kilo, precios mayoristas y créditos a maestros y constructoras. Con facturación SIAT.',
                'Hola, tengo una ferretería y quiero información sobre MoziStock.',
                padre: 'rubros', prioridad: '0.7'),
            new Pagina('rubro-farmacias', 'rubros/farmacias', 'Farmacias', 'rubro',
                'Sistema para farmacias en Bolivia | MoziStock',
                'Venta rápida con código de barras, alertas de stock bajo por sucursal, caja por turno y facturación SIAT para farmacias y boticas de Bolivia.',
                'Hola, tengo una farmacia y quiero información sobre MoziStock.',
                padre: 'rubros', prioridad: '0.7'),
            new Pagina('rubro-ropa-calzado', 'rubros/tiendas-de-ropa-y-calzado', 'Tiendas de ropa y calzado', 'rubro',
                'Sistema para tiendas de ropa y calzado en Bolivia | MoziStock',
                'Controla cada modelo, talla y color como producto, vende con código de barras, maneja devoluciones y créditos y mueve mercadería entre tiendas.',
                'Hola, tengo una tienda de ropa o calzado y quiero información sobre MoziStock.',
                padre: 'rubros', prioridad: '0.7'),
            new Pagina('rubro-minimarkets', 'rubros/minimarkets', 'Minimarkets y tiendas', 'rubro',
                'Sistema para minimarkets y tiendas en Bolivia | MoziStock',
                'Cobra rápido con lector de código de barras, efectivo o QR, cierra caja cuadrada cada turno y deja de anotar fiados en un cuaderno.',
                'Hola, tengo un minimarket o tienda y quiero información sobre MoziStock.',
                padre: 'rubros', prioridad: '0.7'),
            new Pagina('rubro-distribuidoras', 'rubros/distribuidoras', 'Distribuidoras y mayoristas', 'rubro',
                'Sistema para distribuidoras y mayoristas en Bolivia | MoziStock',
                'Pedidos y cotizaciones, precios mayoristas, ventas a crédito con plan de cuotas, cobranza a clientes y stock por almacén para distribuidoras.',
                'Hola, tengo una distribuidora y quiero información sobre MoziStock.',
                padre: 'rubros', prioridad: '0.7'),

            // Comercial y confianza
            new Pagina('precios', 'precios', 'Precios', 'comercial',
                'Precios y planes de MoziStock en Bolivia | Prueba gratis 15 días',
                'Planes mensuales en bolivianos: inventario, facturación SIAT y agente de IA. Prueba gratis 15 días, sin tarjeta, y paga tu mensualidad con QR.',
                'Hola, quiero información sobre los planes y precios de MoziStock.',
                prioridad: '0.9', frecuencia: 'weekly'),
            new Pagina('preguntas-frecuentes', 'preguntas-frecuentes', 'Preguntas frecuentes', 'comercial',
                'Preguntas frecuentes sobre MoziStock | Sistema para Bolivia',
                'Resolvemos tus dudas sobre MoziStock: prueba gratis, facturación SIAT, sucursales, seguridad de tus datos, pagos y soporte en Bolivia.',
                'Hola, tengo una consulta sobre MoziStock.',
                prioridad: '0.7'),
            new Pagina('nosotros', 'nosotros', 'Nosotros', 'comercial',
                'Quiénes somos | MoziStock, sistema de gestión hecho en Bolivia',
                'MoziStock es un sistema de gestión creado por MoziStudios para comercios de Bolivia: inventario, ventas, cobros y facturación SIAT en un solo lugar.',
                'Hola, quiero conocer más sobre MoziStock.',
                prioridad: '0.5', frecuencia: 'yearly', tipo: 'AboutPage'),
            new Pagina('contacto', 'contacto', 'Contacto', 'comercial',
                'Contacto y demostración por WhatsApp | MoziStock Bolivia',
                'Escríbenos por WhatsApp y te mostramos MoziStock funcionando con un negocio como el tuyo. Resolvemos tus dudas sobre planes y facturación SIAT.',
                'Hola, quiero una demostración de MoziStock.',
                prioridad: '0.7', frecuencia: 'yearly', tipo: 'ContactPage'),

            // Legal
            new Pagina('terminos', 'terminos', 'Términos y condiciones', 'legal',
                'Términos y condiciones del servicio | MoziStock',
                'Condiciones de uso del sistema MoziStock: cuenta, prueba gratis, suscripción mensual, pagos, datos de tu empresa y facturación SIAT.',
                'Hola, tengo una consulta sobre los términos de MoziStock.',
                prioridad: '0.3', frecuencia: 'yearly'),
            new Pagina('privacidad', 'privacidad', 'Política de privacidad', 'legal',
                'Política de privacidad | MoziStock',
                'Cómo MoziStock recopila, usa y protege los datos de tu empresa, tus clientes y tus usuarios. Tus datos no se comparten con otras empresas.',
                'Hola, tengo una consulta sobre la privacidad de mis datos en MoziStock.',
                prioridad: '0.3', frecuencia: 'yearly'),
        ];

        $porClave = [];

        foreach ($paginas as $pagina) {
            $porClave[$pagina->clave] = $pagina;
        }

        return $porClave;
    }
}
