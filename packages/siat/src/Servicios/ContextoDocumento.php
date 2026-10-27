<?php

namespace MoziStudios\Siat\Servicios;

use MoziStudios\Siat\Configuracion\PuntoEmision;
use MoziStudios\Siat\Constantes\DocumentoSector;
use MoziStudios\Siat\Constantes\TipoEmision;
use MoziStudios\Siat\Constantes\TipoFacturaDocumento;

/**
 * Datos comunes de las solicitudes sobre un documento ya emitido (estado, anulación, reversión):
 * desde qué sucursal/punto de venta se opera, con qué CUIS y con el CUFD vigente en ese momento.
 */
final class ContextoDocumento
{
    public function __construct(
        public readonly PuntoEmision $punto,
        public readonly string $cuis,
        public readonly string $cufd,
        public readonly TipoEmision $tipoEmision = TipoEmision::EnLinea,
        public readonly TipoFacturaDocumento $tipoFactura = TipoFacturaDocumento::ConDerechoCreditoFiscal,
        public readonly DocumentoSector $documentoSector = DocumentoSector::CompraVenta,
    ) {}
}
