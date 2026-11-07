<?php

namespace App\Domain\SitioWeb;

use App\Models\AjusteGlobal;
use Illuminate\Support\Collection;

/**
 * Datos estructurados (schema.org, JSON-LD) de cada página: Organization, WebSite, WebPage y
 * BreadcrumbList en todas; SoftwareApplication con los precios reales en inicio y precios. Las
 * preguntas frecuentes (FAQPage) las agrega el componente x-sitio.faq. Sin valoraciones ni
 * reseñas: Google penaliza las que no vienen de clientes reales.
 */
final class EsquemaJsonLd
{
    /** Módulos que se anuncian en featureList. */
    public const FUNCIONES = [
        'Facturación electrónica SIAT (computarizada y electrónica en línea)',
        'Punto de venta con lector de código de barras',
        'Pedidos, cotizaciones y devoluciones',
        'Control de inventario por sucursal',
        'Traspasos entre sucursales',
        'Compras y cuentas por pagar',
        'Ventas a crédito, abonos y cuentas por cobrar',
        'Caja por turno, arqueo y egresos',
        'Reportes de ventas, cobros e inventario',
        'Asistente de inteligencia artificial Mozi',
    ];

    /**
     * @param  Collection<int, array{codigo: string, nombre: string, siat: bool, precio: string, entero: string, centavos: string}>|null  $planes
     * @return array<string, mixed>
     */
    public static function grafo(Pagina $pagina, Seo $seo, AjusteGlobal $ajustes, ?Collection $planes = null): array
    {
        $inicio = CatalogoPaginas::urlAbsoluta('/');
        $organizacion = $inicio.'#organizacion';
        $sitio = $inicio.'#sitio';
        $nodos = [self::organizacion($organizacion, $inicio, $ajustes), [
            '@type' => 'WebSite',
            '@id' => $sitio,
            'url' => $inicio,
            'name' => 'MoziStock',
            'description' => 'Sistema de inventario, ventas, cobros y facturación SIAT para negocios en Bolivia.',
            'inLanguage' => 'es-BO',
            'publisher' => ['@id' => $organizacion],
        ]];

        $webPage = [
            '@type' => $pagina->tipo,
            '@id' => $seo->canonical.'#pagina',
            'url' => $seo->canonical,
            'name' => $seo->titulo,
            'description' => $seo->descripcion,
            'inLanguage' => 'es-BO',
            'isPartOf' => ['@id' => $sitio],
            'primaryImageOfPage' => ['@type' => 'ImageObject', 'url' => $seo->ogImagen],
        ];

        if ($pagina->clave === 'inicio') {
            $webPage['about'] = ['@id' => $organizacion];
        } else {
            $webPage['breadcrumb'] = ['@id' => $seo->canonical.'#migas'];
            $nodos[] = self::migas($pagina, $seo);
        }

        $nodos[] = $webPage;

        if ($planes !== null && $planes->isNotEmpty()) {
            $nodos[] = self::aplicacion($organizacion, $inicio, $planes);
        }

        return ['@context' => 'https://schema.org', '@graph' => $nodos];
    }

    /**
     * JSON seguro para incrustar dentro de <script>: escapa < > & y comillas.
     *
     * @param  array<string, mixed>  $datos
     */
    public static function json(array $datos): string
    {
        return (string) json_encode($datos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $preguntas
     * @return array<string, mixed>
     */
    public static function preguntas(array $preguntas): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn (array $par): array => [
                '@type' => 'Question',
                'name' => $par[0],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $par[1]],
            ], $preguntas),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function organizacion(string $id, string $inicio, AjusteGlobal $ajustes): array
    {
        $nodo = [
            '@type' => 'Organization',
            '@id' => $id,
            'name' => 'MoziStock',
            'url' => $inicio,
            'logo' => [
                '@type' => 'ImageObject',
                'url' => CatalogoPaginas::urlAbsoluta('img/sitio/logo-mozistock.png'),
                'width' => 512,
                'height' => 512,
            ],
            'description' => 'Sistema de gestión para comercios de Bolivia: inventario, ventas, créditos, caja, reportes y facturación SIAT.',
            'areaServed' => ['@type' => 'Country', 'name' => 'Bolivia'],
            'parentOrganization' => ['@type' => 'Organization', 'name' => 'MoziStudios'],
        ];
        $contacto = ['@type' => 'ContactPoint', 'contactType' => 'sales', 'areaServed' => 'BO', 'availableLanguage' => ['es']];

        if ($ajustes->sitio_whatsapp !== null) {
            $contacto['telephone'] = '+'.$ajustes->sitio_whatsapp;
        }

        if ($ajustes->correo_contacto !== null) {
            $contacto['email'] = $ajustes->correo_contacto;
            $nodo['email'] = $ajustes->correo_contacto;
        }

        if (count($contacto) > 4) {
            $nodo['contactPoint'] = [$contacto];
        }

        return $nodo;
    }

    /**
     * @return array<string, mixed>
     */
    private static function migas(Pagina $pagina, Seo $seo): array
    {
        $elementos = [];

        foreach (CatalogoPaginas::migas($pagina) as $posicion => $miga) {
            $elementos[] = [
                '@type' => 'ListItem',
                'position' => $posicion + 1,
                'name' => $miga->nombre,
                'item' => $miga->clave === $pagina->clave ? $seo->canonical : $miga->url(),
            ];
        }

        return ['@type' => 'BreadcrumbList', '@id' => $seo->canonical.'#migas', 'itemListElement' => $elementos];
    }

    /**
     * @param  Collection<int, array{codigo: string, nombre: string, siat: bool, precio: string, entero: string, centavos: string}>  $planes
     * @return array<string, mixed>
     */
    private static function aplicacion(string $organizacion, string $inicio, Collection $planes): array
    {
        $urlPrecios = CatalogoPaginas::buscar('precios')->url();

        return [
            '@type' => 'SoftwareApplication',
            '@id' => $inicio.'#aplicacion',
            'name' => 'MoziStock',
            'url' => $inicio,
            'applicationCategory' => 'BusinessApplication',
            'applicationSubCategory' => 'Sistema de inventario, punto de venta y facturación',
            'operatingSystem' => 'Web (Windows, macOS, Android, iOS)',
            'inLanguage' => 'es',
            'description' => 'Sistema web de inventario, ventas, cobros, caja, reportes y facturación SIAT para negocios en Bolivia.',
            'featureList' => self::FUNCIONES,
            'publisher' => ['@id' => $organizacion],
            'offers' => $planes->map(fn (array $plan): array => [
                '@type' => 'Offer',
                'name' => 'Plan '.$plan['nombre'],
                'price' => $plan['precio'],
                'priceCurrency' => 'BOB',
                'url' => $urlPrecios,
                'availability' => 'https://schema.org/InStock',
                'areaServed' => ['@type' => 'Country', 'name' => 'Bolivia'],
                'priceSpecification' => [
                    '@type' => 'UnitPriceSpecification',
                    'price' => $plan['precio'],
                    'priceCurrency' => 'BOB',
                    'unitText' => 'mes',
                    'referenceQuantity' => ['@type' => 'QuantitativeValue', 'value' => 1, 'unitCode' => 'MON'],
                ],
            ])->values()->all(),
        ];
    }
}
