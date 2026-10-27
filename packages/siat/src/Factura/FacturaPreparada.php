<?php

namespace MoziStudios\Siat\Factura;

/**
 * Factura lista para enviar: CUF, XML validado contra el XSD, archivo GZIP y su SHA-256.
 * Quien integra debe guardar el XML y el CUF ANTES de enviarla: si el envío falla, se reintenta
 * o se envía en paquete con estos mismos datos, nunca con una factura regenerada.
 */
final class FacturaPreparada
{
    public function __construct(
        public readonly FacturaCompraVenta $factura,
        public readonly string $cuf,
        public readonly string $xml,
        public readonly string $archivo,
        public readonly string $hashArchivo,
    ) {}
}
