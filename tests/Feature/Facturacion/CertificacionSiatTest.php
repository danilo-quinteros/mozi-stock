<?php

use App\Models\Empresa;
use App\Models\SiatCufd;
use App\Models\SiatCuis;
use App\Models\SiatFactura;
use App\Models\Venta;
use MoziStudios\Siat\Configuracion\ConfiguracionSistema;
use MoziStudios\Siat\Soap\Transporte;
use MoziStudios\Siat\Testing\TransporteFalso;

const TOKEN_CERT_SECRETO = 'token.delegado.SUPER_SECRETO_XYZ_98765';

beforeEach(function () {
    config([
        'siat.codigo_sistema' => '228BD776B70C0C6326DCF',
        'siat.ambiente' => 2,
        'siat.url_base' => ConfiguracionSistema::URL_PILOTO,
        'siat.pruebas.nit' => '6259206018',
        'siat.pruebas.token' => TOKEN_CERT_SECRETO,
    ]);

    $this->transporte = new TransporteFalso;
    $this->app->instance(Transporte::class, $this->transporte);

    // Limpiar estado de prueba si existe
    $estadoPath = storage_path('app/private/siat-certificacion/estado_testing.json');
    if (file_exists($estadoPath)) {
        @unlink($estadoPath);
    }
});

test('aborta fuera del piloto o si la url no apunta al piloto', function () {
    config(['siat.ambiente' => 1]);

    $this->artisan('mozistock:siat-certificacion', ['etapa' => 'cuis'])
        ->expectsOutputToContain('solo se ejecuta con SIAT_AMBIENTE=2')
        ->assertFailed();

    config([
        'siat.ambiente' => 2,
        'siat.url_base' => 'https://siatservicios.impuestos.gob.bo/v2/',
    ]);

    $this->artisan('mozistock:siat-certificacion', ['etapa' => 'cuis'])
        ->expectsOutputToContain('no apunta al piloto del SIN')
        ->assertFailed();
});

test('no escribe en la base de datos', function () {
    $ventasIniciales = Venta::query()->count();
    $facturasIniciales = SiatFactura::query()->count();
    $cuisIniciales = SiatCuis::query()->count();
    $cufdIniciales = SiatCufd::query()->count();
    $empresasIniciales = Empresa::query()->count();

    $this->artisan('mozistock:siat-certificacion', [
        'etapa' => 'iv',
        '--ensayo' => true,
        '--cantidad' => 2,
        '--punto-venta' => 0,
    ])->assertSuccessful();

    expect(Venta::query()->count())->toBe($ventasIniciales)
        ->and(SiatFactura::query()->count())->toBe($facturasIniciales)
        ->and(SiatCuis::query()->count())->toBe($cuisIniciales)
        ->and(SiatCufd::query()->count())->toBe($cufdIniciales)
        ->and(Empresa::query()->count())->toBe($empresasIniciales);
});

test('no repite numeros al reanudar la emision', function () {
    $this->artisan('mozistock:siat-certificacion', [
        'etapa' => 'iv',
        '--ensayo' => true,
        '--cantidad' => 2,
        '--punto-venta' => 0,
    ])->assertSuccessful();

    $estado1 = json_decode((string) file_get_contents(storage_path('app/private/siat-certificacion/estado_testing.json')), true);
    expect($estado1['ultimo_numero'][0])->toBe(700002);

    $this->artisan('mozistock:siat-certificacion', [
        'etapa' => 'iv',
        '--ensayo' => true,
        '--cantidad' => 2,
        '--punto-venta' => 0,
        '--reanudar' => true,
    ])->assertSuccessful();

    $estado2 = json_decode((string) file_get_contents(storage_path('app/private/siat-certificacion/estado_testing.json')), true);
    expect($estado2['ultimo_numero'][0])->toBe(700004);
});

test('el token delegado nunca aparece en ningun archivo generado', function () {
    $this->artisan('mozistock:siat-certificacion', [
        'etapa' => 'iv',
        '--ensayo' => true,
        '--cantidad' => 2,
        '--punto-venta' => 0,
    ])->assertSuccessful();

    $archivosPrivados = glob(storage_path('app/private/siat-certificacion/**/*.*'));
    $archivosEvidencias = glob(base_path('../docs/evidencias/siat-certificacion/*.csv'));
    $todosLosArchivos = array_merge($archivosPrivados ?: [], $archivosEvidencias ?: []);

    expect($todosLosArchivos)->not->toBeEmpty();

    foreach ($todosLosArchivos as $archivo) {
        if (is_file($archivo)) {
            $contenido = (string) file_get_contents($archivo);
            expect($contenido)->not->toContain(TOKEN_CERT_SECRETO);
        }
    }
});
