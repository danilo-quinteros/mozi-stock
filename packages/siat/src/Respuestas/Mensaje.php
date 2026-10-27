<?php

namespace MoziStudios\Siat\Respuestas;

/**
 * Mensaje del SIN (código de la paramétrica «mensajes de servicios» y su descripción). En la recepción
 * de facturas indica además si es advertencia y a qué archivo o línea se refiere.
 */
final class Mensaje
{
    public function __construct(
        public readonly int $codigo,
        public readonly string $descripcion,
        public readonly ?bool $advertencia = null,
        public readonly ?int $numeroArchivo = null,
        public readonly ?int $numeroDetalle = null,
    ) {}

    public function __toString(): string
    {
        return "{$this->codigo} {$this->descripcion}";
    }
}
