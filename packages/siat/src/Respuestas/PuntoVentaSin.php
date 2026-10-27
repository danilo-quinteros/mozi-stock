<?php

namespace MoziStudios\Siat\Respuestas;

/** Punto de venta tal como lo informa el SIN en consultaPuntoVenta (el tipo llega como texto). */
final class PuntoVentaSin
{
    public function __construct(
        public readonly int $codigo,
        public readonly string $nombre,
        public readonly string $tipo,
    ) {}
}
