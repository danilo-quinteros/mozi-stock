<?php

use App\Domain\SitioWeb\CatalogoPaginas;

beforeEach(function () {
    config(['app.url' => 'https://app.mozistock.test', 'mozistock.dominio_sitio' => 'mozistock.test']);
    $this->paginaSitio = collect(CatalogoPaginas::todas())->first(fn ($p) => $p->clave !== 'inicio')->ruta;
});

test('the main domain shows the public site and sends system pages to the subdomain', function () {
    $this->get('https://mozistock.test/')->assertOk();
    $this->get('https://mozistock.test/'.ltrim($this->paginaSitio, '/'))->assertOk();
    $this->get('https://mozistock.test/pedidos?estado=pendiente')->assertRedirect('https://app.mozistock.test/pedidos?estado=pendiente');
    $this->get('https://mozistock.test/login')->assertRedirect('https://app.mozistock.test/login');
    $this->get('https://mozistock.test/registro')->assertRedirect('https://app.mozistock.test/registro');
});

test('the subdomain opens the panel at its root and sends site pages back to the main domain', function () {
    $this->get('https://app.mozistock.test/')->assertRedirect('https://app.mozistock.test/dashboard');
    $this->get('https://app.mozistock.test/'.ltrim($this->paginaSitio, '/'))->assertRedirect('https://mozistock.test/'.ltrim($this->paginaSitio, '/'));
    $this->get('https://app.mozistock.test/login')->assertOk();
    $this->get('https://app.mozistock.test/robots.txt')->assertOk();
});

test('without a site domain (localhost) site and system share the same host as before', function () {
    config(['mozistock.dominio_sitio' => null, 'app.url' => 'http://localhost']);

    $this->get('http://localhost/')->assertOk();
    $this->get('http://localhost/login')->assertOk();
});
