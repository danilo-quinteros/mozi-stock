<?php

namespace App\Domain\AgenteMozi\Lienzo;

use App\Enums\TipoArtefacto;
use App\Models\AgenteArtefacto;
use App\Models\AgenteMensaje;
use Illuminate\Support\Str;

/**
 * Serializa lo guardado al protocolo versionado que consume el Lienzo. Las URL las genera el servidor
 * (nunca la IA) y el código de sandbox_web no viaja: el Lienzo solo recibe la URL del documento aislado.
 */
final class ProtocoloLienzo
{
    /**
     * Un análisis por respuesta del agente que trajo artefactos, en orden cronológico.
     *
     * @param  iterable<AgenteMensaje>  $mensajes  con artefactos.dataset cargados
     * @return list<array<string, mixed>>
     */
    public static function analisis(iterable $mensajes): array
    {
        $preguntas = [];
        $analisis = [];

        foreach ($mensajes as $mensaje) {
            if (! $mensaje->esDelAgente()) {
                $preguntas[$mensaje->id] = $mensaje->texto;

                continue;
            }

            $primero = $mensaje->artefactos->first();

            if ($primero === null) {
                continue;
            }

            $pregunta = $preguntas[$mensaje->respuesta_de_id] ?? $primero->titulo;
            $artefactos = $mensaje->artefactos->map(fn (AgenteArtefacto $a) => self::artefacto($a))->all();

            $analisis[] = [
                'schema_version' => ValidadorLienzo::VERSION,
                'message_id' => $mensaje->id,
                'title' => Str::limit(Str::squish($pregunta), 70),
                'created_at' => $mensaje->created_at->toIso8601String(),
                'demo' => $mensaje->artefactos->contains(fn (AgenteArtefacto $a) => ($a->meta['quality'] ?? null) === 'demo'),
                'artifacts' => array_values($artefactos),
            ];
        }

        return $analisis;
    }

    /**
     * @return array<string, mixed>
     */
    public static function artefacto(AgenteArtefacto $artefacto): array
    {
        $ref = $artefacto->dataset?->ref;

        return [
            'id' => $artefacto->id,
            'type' => $artefacto->tipo->value,
            'title' => $artefacto->titulo,
            'dataset_ref' => $ref,
            'dataset_url' => $ref === null ? null : route('agente-mozi.dataset', $ref),
            'csv_url' => $ref === null ? null : route('agente-mozi.dataset.csv', $ref),
            'view_url' => $artefacto->tipo === TipoArtefacto::SandboxWeb ? route('agente-mozi.sandbox', $artefacto) : null,
            'spec' => $artefacto->tipo === TipoArtefacto::SandboxWeb ? [] : $artefacto->spec,
            'meta' => $artefacto->meta,
        ];
    }
}
