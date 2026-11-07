<?php

namespace App\Domain\AgenteMozi\Ia\DTOs;

/**
 * Resultado que se devuelve al modelo por una llamada (datos ya autorizados y acotados, o un error).
 */
final readonly class ResultadoHerramienta
{
    /**
     * @param  array<string, mixed>  $contenido
     */
    public function __construct(
        public ?string $id,
        public string $nombre,
        public array $contenido,
    ) {}
}
