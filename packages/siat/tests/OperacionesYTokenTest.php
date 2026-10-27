<?php

namespace MoziStudios\Siat\Tests;

use DateTimeImmutable;
use DateTimeZone;
use MoziStudios\Siat\ClienteSiat;
use MoziStudios\Siat\Configuracion\DatosToken;
use MoziStudios\Siat\Configuracion\PuntoEmision;
use MoziStudios\Siat\Configuracion\TokenDelegado;
use MoziStudios\Siat\Excepciones\DatosInvalidos;
use MoziStudios\Siat\Soap\Servicio;
use MoziStudios\Siat\Testing\TransporteFalso;
use PHPUnit\Framework\TestCase;

final class OperacionesYTokenTest extends TestCase
{
    /**
     * JWT con la forma del token delegado del piloto (firma ficticia).
     *
     * @param  array<string, mixed>  $reclamos
     */
    public static function jwt(array $reclamos): string
    {
        $parte = fn (array $datos): string => rtrim(strtr(base64_encode((string) json_encode($datos)), '+/', '-_'), '=');

        return $parte(['typ' => 'JWT', 'alg' => 'HS512']).'.'.$parte($reclamos).'.firmaFicticia_abc';
    }

    public function test_lee_nit_sistema_y_vencimiento_del_token(): void
    {
        $token = new TokenDelegado(self::jwt([
            'sub' => 'usuario',
            'codigoSistema' => '228BD776B70C0C6326DCF',
            'nit' => base64_encode((string) gzencode('6259206018')),
            'nitDelegado' => 1020703023,
            'exp' => 1824393207,
            'iat' => 1792871577,
        ]));
        $datos = DatosToken::leer($token);

        $this->assertNotNull($datos);
        $this->assertSame([1020703023, 6259206018], $datos->nits);
        $this->assertTrue($datos->incluyeNit(6259206018));
        $this->assertFalse($datos->incluyeNit(123));
        $this->assertSame('228BD776B70C0C6326DCF', $datos->codigoSistema);
        $this->assertSame('2027-10-24 15:53:27', $datos->vence?->format('Y-m-d H:i:s'));
        $this->assertSame('+00:00', $datos->vence->format('P'));
        $this->assertFalse($datos->vencido(new DateTimeImmutable('2027-10-24 11:53:26', new DateTimeZone('America/La_Paz'))));
        $this->assertTrue($datos->vencido(new DateTimeImmutable('2027-10-24 15:53:27', new DateTimeZone('UTC'))));
    }

    public function test_un_texto_que_no_es_jwt_o_con_reclamos_danados_no_rompe(): void
    {
        $this->assertNull(DatosToken::leer(new TokenDelegado('no-es-un-jwt')));
        $this->assertNull(DatosToken::leer(new TokenDelegado('a.@@@.c')));

        $danado = DatosToken::leer(new TokenDelegado(self::jwt(['nit' => base64_encode("\x1f\x8bbasura"), 'nitDelegado' => 'abc', 'exp' => 'mañana'])));
        $this->assertNotNull($danado);
        $this->assertSame([], $danado->nits);
        $this->assertNull($danado->vence);
        $this->assertNull($danado->codigoSistema);
    }

    private function cliente(TransporteFalso $transporte): ClienteSiat
    {
        return new ClienteSiat(Fabrica::configuracion(), $transporte);
    }

    public function test_registra_consulta_y_cierra_puntos_de_venta(): void
    {
        $transporte = (new TransporteFalso)
            ->responder(['RespuestaRegistroPuntoVenta' => ['codigoPuntoVenta' => 3, 'transaccion' => true]])
            ->responder(['RespuestaConsultaPuntoVenta' => ['transaccion' => true, 'listaPuntosVentas' => [
                ['codigoPuntoVenta' => 0, 'nombrePuntoVenta' => 'SIN PUNTO', 'tipoPuntoVenta' => 'PUNTO VENTA FIJO'],
                ['codigoPuntoVenta' => 3, 'nombrePuntoVenta' => 'Caja 2', 'tipoPuntoVenta' => 'PUNTO VENTA CAJEROS'],
            ]]])
            ->responder(['RespuestaCierrePuntoVenta' => ['codigoPuntoVenta' => 3, 'transaccion' => true]]);
        $servicio = $this->cliente($transporte)->operaciones(Fabrica::contribuyente());

        $registro = $servicio->registroPuntoVenta(1, 'C2FD8E5A', 5, 'Caja 2', 'Caja de la sucursal norte');
        $consulta = $servicio->consultaPuntoVenta(1, 'C2FD8E5A');
        $cierre = $servicio->cierrePuntoVenta(new PuntoEmision(1, 3), 'C2FD8E5A');

        $this->assertSame(3, $registro->codigoPuntoVenta);
        $this->assertSame([0, 3], array_map(fn ($p) => $p->codigo, $consulta->puntos));
        $this->assertSame('PUNTO VENTA CAJEROS', $consulta->puntos[1]->tipo);
        $this->assertTrue($cierre->transaccion);

        $this->assertSame(Servicio::Operaciones, $transporte->llamadas[0]['servicio']);
        $this->assertSame(['SolicitudRegistroPuntoVenta' => [
            'codigoAmbiente' => 2,
            'codigoModalidad' => 2,
            'codigoSistema' => 'ABC123DEF456',
            'codigoSucursal' => 1,
            'codigoTipoPuntoVenta' => 5,
            'cuis' => 'C2FD8E5A',
            'descripcion' => 'Caja de la sucursal norte',
            'nit' => 123451020,
            'nombrePuntoVenta' => 'Caja 2',
        ]], $transporte->llamadas[0]['parametros']);
        $this->assertSame(3, $transporte->llamadas[2]['parametros']['SolicitudCierrePuntoVenta']['codigoPuntoVenta']);
        $this->assertArrayNotHasKey('codigoModalidad', $transporte->llamadas[1]['parametros']['SolicitudConsultaPuntoVenta']);
    }

    public function test_el_punto_de_venta_0_no_se_cierra(): void
    {
        $transporte = new TransporteFalso;

        try {
            $this->cliente($transporte)->operaciones(Fabrica::contribuyente())->cierrePuntoVenta(new PuntoEmision(1, 0), 'C2FD8E5A');
            $this->fail('Debía rechazar el cierre del punto 0.');
        } catch (DatosInvalidos) {
            $this->assertSame([], $transporte->llamadas);
        }
    }

    public function test_el_nombre_del_punto_de_venta_es_obligatorio(): void
    {
        $this->expectException(DatosInvalidos::class);
        $this->cliente(new TransporteFalso)->operaciones(Fabrica::contribuyente())->registroPuntoVenta(1, 'C2FD8E5A', 5, '  ', 'x');
    }

    public function test_registra_y_consulta_eventos_significativos(): void
    {
        $transporte = (new TransporteFalso)
            ->responder(['RespuestaListaEventos' => [
                'transaccion' => true,
                'codigoRecepcionEventoSignificativo' => 880011,
                'mensajesList' => [['codigo' => 991, 'descripcion' => 'REGISTRO DE EVENTO CORRECTO']],
            ]])
            ->responder(['RespuestaListaEventos' => [
                'transaccion' => true,
                'listaCodigos' => [
                    [
                        'codigoEvento' => 1,
                        'codigoRecepcionEventoSignificativo' => 880011,
                        'descripcion' => 'CORTE DEL SERVICIO DE INTERNET',
                        'fechaInicio' => '2026-10-25T08:00:00.000',
                        'fechaFin' => '2026-10-25T09:30:00.000',
                    ],
                ],
                'mensajesList' => [],
            ]]);

        $servicio = $this->cliente($transporte)->operaciones(Fabrica::contribuyente());

        $inicio = new DateTimeImmutable('2026-10-25 08:00:00', new DateTimeZone('America/La_Paz'));
        $fin = new DateTimeImmutable('2026-10-25 09:30:00', new DateTimeZone('America/La_Paz'));

        $registro = $servicio->registroEventoSignificativo(
            sucursal: 0,
            puntoVenta: 1,
            cuis: 'C2FD8E5A',
            cufd: 'CUFDNUEVO123',
            cufdEvento: 'CUFDANTERIOR456',
            codigoMotivoEvento: 1,
            descripcion: 'Corte de fibra optica',
            fechaHoraInicio: $inicio,
            fechaHoraFin: $fin,
        );

        $this->assertTrue($registro->transaccion);
        $this->assertSame(880011, $registro->codigoRecepcionEventoSignificativo);
        $this->assertSame('991 REGISTRO DE EVENTO CORRECTO', (string) $registro->mensajes[0]);

        $this->assertSame(Servicio::Operaciones, $transporte->llamadas[0]['servicio']);
        $this->assertSame('registroEventoSignificativo', $transporte->llamadas[0]['operacion']);
        $this->assertSame(['SolicitudEventoSignificativo' => [
            'codigoAmbiente' => 2,
            'codigoMotivoEvento' => 1,
            'codigoPuntoVenta' => 1,
            'codigoSistema' => 'ABC123DEF456',
            'codigoSucursal' => 0,
            'cufd' => 'CUFDNUEVO123',
            'cufdEvento' => 'CUFDANTERIOR456',
            'cuis' => 'C2FD8E5A',
            'descripcion' => 'Corte de fibra optica',
            'fechaHoraFinEvento' => '2026-10-25T09:30:00.000',
            'fechaHoraInicioEvento' => '2026-10-25T08:00:00.000',
            'nit' => 123451020,
        ]], $transporte->llamadas[0]['parametros']);

        $consulta = $servicio->consultaEventoSignificativo(
            sucursal: 0,
            puntoVenta: 1,
            cuis: 'C2FD8E5A',
            cufd: 'CUFDNUEVO123',
            fechaEvento: $inicio,
        );

        $this->assertTrue($consulta->transaccion);
        $this->assertCount(1, $consulta->eventos);
        $this->assertSame(1, $consulta->eventos[0]->codigoEvento);
        $this->assertSame(880011, $consulta->eventos[0]->codigoRecepcionEventoSignificativo);
        $this->assertSame('CORTE DEL SERVICIO DE INTERNET', $consulta->eventos[0]->descripcion);

        $this->assertSame(Servicio::Operaciones, $transporte->llamadas[1]['servicio']);
        $this->assertSame('consultaEventoSignificativo', $transporte->llamadas[1]['operacion']);
        $this->assertSame(['SolicitudConsultaEvento' => [
            'codigoAmbiente' => 2,
            'codigoPuntoVenta' => 1,
            'codigoSistema' => 'ABC123DEF456',
            'codigoSucursal' => 0,
            'cufd' => 'CUFDNUEVO123',
            'cuis' => 'C2FD8E5A',
            'fechaEvento' => '2026-10-25T08:00:00.000',
            'nit' => 123451020,
        ]], $transporte->llamadas[1]['parametros']);
    }
}
