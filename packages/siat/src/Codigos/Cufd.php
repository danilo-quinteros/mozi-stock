<?php

namespace MoziStudios\Siat\Codigos;

use DateTimeImmutable;
use MoziStudios\Siat\Excepciones\DatosInvalidos;

/**
 * CUFD vigente de una sucursal/punto de venta. El código de control entra al CUF y el código
 * interno (nuevo en V3) va en cada XML; quien integra los guarda con su vigencia y su historial.
 */
final class Cufd
{
    public function __construct(
        public readonly string $codigo,
        public readonly string $codigoControl,
        public readonly ?string $codigoInterno = null,
        public readonly ?string $direccion = null,
        public readonly ?DateTimeImmutable $fechaVigencia = null,
    ) {
        if (trim($codigo) === '' || preg_match('/^[A-Za-z0-9]+$/', $codigoControl) !== 1) {
            throw new DatosInvalidos('El CUFD necesita su código y un código de control alfanumérico.');
        }
    }
}
