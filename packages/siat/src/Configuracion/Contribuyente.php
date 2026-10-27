<?php

namespace MoziStudios\Siat\Configuracion;

use MoziStudios\Siat\Excepciones\DatosInvalidos;

/**
 * Emisor que factura mediante este sistema: su NIT y el token delegado que autorizó al proveedor.
 * En un sistema multiempresa se construye uno por empresa y por solicitud; nunca se comparte.
 */
final class Contribuyente
{
    public function __construct(
        public readonly int $nit,
        public readonly TokenDelegado $token,
    ) {
        if ($nit < 1 || $nit > 9999999999999) {
            throw new DatosInvalidos('El NIT debe tener entre 1 y 13 dígitos.');
        }
    }
}
