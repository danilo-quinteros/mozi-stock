<?php

namespace MoziStudios\Siat\Servicios;

use MoziStudios\Siat\Configuracion\PuntoEmision;
use MoziStudios\Siat\Respuestas\Lector;
use MoziStudios\Siat\Respuestas\RespuestaCatalogo;
use MoziStudios\Siat\Respuestas\RespuestaComunicacion;
use MoziStudios\Siat\Respuestas\RespuestaFechaHora;
use MoziStudios\Siat\Sincronizacion\Catalogo;
use MoziStudios\Siat\Soap\Servicio;

/** FacturacionSincronizacion V3: catálogos y fecha/hora oficial. */
final class ServicioSincronizacion extends ServicioSiat
{
    protected function servicio(): Servicio
    {
        return Servicio::Sincronizacion;
    }

    public function verificarComunicacion(): RespuestaComunicacion
    {
        return RespuestaComunicacion::desde(Lector::objeto($this->llamar('verificarComunicacion', []), 'return'));
    }

    public function catalogo(Catalogo $catalogo, PuntoEmision $punto, string $cuis): RespuestaCatalogo
    {
        $respuesta = $this->llamar($catalogo->value, $this->solicitud($punto, $cuis));

        return RespuestaCatalogo::desde($catalogo, Lector::objeto($respuesta, $catalogo->envoltorioRespuesta()));
    }

    public function fechaHora(PuntoEmision $punto, string $cuis): RespuestaFechaHora
    {
        $respuesta = $this->llamar('sincronizarFechaHora', $this->solicitud($punto, $cuis));

        return RespuestaFechaHora::desde(Lector::objeto($respuesta, 'RespuestaFechaHora'));
    }

    /**
     * @return array<string, array<string, int|string>>
     */
    private function solicitud(PuntoEmision $punto, string $cuis): array
    {
        return ['SolicitudSincronizacion' => [
            'codigoAmbiente' => $this->configuracion->ambiente->value,
            'codigoPuntoVenta' => $punto->puntoVenta,
            'codigoSistema' => $this->configuracion->codigoSistema,
            'codigoSucursal' => $punto->sucursal,
            'cuis' => self::codigo($cuis, 'cuis'),
            'nit' => $this->contribuyente->nit,
        ]];
    }
}
