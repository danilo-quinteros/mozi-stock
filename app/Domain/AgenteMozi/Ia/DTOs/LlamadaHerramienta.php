<?php

namespace App\Domain\AgenteMozi\Ia\DTOs;

/**
 * Llamada a herramienta que pidió el modelo. Los argumentos son datos no confiables: el registro de
 * herramientas los valida antes de ejecutar nada.
 */
final readonly class LlamadaHerramienta
{
    /**
     * @param  array<string, mixed>  $argumentos
     */
    public function __construct(
        public ?string $id,
        public string $nombre,
        public array $argumentos,
    ) {}
}
