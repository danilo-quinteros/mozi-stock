<?php

namespace MoziStudios\Siat\Respuestas;

/**
 * Fila normalizada de un catálogo sincronizado: código, descripción y los atributos propios de
 * cada catálogo (por ejemplo codigoActividad y nandina en productos y servicios).
 */
final class ItemCatalogo
{
    /**
     * @param  array<string, string>  $atributos
     */
    public function __construct(
        public readonly string $codigo,
        public readonly string $descripcion,
        public readonly array $atributos = [],
    ) {}
}
