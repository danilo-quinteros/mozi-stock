<?php

namespace App\Domain\AgenteMozi\DTOs;

/**
 * Enlace a una pantalla existente que ofrece una herramienta (IA-P3): tipo de la lista cerrada de
 * CatalogoAcciones, id de la entidad (si el tipo lo usa) y etiqueta armada por el servidor con datos
 * de la empresa. El modelo solo elige cuáles mostrar; nunca escribe la URL ni la etiqueta.
 */
final readonly class AccionPropuesta
{
    public function __construct(
        public string $tipo,
        public ?int $id,
        public string $etiqueta,
    ) {}

    /**
     * @return array{tipo: string, id: int|null, etiqueta: string}
     */
    public function aArreglo(): array
    {
        return ['tipo' => $this->tipo, 'id' => $this->id, 'etiqueta' => $this->etiqueta];
    }
}
