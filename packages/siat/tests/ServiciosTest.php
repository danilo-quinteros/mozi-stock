<?php

namespace MoziStudios\Siat\Tests;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use MoziStudios\Siat\Algoritmos\Archivo;
use MoziStudios\Siat\ClienteSiat;
use MoziStudios\Siat\Configuracion\Contribuyente;
use MoziStudios\Siat\Configuracion\PuntoEmision;
use MoziStudios\Siat\Configuracion\TokenDelegado;
use MoziStudios\Siat\Constantes\TipoEmision;
use MoziStudios\Siat\Excepciones\DatosInvalidos;
use MoziStudios\Siat\Excepciones\ErrorComunicacion;
use MoziStudios\Siat\Fecha\Reloj;
use MoziStudios\Siat\Respuestas\RespuestaRecepcion;
use MoziStudios\Siat\Servicios\ContextoDocumento;
use MoziStudios\Siat\Sincronizacion\Catalogo;
use MoziStudios\Siat\Soap\Servicio;
use MoziStudios\Siat\Testing\TransporteFalso;
use PHPUnit\Framework\TestCase;

final class ServiciosTest extends TestCase
{
    private TransporteFalso $transporte;

    protected function setUp(): void
    {
        $this->transporte = new TransporteFalso;
    }

    private function cliente(): ClienteSiat
    {
        $reloj = new class implements Reloj
        {
            public function ahora(): DateTimeImmutable
            {
                return new DateTimeImmutable('2027-06-01 09:20:00.123456', new DateTimeZone('America/La_Paz'));
            }
        };

        return new ClienteSiat(Fabrica::configuracion(), $this->transporte, $reloj);
    }

    /**
     * @return array<string, mixed>
     */
    private function parametros(int $indice = 0): array
    {
        return $this->transporte->llamadas[$indice]['parametros'];
    }

    public function test_cuis_envia_la_solicitud_v3_y_lee_la_respuesta(): void
    {
        $this->transporte->responder(['RespuestaCuis' => [
            'codigo' => 'C2FD8E5A',
            'fechaVigencia' => '2028-06-01T09:00:00.000-04:00',
            'transaccion' => true,
        ]]);

        $respuesta = $this->cliente()->codigos(Fabrica::contribuyente())->cuis(new PuntoEmision(1, 2));

        $this->assertTrue($respuesta->transaccion);
        $this->assertSame('C2FD8E5A', $respuesta->codigo);
        $this->assertSame('2028-06-01', $respuesta->fechaVigencia?->format('Y-m-d'));
        $this->assertSame(Servicio::Codigos, $this->transporte->llamadas[0]['servicio']);
        $this->assertSame('cuis', $this->transporte->llamadas[0]['operacion']);
        $this->assertSame(['SolicitudCuis' => [
            'codigoAmbiente' => 2,
            'codigoModalidad' => 2,
            'codigoPuntoVenta' => 2,
            'codigoSistema' => 'ABC123DEF456',
            'codigoSucursal' => 1,
            'nit' => 123451020,
        ]], $this->parametros());
    }

    public function test_cufd_v3_entrega_codigo_interno_y_mensajes_como_objeto_unico_o_lista(): void
    {
        $this->transporte
            ->responder(['RespuestaCufd' => [
                'codigo' => 'BQUE+QytqQUDBKVUFOSVRPQkxVRFZNVFVJBMDAwMDAwM',
                'codigoControl' => 'A19E23EF34124CD',
                'codigoInterno' => 'A',
                'direccion' => 'Av. Juan XXIII',
                'fechaVigencia' => '2027-06-02T09:00:00.000-04:00',
                'transaccion' => true,
                'mensajesList' => ['codigo' => 994, 'descripcion' => 'Aviso único'],
            ]])
            ->responder(['RespuestaCufd' => [
                'transaccion' => false,
                'mensajesList' => [['codigo' => 980, 'descripcion' => 'CUIS inválido'], ['codigo' => 2, 'descripcion' => 'Otro']],
            ]]);

        $servicio = $this->cliente()->codigos(Fabrica::contribuyente());
        $correcta = $servicio->cufd(new PuntoEmision, 'C2FD8E5A');
        $fallida = $servicio->cufd(new PuntoEmision, 'C2FD8E5A');

        $this->assertSame('A', $correcta->cufd?->codigoInterno);
        $this->assertSame('A19E23EF34124CD', $correcta->cufd->codigoControl);
        $this->assertSame('994 Aviso único', (string) $correcta->mensajes[0]);
        $this->assertSame('C2FD8E5A', $this->parametros()['SolicitudCufd']['cuis']);

        $this->assertFalse($fallida->transaccion);
        $this->assertNull($fallida->cufd);
        $this->assertSame([980, 2], array_map(fn ($m) => $m->codigo, $fallida->mensajes));
    }

    public function test_una_respuesta_sin_el_envoltorio_esperado_es_error_de_comunicacion(): void
    {
        $this->transporte->responder(['otraCosa' => []]);

        $this->expectException(ErrorComunicacion::class);
        $this->cliente()->codigos(Fabrica::contribuyente())->verificarComunicacion();
    }

    public function test_rechaza_codigos_con_caracteres_inyectados(): void
    {
        $this->expectException(DatosInvalidos::class);
        $this->cliente()->codigos(Fabrica::contribuyente())->cufd(new PuntoEmision, 'C2FD</cuis><nit>1');
    }

    public function test_sincroniza_catalogos_con_su_envoltorio_y_lista(): void
    {
        $this->transporte
            ->responder(['RespuestaListaProductos' => [
                'transaccion' => true,
                'listaCodigos' => [
                    ['codigoActividad' => '4752100', 'codigoProducto' => '1001912', 'descripcionProducto' => 'GANCHOS', 'nandina' => []],
                ],
            ]])
            ->responder(['RespuestaListaParametricasLeyendas' => [
                'transaccion' => true,
                'listaLeyendas' => ['codigoActividad' => '4752100', 'descripcionLeyenda' => 'Ley N° 453: ...'],
            ]])
            ->responder(['RespuestaListaParametricas' => [
                'transaccion' => true,
                'listaCodigos' => [['codigoClasificador' => 1, 'descripcion' => 'EFECTIVO'], ['codigoClasificador' => 2, 'descripcion' => 'TARJETA']],
            ]]);

        $servicio = $this->cliente()->sincronizacion(Fabrica::contribuyente());
        $productos = $servicio->catalogo(Catalogo::ProductosServicios, new PuntoEmision, 'C2FD8E5A');
        $leyendas = $servicio->catalogo(Catalogo::LeyendasFactura, new PuntoEmision, 'C2FD8E5A');
        $metodos = $servicio->catalogo(Catalogo::TiposMetodoPago, new PuntoEmision, 'C2FD8E5A');

        $this->assertSame('1001912', $productos->items[0]->codigo);
        $this->assertSame(['codigoActividad' => '4752100', 'nandina' => ''], $productos->items[0]->atributos);
        $this->assertCount(1, $leyendas->items);
        $this->assertSame('4752100', $leyendas->items[0]->codigo);
        $this->assertSame(['1', '2'], array_map(fn ($i) => $i->codigo, $metodos->items));
        $this->assertSame('sincronizarListaProductosServicios', $this->transporte->llamadas[0]['operacion']);
        $this->assertSame(Servicio::Sincronizacion, $this->transporte->llamadas[2]['servicio']);
        $this->assertArrayNotHasKey('codigoModalidad', $this->parametros()['SolicitudSincronizacion']);
    }

    public function test_recepcion_envia_archivo_hash_y_fecha_de_envio_con_milisegundos(): void
    {
        $this->transporte->responder(['RespuestaServicioFacturacion' => [
            'codigoDescripcion' => 'VALIDADA',
            'codigoEstado' => 908,
            'codigoRecepcion' => 'b3a9f6c1-0000',
            'transaccion' => true,
        ]]);

        $cliente = $this->cliente();
        $preparada = $cliente->preparador()->preparar(Fabrica::factura());
        $respuesta = $cliente->compraVenta(Fabrica::contribuyente())->recepcionFactura($preparada, 'C2FD8E5A');
        $solicitud = $this->parametros()['SolicitudServicioRecepcionFactura'];

        $this->assertTrue($respuesta->validada());
        $this->assertFalse($respuesta->observada());
        $this->assertSame('b3a9f6c1-0000', $respuesta->codigoRecepcion);
        $this->assertSame($preparada->archivo, $solicitud['archivo']);
        $this->assertSame($preparada->hashArchivo, $solicitud['hashArchivo']);
        $this->assertSame('2027-06-01T09:20:00.123', $solicitud['fechaEnvio']);
        $this->assertSame(Fabrica::cufd()->codigo, $solicitud['cufd']);
        $this->assertSame(1, $solicitud['codigoSucursal']);
        $this->assertSame(1, $solicitud['codigoDocumentoSector']);
        $this->assertSame(1, $solicitud['codigoEmision']);
        $this->assertSame(1, $solicitud['tipoFacturaDocumento']);
        $this->assertSame(123451020, $solicitud['nit']);
    }

    public function test_una_empresa_no_puede_enviar_la_factura_de_otro_nit(): void
    {
        $cliente = $this->cliente();
        $preparada = $cliente->preparador()->preparar(Fabrica::factura());

        try {
            $cliente->compraVenta(Fabrica::contribuyente(999888777))->recepcionFactura($preparada, 'C2FD8E5A');
            $this->fail('Debía rechazar la factura de otro NIT.');
        } catch (DatosInvalidos) {
            $this->assertSame([], $this->transporte->llamadas);
        }
    }

    public function test_estado_y_anulacion_usan_el_contexto_indicado(): void
    {
        $this->transporte
            ->responder(['RespuestaServicioFacturacion' => ['codigoEstado' => 904, 'transaccion' => true, 'mensajesList' => [['codigo' => 1001, 'descripcion' => 'Observación', 'advertencia' => true, 'numeroDetalle' => 2]]]])
            ->responder(['RespuestaServicioFacturacion' => ['codigoEstado' => 905, 'codigoDescripcion' => 'ANULACION CONFIRMADA', 'transaccion' => true]]);

        $servicio = $this->cliente()->compraVenta(Fabrica::contribuyente());
        $contexto = new ContextoDocumento(new PuntoEmision(1, 0), 'C2FD8E5A', 'CUFDNUEVO123');
        $estado = $servicio->verificacionEstado($contexto, '8727F63A15F8976591FDDE5B387C5D015A29E06A1A19E23EF34124CD');
        $anulacion = $servicio->anulacion($contexto, '8727F63A15F8976591FDDE5B387C5D015A29E06A1A19E23EF34124CD', 1);

        $this->assertTrue($estado->observada());
        $this->assertTrue($estado->mensajes[0]->advertencia);
        $this->assertSame(2, $estado->mensajes[0]->numeroDetalle);
        $this->assertSame(905, $anulacion->codigoEstado);
        $this->assertSame('verificacionEstadoFactura', $this->transporte->llamadas[0]['operacion']);
        $this->assertSame('CUFDNUEVO123', $this->parametros()['SolicitudServicioVerificacionEstadoFactura']['cufd']);
        $this->assertSame(1, $this->parametros(1)['SolicitudServicioAnulacionFactura']['codigoMotivo']);
        $this->assertSame(RespuestaRecepcion::ESTADO_OBSERVADA, $estado->codigoEstado);
    }

    public function test_cada_empresa_usa_su_propio_token_y_el_token_nunca_va_en_los_parametros(): void
    {
        $this->transporte
            ->responder(['RespuestaComunicacion' => ['transaccion' => true]])
            ->responder(['RespuestaComunicacion' => ['transaccion' => true]]);

        $cliente = $this->cliente();
        $empresaA = new Contribuyente(111, new TokenDelegado('token-de-A-AAAA'));
        $empresaB = new Contribuyente(222, new TokenDelegado('token-de-B-BBBB'));

        $this->assertTrue($cliente->codigos($empresaA)->verificarComunicacion()->transaccion);
        $this->assertTrue($cliente->codigos($empresaB)->verificarComunicacion()->transaccion);

        $this->assertSame(['…AAAA', '…BBBB'], array_column($this->transporte->llamadas, 'token'));
        $this->assertStringNotContainsString('token-de', (string) json_encode($this->transporte->llamadas));
    }

    /**
     * Regresión del piloto (24/10/2026): sincronizarFechaHora devuelve «2026-10-24T15:55:28.914» sin zona.
     * Con PHP en UTC (Laravel) se leía como 15:55 UTC y el diagnóstico mostraba un desfase falso de 4 horas.
     */
    public function test_las_fechas_sin_zona_del_sin_se_leen_en_hora_de_bolivia(): void
    {
        $zonaAnterior = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            $this->transporte
                ->responder(['RespuestaFechaHora' => ['fechaHora' => '2026-10-24T15:55:28.914', 'transaccion' => true]])
                ->responder(['RespuestaCuis' => ['codigo' => 'C2FD8E5A', 'fechaVigencia' => '2027-10-24T15:55:05.000-04:00', 'transaccion' => true]]);

            $hora = $this->cliente()->sincronizacion(Fabrica::contribuyente())->fechaHora(new PuntoEmision, 'C2FD8E5A');
            $cuis = $this->cliente()->codigos(Fabrica::contribuyente())->cuis(new PuntoEmision);
        } finally {
            date_default_timezone_set($zonaAnterior);
        }

        $this->assertSame('2026-10-24T15:55:28.914', $hora->texto);
        $this->assertSame('2026-10-24 19:55:28.914', $hora->fechaHora?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'));
        $this->assertSame('2027-10-24T19:55:05+00:00', $cuis->fechaVigencia?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM));
    }

    public function test_recepcion_y_validacion_de_paquetes(): void
    {
        $this->transporte
            ->responder(['RespuestaServicioFacturacion' => [
                'transaccion' => true,
                'codigoEstado' => RespuestaRecepcion::ESTADO_PENDIENTE,
                'codigoRecepcion' => 'REC-PAQ-123',
                'codigoDescripcion' => 'PENDIENTE',
            ]])
            ->responder(['RespuestaServicioFacturacion' => [
                'transaccion' => true,
                'codigoEstado' => RespuestaRecepcion::ESTADO_VALIDADA,
                'codigoRecepcion' => 'REC-PAQ-123',
                'codigoDescripcion' => 'VALIDADA',
            ]]);

        $tarGz = Archivo::tarGz(['factura_1.xml' => '<factura>1</factura>', 'factura_2.xml' => '<factura>2</factura>']);
        $contexto = new ContextoDocumento(
            punto: new PuntoEmision(0, 1),
            cuis: 'C2FD8E5A',
            cufd: 'CUFD999',
            tipoEmision: TipoEmision::FueraDeLinea,
        );

        $servicio = $this->cliente()->compraVenta(Fabrica::contribuyente());
        $recepcion = $servicio->recepcionPaquete(
            contexto: $contexto,
            archivoTarGz: $tarGz,
            cantidadFacturas: 2,
            codigoEvento: 880011,
            cafc: 'CAFC12345',
        );

        $this->assertTrue($recepcion->transaccion);
        $this->assertTrue($recepcion->pendiente());
        $this->assertSame('REC-PAQ-123', $recepcion->codigoRecepcion);

        $this->assertSame(Servicio::CompraVenta, $this->transporte->llamadas[0]['servicio']);
        $this->assertSame('recepcionPaqueteFactura', $this->transporte->llamadas[0]['operacion']);
        $solicitudRecepcion = $this->transporte->llamadas[0]['parametros']['SolicitudServicioRecepcionPaquete'];
        $this->assertSame(2, $solicitudRecepcion['codigoEmision']);
        $this->assertSame(1, $solicitudRecepcion['codigoPuntoVenta']);
        $this->assertSame(0, $solicitudRecepcion['codigoSucursal']);
        $this->assertSame(2, $solicitudRecepcion['cantidadFacturas']);
        $this->assertSame(880011, $solicitudRecepcion['codigoEvento']);
        $this->assertSame('CAFC12345', $solicitudRecepcion['cafc']);
        $this->assertSame(Archivo::sha256($tarGz), $solicitudRecepcion['hashArchivo']);
        $this->assertSame('2027-06-01T09:20:00.123', $solicitudRecepcion['fechaEnvio']);

        $validacion = $servicio->validacionRecepcionPaquete($contexto, 'REC-PAQ-123');
        $this->assertTrue($validacion->transaccion);
        $this->assertTrue($validacion->validada());

        $this->assertSame(Servicio::CompraVenta, $this->transporte->llamadas[1]['servicio']);
        $this->assertSame('validacionRecepcionPaqueteFactura', $this->transporte->llamadas[1]['operacion']);
        $this->assertSame('REC-PAQ-123', $this->transporte->llamadas[1]['parametros']['SolicitudServicioValidacionRecepcionPaquete']['codigoRecepcion']);
    }

    public function test_recepcion_paquete_rechaza_cantidad_cero(): void
    {
        $contexto = new ContextoDocumento(new PuntoEmision, 'C2FD8E5A', 'CUFD999');
        $this->expectException(DatosInvalidos::class);
        $this->cliente()->compraVenta(Fabrica::contribuyente())->recepcionPaquete($contexto, 'dummy', 0, 1);
    }

    public function test_el_transporte_falso_falla_si_no_hay_respuesta_preparada(): void
    {
        $this->expectException(LogicException::class);
        $this->cliente()->codigos(Fabrica::contribuyente())->verificarComunicacion();
    }
}
