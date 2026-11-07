<?php

namespace App\Domain\AgenteMozi\Ia\DTOs;

/**
 * Respuesta de un proveedor (AiResponse de § 4.2). mensaje es el turno del modelo listo para volver
 * al historial de la siguiente petición; modelo es la versión que informó el proveedor.
 */
final readonly class RespuestaIa
{
    public function __construct(
        public MensajeIa $mensaje,
        public UsoIa $uso,
        public string $proveedor,
        public string $modelo,
        public ?string $finalizacion = null,
        public ?string $referenciaProveedor = null,
    ) {}
}
