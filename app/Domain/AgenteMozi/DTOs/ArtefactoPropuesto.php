<?php

namespace App\Domain\AgenteMozi\DTOs;

use App\Enums\TipoArtefacto;

/**
 * Artefacto sugerido para el Lienzo, todavía sin validar. dataset es la clave de un
 * DatasetPropuesto de la misma respuesta (nunca una URL ni un ID de base de datos).
 */
final readonly class ArtefactoPropuesto
{
    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public TipoArtefacto $tipo,
        public string $titulo,
        public ?string $dataset,
        public array $spec,
        public array $meta = [],
    ) {}
}
