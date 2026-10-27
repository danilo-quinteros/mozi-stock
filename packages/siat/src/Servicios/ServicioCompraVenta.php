<?php

namespace MoziStudios\Siat\Servicios;

use MoziStudios\Siat\Algoritmos\Archivo;
use MoziStudios\Siat\Configuracion\ConfiguracionSistema;
use MoziStudios\Siat\Configuracion\Contribuyente;
use MoziStudios\Siat\Excepciones\DatosInvalidos;
use MoziStudios\Siat\Factura\FacturaPreparada;
use MoziStudios\Siat\Fecha\FechaSiat;
use MoziStudios\Siat\Fecha\Reloj;
use MoziStudios\Siat\Respuestas\Lector;
use MoziStudios\Siat\Respuestas\RespuestaComunicacion;
use MoziStudios\Siat\Respuestas\RespuestaRecepcion;
use MoziStudios\Siat\Soap\Servicio;
use MoziStudios\Siat\Soap\Transporte;

/**
 * ServicioFacturacionCompraVenta V3, emisión individual: recepción, verificación de estado,
 * anulación y reversión de anulación. Paquetes de contingencia y emisión masiva se agregan en su fase.
 */
final class ServicioCompraVenta extends ServicioSiat
{
    public function __construct(
        ConfiguracionSistema $configuracion,
        Transporte $transporte,
        Contribuyente $contribuyente,
        private readonly Reloj $reloj,
    ) {
        parent::__construct($configuracion, $transporte, $contribuyente);
    }

    protected function servicio(): Servicio
    {
        return Servicio::CompraVenta;
    }

    public function verificarComunicacion(): RespuestaComunicacion
    {
        return RespuestaComunicacion::desde(Lector::objeto($this->llamar('verificarComunicacion', []), 'return'));
    }

    /**
     * Envía una factura preparada. Debe ser del mismo NIT que el contribuyente de este servicio.
     */
    public function recepcionFactura(FacturaPreparada $preparada, string $cuis): RespuestaRecepcion
    {
        $factura = $preparada->factura;

        if ($factura->nitEmisor !== $this->contribuyente->nit) {
            throw new DatosInvalidos('La factura es de otro NIT que el contribuyente que la envía.');
        }

        $contexto = new ContextoDocumento($factura->punto, $cuis, $factura->cufd->codigo, $factura->tipoEmision);
        $respuesta = $this->llamar('recepcionFactura', ['SolicitudServicioRecepcionFactura' => $this->base($contexto) + [
            'archivo' => $preparada->archivo,
            'fechaEnvio' => FechaSiat::desde($this->reloj->ahora())->xml(),
            'hashArchivo' => $preparada->hashArchivo,
        ]]);

        return $this->respuesta($respuesta);
    }

    public function recepcionFacturaRaw(ContextoDocumento $contexto, string $archivo, string $hashArchivo): RespuestaRecepcion
    {
        $respuesta = $this->llamar('recepcionFactura', ['SolicitudServicioRecepcionFactura' => $this->base($contexto) + [
            'archivo' => $archivo,
            'fechaEnvio' => FechaSiat::desde($this->reloj->ahora())->xml(),
            'hashArchivo' => $hashArchivo,
        ]]);

        return $this->respuesta($respuesta);
    }

    public function verificacionEstado(ContextoDocumento $contexto, string $cuf): RespuestaRecepcion
    {
        return $this->respuesta($this->llamar('verificacionEstadoFactura', ['SolicitudServicioVerificacionEstadoFactura' => $this->base($contexto) + [
            'cuf' => self::codigo($cuf, 'cuf'),
        ]]));
    }

    /** El motivo sale del catálogo MotivosAnulacion sincronizado. */
    public function anulacion(ContextoDocumento $contexto, string $cuf, int $codigoMotivo): RespuestaRecepcion
    {
        return $this->respuesta($this->llamar('anulacionFactura', ['SolicitudServicioAnulacionFactura' => $this->base($contexto) + [
            'codigoMotivo' => $codigoMotivo,
            'cuf' => self::codigo($cuf, 'cuf'),
        ]]));
    }

    public function reversionAnulacion(ContextoDocumento $contexto, string $cuf): RespuestaRecepcion
    {
        return $this->respuesta($this->llamar('reversionAnulacionFactura', ['SolicitudServicioReversionAnulacionFactura' => $this->base($contexto) + [
            'cuf' => self::codigo($cuf, 'cuf'),
        ]]));
    }

    /**
     * Envía un paquete de facturas comprimido en .tar.gz (contingencia o emisión fuera de línea).
     */
    public function recepcionPaquete(
        ContextoDocumento $contexto,
        string $archivoTarGz,
        int $cantidadFacturas,
        int $codigoEvento,
        ?string $cafc = null,
    ): RespuestaRecepcion {
        if ($cantidadFacturas <= 0) {
            throw new DatosInvalidos('La cantidad de facturas del paquete debe ser mayor a cero.');
        }

        $hashArchivo = Archivo::sha256($archivoTarGz);
        $solicitud = $this->base($contexto) + [
            'archivo' => $archivoTarGz,
            'fechaEnvio' => FechaSiat::desde($this->reloj->ahora())->xml(),
            'hashArchivo' => $hashArchivo,
            'cantidadFacturas' => $cantidadFacturas,
            'codigoEvento' => $codigoEvento,
        ];

        if ($cafc !== null && $cafc !== '') {
            $solicitud['cafc'] = $cafc;
        }

        $respuesta = $this->llamar('recepcionPaqueteFactura', ['SolicitudServicioRecepcionPaquete' => $solicitud]);

        return $this->respuesta($respuesta);
    }

    public function recepcionPaqueteFactura(
        ContextoDocumento $contexto,
        string $archivoTarGz,
        int $cantidadFacturas,
        int $codigoEvento,
        ?string $cafc = null,
    ): RespuestaRecepcion {
        return $this->recepcionPaquete($contexto, $archivoTarGz, $cantidadFacturas, $codigoEvento, $cafc);
    }

    public function validacionRecepcionPaquete(ContextoDocumento $contexto, string $codigoRecepcion): RespuestaRecepcion
    {
        $respuesta = $this->llamar('validacionRecepcionPaqueteFactura', ['SolicitudServicioValidacionRecepcionPaquete' => $this->base($contexto) + [
            'codigoRecepcion' => self::codigo($codigoRecepcion, 'codigoRecepcion'),
        ]]);

        return $this->respuesta($respuesta);
    }

    public function validacionRecepcionPaqueteFactura(ContextoDocumento $contexto, string $codigoRecepcion): RespuestaRecepcion
    {
        return $this->validacionRecepcionPaquete($contexto, $codigoRecepcion);
    }

    /**
     * Campos de solicitudRecepcion, comunes a todas las operaciones de facturación.
     *
     * @return array<string, int|string>
     */
    private function base(ContextoDocumento $contexto): array
    {
        return [
            'codigoAmbiente' => $this->configuracion->ambiente->value,
            'codigoDocumentoSector' => $contexto->documentoSector->value,
            'codigoEmision' => $contexto->tipoEmision->value,
            'codigoModalidad' => $this->configuracion->modalidad->value,
            'codigoPuntoVenta' => $contexto->punto->puntoVenta,
            'codigoSistema' => $this->configuracion->codigoSistema,
            'codigoSucursal' => $contexto->punto->sucursal,
            'cufd' => self::codigo($contexto->cufd, 'cufd'),
            'cuis' => self::codigo($contexto->cuis, 'cuis'),
            'nit' => $this->contribuyente->nit,
            'tipoFacturaDocumento' => $contexto->tipoFactura->value,
        ];
    }

    private function respuesta(object $respuesta): RespuestaRecepcion
    {
        return RespuestaRecepcion::desde(Lector::objeto($respuesta, 'RespuestaServicioFacturacion'));
    }
}
