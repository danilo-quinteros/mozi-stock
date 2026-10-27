<?php

namespace MoziStudios\Siat\Cuf;

use MoziStudios\Siat\Constantes\DocumentoSector;
use MoziStudios\Siat\Constantes\Modalidad;
use MoziStudios\Siat\Constantes\TipoEmision;
use MoziStudios\Siat\Constantes\TipoFacturaDocumento;
use MoziStudios\Siat\Fecha\FechaSiat;

/** Campos que componen el CUF, en el orden y con las longitudes del anexo «Generación CUF». */
final class DatosCuf
{
    public function __construct(
        public readonly int $nit,
        public readonly FechaSiat $fecha,
        public readonly int $sucursal,
        public readonly Modalidad $modalidad,
        public readonly TipoEmision $tipoEmision,
        public readonly TipoFacturaDocumento $tipoFactura,
        public readonly DocumentoSector $documentoSector,
        public readonly int $numeroFactura,
        public readonly int $puntoVenta,
    ) {}
}
