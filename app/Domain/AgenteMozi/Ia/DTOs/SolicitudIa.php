<?php

namespace App\Domain\AgenteMozi\Ia\DTOs;

/**
 * Petición a un proveedor (AiRequest de § 4.2). herramientaObligatoria exige que el modelo conteste
 * con una llamada (el turno termina con la herramienta «responder»); permitidas restringe a cuáles.
 * temperatura en null deja el valor del proveedor (Gemini 3 recomienda no cambiarlo).
 */
final readonly class SolicitudIa
{
    /**
     * @param  list<MensajeIa>  $mensajes
     * @param  list<DefinicionHerramienta>  $herramientas
     * @param  list<string>|null  $permitidas
     */
    public function __construct(
        public string $modelo,
        public string $sistema,
        public array $mensajes,
        public array $herramientas = [],
        public bool $herramientaObligatoria = false,
        public ?array $permitidas = null,
        public int $maxTokens = 8192,
        public ?float $temperatura = null,
        public string $referencia = '',
    ) {}
}
