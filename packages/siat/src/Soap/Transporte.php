<?php

namespace MoziStudios\Siat\Soap;

use MoziStudios\Siat\Configuracion\ConfiguracionSistema;
use MoziStudios\Siat\Configuracion\TokenDelegado;
use MoziStudios\Siat\Excepciones\ErrorComunicacion;

/**
 * Envía una operación SOAP al SIN y devuelve el cuerpo de la respuesta tal como lo decodifica el WSDL.
 * SoapTransporte es la implementación real; Testing\TransporteFalso sirve para pruebas sin red.
 */
interface Transporte
{
    /**
     * @param  array<string, mixed>  $parametros  cuerpo de la operación, con su envoltorio (p. ej. SolicitudCuis)
     *
     * @throws ErrorComunicacion
     */
    public function llamar(
        ConfiguracionSistema $configuracion,
        Servicio $servicio,
        string $operacion,
        array $parametros,
        TokenDelegado $token,
    ): object;
}
