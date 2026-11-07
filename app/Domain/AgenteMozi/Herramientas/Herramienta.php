<?php

namespace App\Domain\AgenteMozi\Herramientas;

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\DTOs\DatasetPropuesto;

/**
 * Herramienta empresarial que el modelo puede pedir (§ 5): nombre estable, esquema de argumentos,
 * permiso necesario y resultados trazables como datasets. Nunca recibe empresa ni sucursal del modelo:
 * las toma de ContextoAgente. Las herramientas de esta etapa son de solo lectura.
 */
interface Herramienta
{
    public function nombre(): string;

    /** Descripción para el modelo: qué devuelve, columnas y límites. */
    public function descripcion(): string;

    /**
     * JSON Schema (objeto) de los argumentos. Cualquier clave fuera de properties se rechaza.
     *
     * @return array<string, mixed>
     */
    public function parametros(): array;

    /**
     * Reglas de validación de Laravel para los argumentos.
     *
     * @return array<string, mixed>
     */
    public function reglas(): array;

    /** Gate que debe permitir el usuario; null = cualquier usuario del agente. */
    public function permiso(): ?string;

    /** Estado de trabajo apto para la interfaz mientras se ejecuta. */
    public function avance(): string;

    /**
     * @param  array<string, mixed>  $argumentos  ya validados
     * @return list<array{dataset: DatasetPropuesto, descripcion: string}>
     */
    public function ejecutar(ContextoAgente $contexto, array $argumentos): array;
}
