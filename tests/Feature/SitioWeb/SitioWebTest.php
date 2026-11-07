<?php

use App\Domain\SitioWeb\CatalogoPaginas;
use App\Models\AjusteGlobal;
use App\Models\PaginaWeb;

/**
 * @return list<array<string, mixed>>
 */
function bloquesJsonLd(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $coincidencias);

    return array_map(fn (string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $coincidencias[1]);
}

/**
 * @param  list<array<string, mixed>>  $bloques
 * @return list<string>
 */
function tiposJsonLd(array $bloques): array
{
    $tipos = [];

    foreach ($bloques as $bloque) {
        foreach ($bloque['@graph'] ?? [$bloque] as $nodo) {
            $tipos[] = $nodo['@type'];
        }
    }

    return $tipos;
}

test('every catalog page renders with complete SEO tags and structured data', function (string $clave) {
    $pagina = CatalogoPaginas::buscar($clave);
    $html = $this->get(route($pagina->nombreRuta()))->assertOk()->getContent();
    $tipos = tiposJsonLd(bloquesJsonLd($html));

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('<html lang="es-BO"')
        ->toContain('<title>'.e($pagina->titulo).'</title>')
        ->toContain('<meta name="description" content="'.e($pagina->descripcion).'">')
        ->toContain('<link rel="canonical" href="'.$pagina->url().'">')
        ->toContain('<meta name="robots" content="index, follow')
        ->toContain('<meta property="og:url" content="'.$pagina->url().'">')
        ->toContain('<meta property="og:title" content="'.e($pagina->titulo).'">')
        ->toContain('<meta property="og:image" content="'.CatalogoPaginas::urlAbsoluta('img/sitio/og-mozistock.png').'">')
        ->toContain('<meta property="og:locale" content="es_BO">')
        ->toContain('<meta name="twitter:card" content="summary_large_image">')
        ->and($tipos)->toContain('Organization', 'WebSite', $pagina->tipo);

    if ($clave !== 'inicio') {
        expect($tipos)->toContain('BreadcrumbList');
    }
})->with(fn () => array_keys(CatalogoPaginas::todas()));

test('default SEO texts are unique, mention Bolivia where it matters and fit the database columns', function () {
    $paginas = CatalogoPaginas::todas();
    $titulos = array_map(fn ($p) => $p->titulo, $paginas);
    $descripciones = array_map(fn ($p) => $p->descripcion, $paginas);

    expect(array_unique($titulos))->toHaveCount(count($paginas))
        ->and(array_unique($descripciones))->toHaveCount(count($paginas));

    foreach ($paginas as $pagina) {
        expect(mb_strlen($pagina->titulo))->toBeLessThanOrEqual(70, $pagina->clave)
            ->and(mb_strlen($pagina->descripcion))->toBeLessThanOrEqual(170, $pagina->clave)
            ->and(mb_strlen($pagina->descripcion))->toBeGreaterThanOrEqual(100, $pagina->clave)
            ->and(mb_strlen($pagina->mensajeWhatsapp))->toBeLessThanOrEqual(300);

        if (in_array($pagina->grupo, ['principal', 'modulo', 'rubro'], true)) {
            expect($pagina->titulo.' '.$pagina->descripcion)->toContain('Bolivia');
        }
    }
});

test('home and pricing publish the real plan prices in BOB', function (string $clave) {
    $html = $this->get(route(CatalogoPaginas::buscar($clave)->nombreRuta()))->assertOk()->getContent();
    $app = collect(bloquesJsonLd($html)[0]['@graph'])->firstWhere('@type', 'SoftwareApplication');

    expect($app)->not->toBeNull()
        ->and(collect($app['offers'])->pluck('price')->all())->toBe(['150.00', '300.00'])
        ->and(collect($app['offers'])->pluck('priceCurrency')->unique()->all())->toBe(['BOB'])
        ->and($html)->toContain('<b>150</b>')->toContain('<b>300</b>');
})->with(['inicio', 'precios']);

test('pages with questions publish exactly one FAQPage', function (string $clave) {
    $html = $this->get(route(CatalogoPaginas::buscar($clave)->nombreRuta()))->assertOk()->getContent();
    $faq = collect(bloquesJsonLd($html))->where('@type', 'FAQPage');

    expect($faq)->toHaveCount(1)
        ->and($faq->first()['mainEntity'])->not->toBeEmpty();
})->with(['inicio', 'facturacion', 'precios', 'preguntas-frecuentes', 'rubro-farmacias']);

test('JSON-LD cannot be broken out of its script tag', function () {
    (new PaginaWeb)->forceFill(['clave' => 'inicio', 'titulo_seo' => 'Hola </script><script>alert(1)</script>'])->save();

    $html = $this->get(route('home'))->getContent();

    expect($html)->not->toContain('</script><script>alert(1)')
        ->and(bloquesJsonLd($html))->not->toBeEmpty();
});

test('superadmin overrides replace the defaults on the public page', function () {
    $fila = new PaginaWeb;
    $fila->forceFill([
        'clave' => 'facturacion',
        'titulo_seo' => 'Facturación SIAT en Bolivia, rápida y simple',
        'descripcion_seo' => 'Descripción personalizada.',
        'og_titulo' => 'Factura con el SIAT',
        'mensaje_whatsapp' => 'Quiero facturar con MoziStock',
    ])->save();
    $ajustes = AjusteGlobal::actual();
    $ajustes->sitio_whatsapp = '59171234567';
    $ajustes->sitio_verificacion_google = 'abcDEF123_-xyz';
    $ajustes->save();

    $this->get(route('sitio.facturacion'))->assertOk()
        ->assertSee('<title>Facturación SIAT en Bolivia, rápida y simple</title>', false)
        ->assertSee('<meta name="description" content="Descripción personalizada.">', false)
        ->assertSee('<meta property="og:title" content="Factura con el SIAT">', false)
        ->assertSee('<meta name="google-site-verification" content="abcDEF123_-xyz">', false)
        ->assertSee('https://wa.me/59171234567?text='.rawurlencode('Quiero facturar con MoziStock'), false);
});

test('without a WhatsApp number the buttons lead to the contact page', function () {
    $html = $this->get(route('home'))->getContent();

    expect($html)->not->toContain('wa.me/')
        ->and($html)->toContain('class="wa-fab" href="'.route('sitio.contacto').'"');
});

test('noindex pages say so and leave the sitemap', function () {
    $fila = new PaginaWeb;
    $fila->forceFill(['clave' => 'terminos', 'indexar' => false])->save();

    $this->get(route('sitio.terminos'))->assertSee('<meta name="robots" content="noindex, follow">', false);

    $xml = $this->get(route('sitio.sitemap'))->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->getContent();

    expect($xml)->not->toContain(CatalogoPaginas::buscar('terminos')->url())
        ->and(substr_count($xml, '<url>'))->toBe(count(CatalogoPaginas::todas()) - 1);
});

test('the sitemap lists every page with absolute URLs and is valid XML', function () {
    $xml = $this->get(route('sitio.sitemap'))->assertOk()->getContent();
    $documento = simplexml_load_string($xml);

    expect($documento)->not->toBeFalse()
        ->and(count($documento->url))->toBe(count(CatalogoPaginas::todas()));

    foreach (CatalogoPaginas::todas() as $pagina) {
        expect($xml)->toContain('<loc>'.$pagina->url().'</loc>');
    }
});

test('robots.txt points to the sitemap and keeps the private app out', function () {
    $this->get('/robots.txt')->assertOk()
        ->assertSee('Sitemap: '.CatalogoPaginas::urlAbsoluta('sitemap.xml'))
        ->assertSee('Disallow: /admin')
        ->assertDontSee('Disallow: /'."\n");
});

test('the private app and login pages are not indexable', function () {
    $this->get(route('login'))->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});

test('the public website does not exist in standalone mode', function () {
    config(['mozistock.deployment_mode' => 'standalone']);

    $this->get(route('sitio.precios'))->assertNotFound();
    $this->get(route('sitio.sitemap'))->assertNotFound();
});

test('the social image route only serves files from the site folder', function () {
    $this->get('/sitio/imagenes/..%2F..%2F.env')->assertNotFound();
    $this->get('/sitio/imagenes/'.str_repeat('a', 40).'.png')->assertNotFound();
});
