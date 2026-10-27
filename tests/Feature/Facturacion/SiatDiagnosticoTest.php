<?php

use App\Providers\SiatServiceProvider;
use MoziStudios\Siat\ClienteSiat;
use MoziStudios\Siat\Configuracion\ConfiguracionSistema;
use MoziStudios\Siat\Excepciones\ErrorComunicacion;
use MoziStudios\Siat\Soap\SoapTransporte;
use MoziStudios\Siat\Soap\Transporte;
use MoziStudios\Siat\Testing\TransporteFalso;

/*
 * Fase 1 SIAT: el diagnóstico usa el NIT y el token de prueba de .env, no toca la base de datos
 * y nunca muestra el token. Sin red: el transporte del paquete se reemplaza por uno falso.
 */

const TOKEN_DIAGNOSTICO = 'token.de.prueba.SECRETO9876';

beforeEach(function () {
    config([
        'siat.codigo_sistema' => 'ABC123DEF456',
        'siat.ambiente' => 2,
        'siat.url_base' => ConfiguracionSistema::URL_PILOTO,
        'siat.pruebas.nit' => '123451020',
        'siat.pruebas.token' => TOKEN_DIAGNOSTICO,
    ]);

    $this->transporte = new TransporteFalso;
    $this->app->instance(Transporte::class, $this->transporte);
});

function comunicacionCorrecta(TransporteFalso $transporte): void
{
    $transporte
        ->responder(['RespuestaComunicacion' => ['transaccion' => true, 'mensajesList' => [['codigo' => 926, 'descripcion' => 'COMUNICACION EXITOSA']]]])
        ->responder(['return' => ['transaccion' => true]])
        ->responder(['return' => ['transaccion' => true]]);
}

test('el contenedor entrega un cliente SIAT configurado para el piloto', function () {
    $cliente = app(ClienteSiat::class);

    expect($cliente->configuracion()->codigoSistema)->toBe('ABC123DEF456')
        ->and($cliente->configuracion()->urlBase)->toBe(ConfiguracionSistema::URL_PILOTO)
        ->and(app(ClienteSiat::class))->toBe($cliente);
});

test('el transporte real usa SOAP y una caché de WSDL propia', function () {
    $this->app->forgetInstance(Transporte::class);
    $this->app->offsetUnset(Transporte::class);
    $this->app->register(SiatServiceProvider::class, true);

    expect(app(Transporte::class))->toBeInstanceOf(SoapTransporte::class)
        ->and(is_dir(storage_path('framework/cache/siat-wsdl')))->toBeTrue();
});

test('verifica la comunicación con los tres servicios sin mostrar el token', function () {
    comunicacionCorrecta($this->transporte);

    $this->artisan('mozistock:siat-diagnostico')
        ->expectsOutputToContain('NIT 123451020 · token …9876')
        ->expectsOutputToContain('926 COMUNICACION EXITOSA')
        ->doesntExpectOutputToContain('SECRETO')
        ->assertSuccessful();

    expect(array_column($this->transporte->llamadas, 'operacion'))->toBe(array_fill(0, 3, 'verificarComunicacion'))
        ->and(array_unique(array_column($this->transporte->llamadas, 'token')))->toBe(['…9876']);
});

test('solicita CUIS, hora oficial y CUFD del punto de emisión indicado', function () {
    $this->freezeTime();
    comunicacionCorrecta($this->transporte);
    $this->transporte
        ->responder(['RespuestaCuis' => ['codigo' => 'C2FD8E5A', 'fechaVigencia' => '2027-10-24T10:00:00.000-04:00', 'transaccion' => true]])
        ->responder(['RespuestaFechaHora' => ['fechaHora' => now('America/La_Paz')->format('Y-m-d\TH:i:s.v'), 'transaccion' => true]])
        ->responder(['RespuestaCufd' => [
            'codigo' => 'BQUE+QytqQUDBKVUFOSVRPQkxVRFZNVFVJBMDAwMDAwM',
            'codigoControl' => 'A19E23EF34124CD',
            'codigoInterno' => 'A',
            'fechaVigencia' => '2026-10-25T10:00:00.000-04:00',
            'transaccion' => true,
        ]]);

    $this->artisan('mozistock:siat-diagnostico', ['--cufd' => true, '--sucursal' => 1, '--punto-venta' => 2])
        ->expectsOutputToContain('CUIS sucursal 1 / PV 2: C2FD8E5A')
        // El SIN responde la hora de Bolivia sin zona; con la app en UTC no debe aparecer un desfase de 4 h.
        ->expectsOutputToContain('desfase del servidor: 0 s')
        ->expectsOutputToContain('código interno A')
        ->assertSuccessful();

    $cufd = $this->transporte->llamadas[5]['parametros']['SolicitudCufd'];

    expect($cufd['cuis'])->toBe('C2FD8E5A')
        ->and($cufd['codigoSucursal'])->toBe(1)
        ->and($cufd['codigoPuntoVenta'])->toBe(2);
});

test('una falla de comunicación termina con error y sin el token', function () {
    $this->transporte
        ->responder(new ErrorComunicacion('El SIN no respondió correctamente a FacturacionCodigos.verificarComunicacion: timeout'))
        ->responder(['return' => ['transaccion' => true]])
        ->responder(['return' => ['transaccion' => false, 'mensajesList' => [['codigo' => 995, 'descripcion' => 'Servicio no disponible']]]]);

    $this->artisan('mozistock:siat-diagnostico', ['--cufd' => true])
        ->expectsOutputToContain('timeout')
        ->expectsOutputToContain('RECHAZADO')
        ->doesntExpectOutputToContain('SECRETO')
        ->assertFailed();

    // Sin comunicación no se piden códigos.
    expect($this->transporte->llamadas)->toHaveCount(3);
});

test('explica qué variables faltan sin llamar al SIN', function () {
    config(['siat.pruebas.token' => null, 'siat.codigo_sistema' => '']);

    $this->artisan('mozistock:siat-diagnostico')
        ->expectsOutputToContain('SIAT_CODIGO_SISTEMA, SIAT_PRUEBAS_TOKEN')
        ->assertFailed();

    expect($this->transporte->llamadas)->toBe([]);
});

test('no se ejecuta contra producción', function () {
    config(['siat.ambiente' => 1]);

    $this->artisan('mozistock:siat-diagnostico')
        ->expectsOutputToContain('SIAT_AMBIENTE=2')
        ->assertFailed();

    expect($this->transporte->llamadas)->toBe([]);
});

test('una URL que no corresponde al ambiente se informa como error de configuración', function () {
    config(['siat.url_base' => 'https://siatrest.impuestos.gob.bo/sfd/']);

    $this->artisan('mozistock:siat-diagnostico')
        ->expectsOutputToContain('no es la del piloto')
        ->assertFailed();

    expect($this->transporte->llamadas)->toBe([]);
});
