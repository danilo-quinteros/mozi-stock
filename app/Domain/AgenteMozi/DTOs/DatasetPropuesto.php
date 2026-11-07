<?php

namespace App\Domain\AgenteMozi\DTOs;

/**
 * Datos tabulares que un respondedor entrega para sustentar artefactos. Los importes y decimales
 * viajan como cadenas (nunca float); ValidadorLienzo comprueba tipos, columnas y topes antes de guardar.
 */
final readonly class DatasetPropuesto
{
    /**
     * @param  list<array{field: string, label: string, type: string, nullable?: bool}>  $columnas
     * @param  list<array<string, mixed>>  $filas
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $clave,
        public array $columnas,
        public array $filas,
        public array $meta,
    ) {}
}
