<?php

namespace App\Domain\AgenteMozi\Ia\DTOs;

/**
 * Herramienta que se ofrece al modelo: nombre estable, descripción y esquema JSON de argumentos.
 * El modelo solo puede pedirla; la ejecuta Laravel después de validar nombre, argumentos y permisos.
 */
final readonly class DefinicionHerramienta
{
    /**
     * @param  array<string, mixed>  $parametros  JSON Schema de un objeto
     */
    public function __construct(
        public string $nombre,
        public string $descripcion,
        public array $parametros,
    ) {}
}
