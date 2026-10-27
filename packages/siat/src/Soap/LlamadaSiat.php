<?php

namespace MoziStudios\Siat\Soap;

/** Resumen de una llamada al SIN para bitácoras y métricas. Sin token ni contenido de la factura. */
final class LlamadaSiat
{
    public function __construct(
        public readonly Servicio $servicio,
        public readonly string $operacion,
        public readonly int $milisegundos,
        public readonly bool $exitosa,
        public readonly ?string $error = null,
    ) {}
}
