<?php

namespace App\Domain\AgenteMozi;

use App\Domain\AgenteMozi\Ia\DTOs\UsoIa;
use RuntimeException;
use Throwable;

/**
 * El motor no pudo responder por una causa que el usuario puede entender (cuota agotada, proveedor
 * sin configurar o caído). El mensaje es apto para el chat; el uso ya consumido se guarda igual.
 */
final class RespuestaNoDisponible extends RuntimeException
{
    public function __construct(
        string $mensajeUsuario,
        public readonly ?string $proveedor = null,
        public readonly ?string $modelo = null,
        public readonly ?UsoIa $uso = null,
        ?Throwable $anterior = null,
    ) {
        parent::__construct($mensajeUsuario, 0, $anterior);
    }
}
