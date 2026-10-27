<?php

namespace MoziStudios\Siat\Servicios;

use MoziStudios\Siat\Configuracion\PuntoEmision;
use MoziStudios\Siat\Respuestas\Lector;
use MoziStudios\Siat\Respuestas\RespuestaComunicacion;
use MoziStudios\Siat\Respuestas\RespuestaCufd;
use MoziStudios\Siat\Respuestas\RespuestaCuis;
use MoziStudios\Siat\Respuestas\RespuestaVerificarNit;
use MoziStudios\Siat\Soap\Servicio;

/** FacturacionCodigos V3: comunicación, CUIS, CUFD y verificación de NIT. */
final class ServicioCodigos extends ServicioSiat
{
    protected function servicio(): Servicio
    {
        return Servicio::Codigos;
    }

    public function verificarComunicacion(): RespuestaComunicacion
    {
        return RespuestaComunicacion::desde(Lector::objeto($this->llamar('verificarComunicacion', []), 'RespuestaComunicacion'));
    }

    /** CUIS de la sucursal/punto de venta (vigencia larga; se pide al inicio o al vencer). */
    public function cuis(PuntoEmision $punto): RespuestaCuis
    {
        $respuesta = $this->llamar('cuis', ['SolicitudCuis' => [
            'codigoAmbiente' => $this->configuracion->ambiente->value,
            'codigoModalidad' => $this->configuracion->modalidad->value,
            'codigoPuntoVenta' => $punto->puntoVenta,
            'codigoSistema' => $this->configuracion->codigoSistema,
            'codigoSucursal' => $punto->sucursal,
            'nit' => $this->contribuyente->nit,
        ]]);

        return RespuestaCuis::desde(Lector::objeto($respuesta, 'RespuestaCuis'));
    }

    /** CUFD diario (vigencia de 24 horas) de la sucursal/punto de venta. */
    public function cufd(PuntoEmision $punto, string $cuis): RespuestaCufd
    {
        $respuesta = $this->llamar('cufd', ['SolicitudCufd' => [
            'codigoAmbiente' => $this->configuracion->ambiente->value,
            'codigoModalidad' => $this->configuracion->modalidad->value,
            'codigoPuntoVenta' => $punto->puntoVenta,
            'codigoSistema' => $this->configuracion->codigoSistema,
            'codigoSucursal' => $punto->sucursal,
            'cuis' => self::codigo($cuis, 'cuis'),
            'nit' => $this->contribuyente->nit,
        ]]);

        return RespuestaCufd::desde(Lector::objeto($respuesta, 'RespuestaCufd'));
    }

    public function verificarNit(PuntoEmision $punto, string $cuis, int $nitParaVerificacion): RespuestaVerificarNit
    {
        $respuesta = $this->llamar('verificarNit', ['SolicitudVerificarNit' => [
            'codigoAmbiente' => $this->configuracion->ambiente->value,
            'codigoModalidad' => $this->configuracion->modalidad->value,
            'codigoSistema' => $this->configuracion->codigoSistema,
            'codigoSucursal' => $punto->sucursal,
            'cuis' => self::codigo($cuis, 'cuis'),
            'nit' => $this->contribuyente->nit,
            'nitParaVerificacion' => $nitParaVerificacion,
        ]]);

        return RespuestaVerificarNit::desde(Lector::objeto($respuesta, 'RespuestaVerificarNit'));
    }
}
