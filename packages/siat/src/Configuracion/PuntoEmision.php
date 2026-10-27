<?php

namespace MoziStudios\Siat\Configuracion;

use MoziStudios\Siat\Excepciones\DatosInvalidos;

/**
 * Sucursal SIN (0 = casa matriz) y punto de venta (0 = no corresponde) desde donde se emite.
 * Son los códigos que asigna el SIN, no los identificadores internos del sistema que integra.
 */
final class PuntoEmision
{
    public function __construct(
        public readonly int $sucursal = 0,
        public readonly int $puntoVenta = 0,
    ) {
        if ($sucursal < 0 || $sucursal > 9999 || $puntoVenta < 0 || $puntoVenta > 9999) {
            throw new DatosInvalidos('La sucursal y el punto de venta deben estar entre 0 y 9999.');
        }
    }
}
