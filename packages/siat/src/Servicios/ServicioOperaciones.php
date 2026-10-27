<?php

namespace MoziStudios\Siat\Servicios;

use DateTimeInterface;
use MoziStudios\Siat\Configuracion\PuntoEmision;
use MoziStudios\Siat\Excepciones\DatosInvalidos;
use MoziStudios\Siat\Factura\Texto;
use MoziStudios\Siat\Fecha\FechaSiat;
use MoziStudios\Siat\Respuestas\Lector;
use MoziStudios\Siat\Respuestas\RespuestaComunicacion;
use MoziStudios\Siat\Respuestas\RespuestaConsultaPuntoVenta;
use MoziStudios\Siat\Respuestas\RespuestaListaEventos;
use MoziStudios\Siat\Respuestas\RespuestaPuntoVenta;
use MoziStudios\Siat\Soap\Servicio;

/**
 * FacturacionOperaciones V3, puntos de venta: registro, consulta y cierre. Se opera con el CUIS
 * de la sucursal (punto de venta 0). Eventos significativos se agregan con la contingencia.
 */
final class ServicioOperaciones extends ServicioSiat
{
    protected function servicio(): Servicio
    {
        return Servicio::Operaciones;
    }

    public function verificarComunicacion(): RespuestaComunicacion
    {
        return RespuestaComunicacion::desde(Lector::objeto($this->llamar('verificarComunicacion', []), 'return'));
    }

    /**
     * @param  int  $codigoTipoPuntoVenta  del catálogo TiposPuntoVenta sincronizado
     */
    public function registroPuntoVenta(int $sucursal, string $cuis, int $codigoTipoPuntoVenta, string $nombre, string $descripcion): RespuestaPuntoVenta
    {
        $respuesta = $this->llamar('registroPuntoVenta', ['SolicitudRegistroPuntoVenta' => [
            'codigoAmbiente' => $this->configuracion->ambiente->value,
            'codigoModalidad' => $this->configuracion->modalidad->value,
            'codigoSistema' => $this->configuracion->codigoSistema,
            'codigoSucursal' => self::sucursal($sucursal),
            'codigoTipoPuntoVenta' => $codigoTipoPuntoVenta,
            'cuis' => self::codigo($cuis, 'cuis'),
            'descripcion' => Texto::requerido($descripcion, 'descripcion'),
            'nit' => $this->contribuyente->nit,
            'nombrePuntoVenta' => Texto::requerido($nombre, 'nombrePuntoVenta'),
        ]]);

        return RespuestaPuntoVenta::desde(Lector::objeto($respuesta, 'RespuestaRegistroPuntoVenta'));
    }

    public function consultaPuntoVenta(int $sucursal, string $cuis): RespuestaConsultaPuntoVenta
    {
        $respuesta = $this->llamar('consultaPuntoVenta', ['SolicitudConsultaPuntoVenta' => [
            'codigoAmbiente' => $this->configuracion->ambiente->value,
            'codigoSistema' => $this->configuracion->codigoSistema,
            'codigoSucursal' => self::sucursal($sucursal),
            'cuis' => self::codigo($cuis, 'cuis'),
            'nit' => $this->contribuyente->nit,
        ]]);

        return RespuestaConsultaPuntoVenta::desde(Lector::objeto($respuesta, 'RespuestaConsultaPuntoVenta'));
    }

    /** El punto de venta 0 no se cierra: representa a la sucursal. */
    public function cierrePuntoVenta(PuntoEmision $punto, string $cuis): RespuestaPuntoVenta
    {
        if ($punto->puntoVenta === 0) {
            throw new DatosInvalidos('El punto de venta 0 no se puede cerrar.');
        }

        $respuesta = $this->llamar('cierrePuntoVenta', ['SolicitudCierrePuntoVenta' => [
            'codigoAmbiente' => $this->configuracion->ambiente->value,
            'codigoPuntoVenta' => $punto->puntoVenta,
            'codigoSistema' => $this->configuracion->codigoSistema,
            'codigoSucursal' => $punto->sucursal,
            'cuis' => self::codigo($cuis, 'cuis'),
            'nit' => $this->contribuyente->nit,
        ]]);

        return RespuestaPuntoVenta::desde(Lector::objeto($respuesta, 'RespuestaCierrePuntoVenta'));
    }

    public function registroEventoSignificativo(
        int $sucursal,
        int $puntoVenta,
        string $cuis,
        string $cufd,
        string $cufdEvento,
        int $codigoMotivoEvento,
        string $descripcion,
        DateTimeInterface $fechaHoraInicio,
        DateTimeInterface $fechaHoraFin,
    ): RespuestaListaEventos {
        $respuesta = $this->llamar('registroEventoSignificativo', ['SolicitudEventoSignificativo' => [
            'codigoAmbiente' => $this->configuracion->ambiente->value,
            'codigoMotivoEvento' => $codigoMotivoEvento,
            'codigoPuntoVenta' => $puntoVenta,
            'codigoSistema' => $this->configuracion->codigoSistema,
            'codigoSucursal' => self::sucursal($sucursal),
            'cufd' => self::codigo($cufd, 'cufd'),
            'cufdEvento' => self::codigo($cufdEvento, 'cufdEvento'),
            'cuis' => self::codigo($cuis, 'cuis'),
            'descripcion' => Texto::requerido($descripcion, 'descripcion'),
            'fechaHoraFinEvento' => FechaSiat::desde($fechaHoraFin)->xml(),
            'fechaHoraInicioEvento' => FechaSiat::desde($fechaHoraInicio)->xml(),
            'nit' => $this->contribuyente->nit,
        ]]);

        return RespuestaListaEventos::desde(Lector::objeto($respuesta, 'RespuestaListaEventos'));
    }

    public function consultaEventoSignificativo(
        int $sucursal,
        int $puntoVenta,
        string $cuis,
        string $cufd,
        DateTimeInterface $fechaEvento,
    ): RespuestaListaEventos {
        $respuesta = $this->llamar('consultaEventoSignificativo', ['SolicitudConsultaEvento' => [
            'codigoAmbiente' => $this->configuracion->ambiente->value,
            'codigoPuntoVenta' => $puntoVenta,
            'codigoSistema' => $this->configuracion->codigoSistema,
            'codigoSucursal' => self::sucursal($sucursal),
            'cufd' => self::codigo($cufd, 'cufd'),
            'cuis' => self::codigo($cuis, 'cuis'),
            'fechaEvento' => FechaSiat::desde($fechaEvento)->xml(),
            'nit' => $this->contribuyente->nit,
        ]]);

        return RespuestaListaEventos::desde(Lector::objeto($respuesta, 'RespuestaListaEventos'));
    }

    private static function sucursal(int $codigo): int
    {
        return (new PuntoEmision($codigo))->sucursal;
    }
}
