<?php

namespace App\Domain\AgenteMozi\Ia;

use RuntimeException;
use Throwable;

/**
 * Falla de un proveedor de IA. transitorio indica si tiene sentido reintentar (límite de uso,
 * saturación, red); espera, los segundos que el proveedor pidió esperar antes de reintentar. El
 * mensaje nunca incluye claves ni el cuerpo de la petición.
 */
final class ErrorProveedorIa extends RuntimeException
{
    public function __construct(
        string $mensaje,
        public readonly string $codigo,
        public readonly bool $transitorio = false,
        public readonly ?int $estadoHttp = null,
        ?Throwable $anterior = null,
        public readonly ?int $espera = null,
    ) {
        parent::__construct($mensaje, 0, $anterior);
    }
}
