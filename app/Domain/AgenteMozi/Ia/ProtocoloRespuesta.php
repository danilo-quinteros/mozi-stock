<?php

namespace App\Domain\AgenteMozi\Ia;

use App\Domain\AgenteMozi\DTOs\ArtefactoPropuesto;
use App\Domain\AgenteMozi\DTOs\DatasetPropuesto;
use App\Domain\AgenteMozi\DTOs\RespuestaAgente;
use App\Domain\AgenteMozi\Ia\DTOs\DefinicionHerramienta;
use App\Domain\AgenteMozi\Ia\DTOs\UsoIa;
use App\Domain\AgenteMozi\Lienzo\ValidadorLienzo;
use App\Domain\AgenteMozi\RespuestaNoDisponible;
use App\Enums\TipoArtefacto;

/**
 * Protocolo entre el modelo y el Lienzo (§ 6): la respuesta final llega como llamada a la herramienta
 * «responder», con un esquema por tipo de artefacto que usa las mismas claves que valida
 * ValidadorLienzo. Así funciona igual con cualquier proveedor que admita herramientas, sin combinar
 * herramientas y salida estructurada en la misma petición. El modelo solo referencia datasets que
 * devolvieron las herramientas: nunca envía filas ni cifras para el Lienzo.
 *
 * Sin minItems/maxItems: verificado el 30/10/2026, Gemini rechaza con INVALID_ARGUMENT un arreglo de
 * objetos con maxItems 20 bajo anyOf. Los topes los aplica ValidadorLienzo y van en las descripciones.
 */
final class ProtocoloRespuesta
{
    public const HERRAMIENTA = 'responder';

    public function definicion(bool $sandbox): DefinicionHerramienta
    {
        $variantes = [$this->kpi(), $this->grafico(), $this->tabla(), $this->hallazgo(), $this->vacio()];

        if ($sandbox) {
            $variantes[] = $this->vista();
        }

        return new DefinicionHerramienta(self::HERRAMIENTA, 'Entrega la respuesta final al usuario: texto del chat y elementos para el Lienzo. Llámala una sola vez, al terminar.', [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string', 'description' => 'Respuesta para el chat en Markdown simple (negritas y listas), sin tablas ni HTML.'],
                'artifacts' => [
                    'type' => 'array',
                    'description' => 'Elementos del Lienzo (máximo '.(int) config('agente_mozi.limites.artefactos_por_mensaje').') basados en datasets devueltos por herramientas. Vacío si la pregunta no necesita datos.',
                    'items' => ['anyOf' => $variantes],
                ],
            ],
            'required' => ['message', 'artifacts'],
            'additionalProperties' => false,
        ]);
    }

    /**
     * Resultado de una herramienta para el modelo: columnas tipadas y filas acotadas.
     *
     * @return array<string, mixed>
     */
    public function paraModelo(string $clave, DatasetPropuesto $dataset, string $descripcion): array
    {
        $meta = $dataset->meta;

        return array_filter([
            'dataset' => $clave,
            'description' => $descripcion,
            'quality' => $meta['quality'] ?? 'actual',
            'synthetic' => ($meta['synthetic'] ?? false) === true,
            'period' => $meta['period'] ?? null,
            'scope' => $meta['scope'] ?? null,
            'method' => $meta['method'] ?? null,
            'columns' => array_map(fn (array $columna) => ['field' => $columna['field'], 'label' => $columna['label'], 'type' => $columna['type']], $dataset->columnas),
            'rows' => array_slice($dataset->filas, 0, (int) config('agente_mozi.ia.filas_al_modelo')),
            'total_rows' => count($dataset->filas),
            // Consulta genérica: coincidencias en la base y si el dataset es un recorte de ellas.
            'total_matches' => $meta['total_matches'] ?? null,
            'truncated' => $meta['truncated'] ?? null,
        ], fn ($valor) => $valor !== null);
    }

    /**
     * @param  array<string, mixed>  $argumentos  argumentos de «responder» (no confiables)
     * @param  array<string, DatasetPropuesto>  $datasets  datasets del turno por clave (d1, d2…)
     */
    public function respuesta(array $argumentos, array $datasets, string $proveedor, string $modelo, UsoIa $uso, bool $sandbox): RespuestaAgente
    {
        $mensaje = is_string($argumentos['message'] ?? null) ? trim($argumentos['message']) : '';

        if ($mensaje === '') {
            throw new RespuestaNoDisponible(__('No pude completar la respuesta. Tu pregunta quedó guardada: vuelve a enviarla en un momento.'), $proveedor, $modelo, $uso);
        }

        $sinteticos = array_filter($datasets, fn (DatasetPropuesto $dataset) => ($dataset->meta['synthetic'] ?? false) === true) !== [];
        $artefactos = [];

        foreach (is_array($argumentos['artifacts'] ?? null) ? $argumentos['artifacts'] : [] as $item) {
            $artefacto = is_array($item) ? $this->artefacto($item, $datasets, $sandbox, $sinteticos) : null;

            if ($artefacto !== null) {
                $artefactos[] = $artefacto;
            }
        }

        if ($sinteticos) {
            $mensaje = __('**Modo demostración** · Las cifras son sintéticas y no provienen de tu empresa.')."\n\n".$mensaje;
        }

        return new RespuestaAgente($mensaje, $artefactos, array_map(
            fn (string $clave, DatasetPropuesto $dataset) => new DatasetPropuesto($clave, $dataset->columnas, $dataset->filas, $dataset->meta),
            array_keys($datasets), $datasets,
        ), $proveedor, $modelo, $uso);
    }

    /**
     * Arma el artefacto propuesto; ValidadorLienzo decide después si es válido. Un tipo desconocido,
     * una vista sin habilitar o un hallazgo sin datos del turno se descartan aquí.
     *
     * @param  array<mixed>  $item
     * @param  array<string, DatasetPropuesto>  $datasets
     */
    private function artefacto(array $item, array $datasets, bool $sandbox, bool $sinteticos): ?ArtefactoPropuesto
    {
        $tipo = TipoArtefacto::tryFrom(is_string($item['type'] ?? null) ? $item['type'] : '');

        if ($tipo === null || ($tipo === TipoArtefacto::SandboxWeb && ! $sandbox) || ($tipo === TipoArtefacto::Hallazgo && $datasets === [])) {
            return null;
        }

        $meta = match ($tipo) {
            TipoArtefacto::SandboxWeb => ['origin' => 'ia'],
            // El hallazgo hereda la procedencia de los datos del turno: con datos sintéticos, marca demo.
            TipoArtefacto::Hallazgo => $sinteticos ? ['quality' => 'demo', 'synthetic' => true, 'source' => 'demo_sintetico', 'origin' => 'ia'] : ['origin' => 'ia'],
            TipoArtefacto::Vacio => ['quality' => 'insufficient', 'origin' => 'ia'],
            default => [],
        };

        /** @var array<string, mixed> $spec */
        $spec = array_diff_key($item, array_flip(['type', 'title', 'dataset']));

        return new ArtefactoPropuesto(
            $tipo,
            is_string($item['title'] ?? null) ? $item['title'] : '',
            $tipo->requiereDataset() ? (is_string($item['dataset'] ?? null) ? $item['dataset'] : '') : null,
            $spec,
            $meta,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function kpi(): array
    {
        return $this->variante('kpi', 'Indicador: un valor numérico de un dataset de una fila.', [
            'dataset' => ['type' => 'string'],
            'field' => ['type' => 'string', 'description' => 'Columna numérica del dataset.'],
            'format' => ['type' => 'string', 'enum' => ['integer', 'number', 'decimal_1', 'decimal_2', 'bs', 'percent']],
            'unit' => ['type' => 'string', 'description' => 'Unidad corta, p. ej. «días» o «productos».'],
            'hint' => ['type' => 'string', 'description' => 'Aclaración breve debajo del valor.'],
            'tone' => ['type' => 'string', 'enum' => ['neutral', 'good', 'warning', 'danger']],
        ], ['dataset', 'field']);
    }

    /**
     * @return array<string, mixed>
     */
    private function grafico(): array
    {
        $campo = ['type' => 'string'];

        return $this->variante('chart', 'Gráfico de un dataset.', [
            'dataset' => ['type' => 'string'],
            'chart_type' => ['type' => 'string', 'enum' => ValidadorLienzo::GRAFICOS],
            'x_field' => ['type' => 'string', 'description' => 'Columna de categorías o fechas (numérica en scatter).'],
            'series' => [
                'type' => 'array', 'description' => 'Entre 1 y '.(int) config('agente_mozi.limites.series_grafico').' series numéricas (una sola en donut).',
                'items' => ['type' => 'object', 'properties' => [
                    'field' => $campo, 'label' => ['type' => 'string'], 'style' => ['type' => 'string', 'enum' => ['solid', 'dashed']],
                ], 'required' => ['field', 'label'], 'additionalProperties' => false],
            ],
            'value_format' => ['type' => 'string', 'enum' => ['integer', 'number', 'decimal_1', 'decimal_2', 'bs', 'percent']],
            'value_unit' => ['type' => 'string'],
            'x_label' => ['type' => 'string'],
            'y_label' => ['type' => 'string'],
            'orientation' => ['type' => 'string', 'enum' => ['vertical', 'horizontal'], 'description' => 'Solo bar.'],
            'sort' => ['type' => 'string', 'enum' => ['none', 'asc', 'desc'], 'description' => 'Solo bar y donut.'],
            'band' => ['type' => 'object', 'description' => 'Solo line/area: rango de incertidumbre.', 'properties' => [
                'lower_field' => $campo, 'upper_field' => $campo, 'label' => ['type' => 'string'],
            ], 'required' => ['lower_field', 'upper_field', 'label'], 'additionalProperties' => false],
            'label_field' => ['type' => 'string', 'description' => 'Solo scatter: columna de texto con el nombre del punto.'],
        ], ['dataset', 'chart_type', 'x_field', 'series']);
    }

    /**
     * @return array<string, mixed>
     */
    private function tabla(): array
    {
        return $this->variante('table', 'Tabla de detalle de un dataset.', [
            'dataset' => ['type' => 'string'],
            'columns' => [
                'type' => 'array', 'description' => 'Columnas del dataset a mostrar, en orden.',
                'items' => ['type' => 'object', 'properties' => [
                    'field' => ['type' => 'string'], 'label' => ['type' => 'string'], 'format' => ['type' => 'string', 'enum' => ValidadorLienzo::FORMATOS],
                ], 'required' => ['field', 'label', 'format'], 'additionalProperties' => false],
            ],
            'page_size' => ['type' => 'integer', 'enum' => [10, 25, 50]],
            'default_sort' => ['type' => 'object', 'properties' => [
                'field' => ['type' => 'string'], 'direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
            ], 'required' => ['field', 'direction'], 'additionalProperties' => false],
        ], ['dataset', 'columns']);
    }

    /**
     * @return array<string, mixed>
     */
    private function hallazgo(): array
    {
        return $this->variante('insight', 'Interpretación o advertencia sobre los datos consultados, sin cifras nuevas.', [
            'text' => ['type' => 'string'],
            'tone' => ['type' => 'string', 'enum' => ['info', 'warning', 'success']],
            'bullets' => ['type' => 'array', 'description' => 'Hasta 6 viñetas breves.', 'items' => ['type' => 'string']],
        ], ['text']);
    }

    /**
     * @return array<string, mixed>
     */
    private function vacio(): array
    {
        return $this->variante('empty_state', 'Aviso cuando no hay datos suficientes para mostrar algo.', [
            'text' => ['type' => 'string'],
        ], ['text']);
    }

    /**
     * @return array<string, mixed>
     */
    private function vista(): array
    {
        return $this->variante('sandbox_web', 'Vista personalizada HTML/CSS/JS en un iframe aislado, solo si el usuario la pide.', [
            'dataset' => ['type' => 'string'],
            'html' => ['type' => 'string', 'description' => 'Contenido del body, sin scripts, estilos, enlaces ni URLs.'],
            'css' => ['type' => 'string'],
            'js' => ['type' => 'string', 'description' => 'Recibe los datos con window.mozi.onData(fn).'],
        ], ['dataset', 'html']);
    }

    /**
     * @param  array<string, mixed>  $propiedades
     * @param  list<string>  $requeridas
     * @return array<string, mixed>
     */
    private function variante(string $tipo, string $descripcion, array $propiedades, array $requeridas): array
    {
        return [
            'type' => 'object',
            'description' => $descripcion,
            'properties' => ['type' => ['type' => 'string', 'enum' => [$tipo]], 'title' => ['type' => 'string']] + $propiedades,
            'required' => ['type', 'title', ...$requeridas],
            'additionalProperties' => false,
        ];
    }
}
