<?php

namespace App\Domain\AgenteMozi\DTOs;

use App\Domain\AgenteMozi\Ia\DTOs\UsoIa;

/**
 * Respuesta de un turno, igual para cualquier respondedor (demo, Gemini u OpenAI): texto del chat
 * en Markdown, artefactos sugeridos y los datasets que los sustentan. uso solo existe con un
 * proveedor de IA (tokens, latencia y herramientas del turno). acciones: enlaces de la lista cerrada que
 * ofrecieron las herramientas y eligió el modelo (CatalogoAcciones).
 */
final readonly class RespuestaAgente
{
    /**
     * @param  list<ArtefactoPropuesto>  $artefactos
     * @param  list<DatasetPropuesto>  $datasets
     * @param  list<AccionPropuesta>  $acciones
     */
    public function __construct(
        public string $texto,
        public array $artefactos,
        public array $datasets,
        public string $proveedor,
        public ?string $modelo = null,
        public ?UsoIa $uso = null,
        public array $acciones = [],
    ) {}
}
