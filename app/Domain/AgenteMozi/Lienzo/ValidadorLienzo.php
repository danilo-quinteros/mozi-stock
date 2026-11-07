<?php

namespace App\Domain\AgenteMozi\Lienzo;

use App\Domain\AgenteMozi\DTOs\ArtefactoPropuesto;
use App\Domain\AgenteMozi\DTOs\DatasetPropuesto;
use App\Enums\TipoArtefacto;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Throwable;

/**
 * Protocolo del Lienzo (§ 6.3): la IA sugiere, MoziStock decide qué es válido. Cada spec se
 * reconstruye solo con las claves permitidas de su tipo (nada de opciones libres, callbacks ni
 * código), comprobando que las columnas existan y sus tipos sean compatibles. Los números llegan
 * como enteros o cadenas decimales: nunca float.
 */
final class ValidadorLienzo
{
    public const VERSION = '1.0';

    public const TIPOS_COLUMNA = ['string', 'integer', 'number', 'money', 'percent', 'date'];

    public const FORMATOS = ['text', 'integer', 'number', 'decimal_1', 'decimal_2', 'bs', 'percent', 'date', 'badge'];

    public const GRAFICOS = ['bar', 'line', 'area', 'donut', 'scatter'];

    public const CALIDADES = ['actual', 'estimated', 'demo', 'insufficient'];

    private const NUMERICOS = ['integer', 'number', 'money', 'percent'];

    private const FORMATOS_NUMERICOS = ['integer', 'number', 'decimal_1', 'decimal_2', 'bs', 'percent'];

    public function __construct(private readonly ValidadorSandboxWeb $sandbox) {}

    /**
     * @return array{columnas: list<array{field: string, label: string, type: string, nullable: bool}>, filas: list<array<string, string|int|null>>, meta: array<string, mixed>}
     */
    public function dataset(DatasetPropuesto $dataset): array
    {
        if ($dataset->columnas === [] || count($dataset->columnas) > $this->limite('columnas_dataset')) {
            throw new ArtefactoInvalido('El dataset debe tener entre 1 y '.$this->limite('columnas_dataset').' columnas.');
        }

        $columnas = [];

        foreach ($dataset->columnas as $columna) {
            $campo = $columna['field'];

            if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $campo) !== 1 || isset($columnas[$campo])) {
                throw new ArtefactoInvalido("Columna inválida o repetida: {$campo}.");
            }

            if (! in_array($columna['type'], self::TIPOS_COLUMNA, true)) {
                throw new ArtefactoInvalido("Tipo de columna no permitido: {$columna['type']}.");
            }

            $columnas[$campo] = [
                'field' => $campo,
                'label' => $this->texto($columna['label'], 60),
                'type' => $columna['type'],
                'nullable' => $columna['nullable'] ?? false,
            ];
        }

        if (count($dataset->filas) > $this->limite('filas_dataset')) {
            throw new ArtefactoInvalido('El dataset supera '.$this->limite('filas_dataset').' filas.');
        }

        $filas = [];

        foreach ($dataset->filas as $indice => $fila) {
            if (array_diff_key($fila, $columnas) !== []) {
                throw new ArtefactoInvalido("La fila {$indice} tiene campos que no son columnas del dataset.");
            }

            $normal = [];

            foreach ($columnas as $campo => $columna) {
                $normal[$campo] = $this->valor($fila[$campo] ?? null, $columna, $indice);
            }

            $filas[] = $normal;
        }

        return ['columnas' => array_values($columnas), 'filas' => $filas, 'meta' => $this->meta($dataset->meta)];
    }

    /**
     * @param  array{columnas: list<array{field: string, label: string, type: string, nullable: bool}>, filas: list<array<string, string|int|null>>, meta: array<string, mixed>}|null  $dataset  ya validado
     * @return array{titulo: string, spec: array<string, mixed>, meta: array<string, mixed>}
     */
    public function artefacto(ArtefactoPropuesto $artefacto, ?array $dataset): array
    {
        $tipo = $artefacto->tipo;

        if ($tipo->requiereDataset() !== ($dataset !== null)) {
            throw new ArtefactoInvalido("El artefacto {$tipo->value} ".($dataset === null ? 'requiere' : 'no admite').' un dataset.');
        }

        $campos = $dataset === null ? [] : array_column($dataset['columnas'], 'type', 'field');
        $s = $artefacto->spec;

        $spec = match ($tipo) {
            TipoArtefacto::Kpi => $this->kpi($s, $campos, $dataset['filas'] ?? []),
            TipoArtefacto::Grafico => $this->grafico($s, $campos),
            TipoArtefacto::Tabla => $this->tabla($s, $campos),
            TipoArtefacto::Hallazgo => $this->hallazgo($s),
            TipoArtefacto::Vacio => ['text' => $this->texto($s['text'] ?? null, 300)],
            TipoArtefacto::SandboxWeb => $this->sandbox->validar($s),
        };

        return [
            'titulo' => $this->texto($artefacto->titulo, 160),
            'spec' => $spec,
            'meta' => $this->meta($artefacto->meta + ($dataset['meta'] ?? [])),
        ];
    }

    /**
     * Metadatos de procedencia: solo claves conocidas y saneadas.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function meta(array $meta): array
    {
        $calidad = $meta['quality'] ?? 'actual';

        if (! in_array($calidad, self::CALIDADES, true)) {
            throw new ArtefactoInvalido('Calidad de datos no permitida.');
        }

        $limpio = ['quality' => $calidad];

        foreach (['source' => 60, 'scope' => 120, 'method' => 240, 'note' => 240, 'origin' => 20] as $clave => $maximo) {
            if (isset($meta[$clave])) {
                $limpio[$clave] = $this->texto($meta[$clave], $maximo);
            }
        }

        if (isset($meta['synthetic'])) {
            $limpio['synthetic'] = $meta['synthetic'] === true;
        }

        if (isset($meta['period'])) {
            $periodo = is_array($meta['period']) ? $meta['period'] : [];
            $limpio['period'] = ['from' => $this->fecha($periodo['from'] ?? null), 'to' => $this->fecha($periodo['to'] ?? null)];
        }

        if (isset($meta['calculated_at'])) {
            try {
                $limpio['calculated_at'] = CarbonImmutable::parse($this->texto($meta['calculated_at'], 40))->toIso8601String();
            } catch (Throwable) {
                throw new ArtefactoInvalido('calculated_at no es una fecha válida.');
            }
        }

        if (isset($meta['currency'])) {
            $limpio['currency'] = $meta['currency'] === 'BOB' ? 'BOB' : throw new ArtefactoInvalido('Moneda no permitida.');
        }

        if (isset($meta['timezone'])) {
            $limpio['timezone'] = in_array($meta['timezone'], DateTimeZone::listIdentifiers(), true)
                ? $meta['timezone'] : throw new ArtefactoInvalido('Zona horaria inválida.');
        }

        return $limpio;
    }

    /**
     * @param  array<string, mixed>  $s
     * @param  array<string, string>  $campos
     * @param  list<array<string, string|int|null>>  $filas
     * @return array<string, mixed>
     */
    private function kpi(array $s, array $campos, array $filas): array
    {
        $campo = $this->campo($s['field'] ?? null, $campos, self::NUMERICOS);

        // Un KPI muestra un único valor: con varias filas no hay forma de saber cuál, así que no se adivina.
        if (count($filas) !== 1) {
            throw new ArtefactoInvalido('Un KPI necesita un dataset de exactamente una fila.');
        }

        return array_filter([
            'field' => $campo,
            'format' => $this->formatoNumerico($s['format'] ?? null, $campos[$campo]),
            'unit' => $this->opcional($s['unit'] ?? null, 30),
            'hint' => $this->opcional($s['hint'] ?? null, 160),
            'tone' => $this->opcion($s['tone'] ?? 'neutral', ['neutral', 'good', 'warning', 'danger']),
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $s
     * @param  array<string, string>  $campos
     * @return array<string, mixed>
     */
    private function grafico(array $s, array $campos): array
    {
        $tipo = $this->opcion($s['chart_type'] ?? null, self::GRAFICOS);
        $ejeX = $this->campo($s['x_field'] ?? null, $campos, $tipo === 'scatter' ? self::NUMERICOS : ['string', 'date', 'integer']);
        $series = $s['series'] ?? null;

        if (! is_array($series) || ! array_is_list($series) || $series === [] || count($series) > $this->limite('series_grafico')
            || ($tipo === 'donut' && count($series) !== 1)) {
            throw new ArtefactoInvalido('Series del gráfico inválidas.');
        }

        $limpias = [];

        foreach ($series as $serie) {
            $serie = is_array($serie) ? $serie : [];
            $limpias[] = [
                'field' => $this->campo($serie['field'] ?? null, $campos, self::NUMERICOS),
                'label' => $this->texto($serie['label'] ?? null, 40),
                'style' => $this->opcion($serie['style'] ?? 'solid', ['solid', 'dashed']),
            ];
        }

        $spec = [
            'chart_type' => $tipo,
            'x_field' => $ejeX,
            'series' => $limpias,
            'value_format' => $this->formatoNumerico($s['value_format'] ?? null, $campos[$limpias[0]['field']]),
            'value_unit' => $this->opcional($s['value_unit'] ?? null, 20),
            'x_label' => $this->opcional($s['x_label'] ?? null, 40),
            'y_label' => $this->opcional($s['y_label'] ?? null, 40),
        ];

        if ($tipo === 'bar') {
            $spec['orientation'] = $this->opcion($s['orientation'] ?? 'vertical', ['vertical', 'horizontal']);
        }

        if (in_array($tipo, ['bar', 'donut'], true)) {
            $spec['sort'] = $this->opcion($s['sort'] ?? 'none', ['none', 'asc', 'desc']);
        }

        if (in_array($tipo, ['line', 'area'], true) && isset($s['band'])) {
            $banda = is_array($s['band']) ? $s['band'] : [];
            $spec['band'] = [
                'lower_field' => $this->campo($banda['lower_field'] ?? null, $campos, self::NUMERICOS),
                'upper_field' => $this->campo($banda['upper_field'] ?? null, $campos, self::NUMERICOS),
                'label' => $this->texto($banda['label'] ?? null, 40),
            ];
        }

        if ($tipo === 'scatter' && isset($s['label_field'])) {
            $spec['label_field'] = $this->campo($s['label_field'], $campos, ['string']);
        }

        return array_filter($spec, fn ($v) => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $s
     * @param  array<string, string>  $campos
     * @return array<string, mixed>
     */
    private function tabla(array $s, array $campos): array
    {
        $columnas = $s['columns'] ?? null;

        if (! is_array($columnas) || ! array_is_list($columnas) || $columnas === [] || count($columnas) > $this->limite('columnas_dataset')) {
            throw new ArtefactoInvalido('Columnas de la tabla inválidas.');
        }

        $limpias = [];

        foreach ($columnas as $columna) {
            $columna = is_array($columna) ? $columna : [];
            $campo = $this->campo($columna['field'] ?? null, $campos, self::TIPOS_COLUMNA);
            $formato = $this->opcion($columna['format'] ?? 'text', self::FORMATOS);

            if ((in_array($formato, self::FORMATOS_NUMERICOS, true) && ! in_array($campos[$campo], self::NUMERICOS, true))
                || ($formato === 'date' && $campos[$campo] !== 'date') || isset($limpias[$campo])) {
                throw new ArtefactoInvalido("Formato {$formato} incompatible o columna repetida: {$campo}.");
            }

            $limpias[$campo] = ['field' => $campo, 'label' => $this->texto($columna['label'] ?? null, 40), 'format' => $formato];
        }

        $spec = ['columns' => array_values($limpias), 'page_size' => (int) $this->opcion($s['page_size'] ?? 10, [10, 25, 50])];

        if (isset($s['default_sort'])) {
            $orden = is_array($s['default_sort']) ? $s['default_sort'] : [];
            $spec['default_sort'] = [
                'field' => $this->campo($orden['field'] ?? null, array_intersect_key($campos, $limpias), self::TIPOS_COLUMNA),
                'direction' => $this->opcion($orden['direction'] ?? 'asc', ['asc', 'desc']),
            ];
        }

        return $spec;
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private function hallazgo(array $s): array
    {
        $vinetas = $s['bullets'] ?? [];

        if (! is_array($vinetas) || ! array_is_list($vinetas) || count($vinetas) > 6) {
            throw new ArtefactoInvalido('Viñetas del hallazgo inválidas.');
        }

        return [
            'text' => $this->texto($s['text'] ?? null, 1200),
            'tone' => $this->opcion($s['tone'] ?? 'info', ['info', 'warning', 'success']),
            'bullets' => array_map(fn ($v) => $this->texto($v, 200), $vinetas),
        ];
    }

    /**
     * @param  array{field: string, label: string, type: string, nullable: bool}  $columna
     */
    private function valor(mixed $valor, array $columna, int $fila): string|int|null
    {
        if ($valor === null) {
            return $columna['nullable'] ? null : throw new ArtefactoInvalido("Fila {$fila}: {$columna['field']} no admite vacío.");
        }

        $error = new ArtefactoInvalido("Fila {$fila}: valor inválido para {$columna['field']} ({$columna['type']}).");

        return match ($columna['type']) {
            'string' => is_string($valor) && mb_strlen($valor) <= 200 ? $this->limpiar($valor) : throw $error,
            'integer' => is_int($valor) ? $valor : (is_string($valor) && preg_match('/^-?\d{1,15}$/', $valor) === 1 ? (int) $valor : throw $error),
            'date' => is_string($valor) && $this->esFecha($valor) ? $valor : throw $error,
            default => is_int($valor) ? (string) $valor : (is_string($valor) && preg_match('/^-?\d{1,15}(\.\d{1,6})?$/', $valor) === 1 ? $valor : throw $error),
        };
    }

    /**
     * @param  array<string, string>  $campos
     * @param  list<string>  $tipos
     */
    private function campo(mixed $campo, array $campos, array $tipos): string
    {
        if (! is_string($campo) || ! isset($campos[$campo]) || ! in_array($campos[$campo], $tipos, true)) {
            throw new ArtefactoInvalido('Campo inexistente o de tipo incompatible: '.(is_string($campo) ? $campo : gettype($campo)).'.');
        }

        return $campo;
    }

    private function formatoNumerico(mixed $formato, string $tipoColumna): string
    {
        $formato ??= match ($tipoColumna) {
            'money' => 'bs',
            'percent' => 'percent',
            'integer' => 'integer',
            default => 'number',
        };

        return (string) $this->opcion($formato, self::FORMATOS_NUMERICOS);
    }

    /**
     * @param  list<string|int>  $permitidas
     */
    private function opcion(mixed $valor, array $permitidas): string|int
    {
        if ((! is_string($valor) && ! is_int($valor)) || ! in_array($valor, $permitidas, true)) {
            throw new ArtefactoInvalido('Opción no permitida: '.(is_scalar($valor) ? (string) $valor : gettype($valor)).'.');
        }

        return $valor;
    }

    private function texto(mixed $valor, int $maximo): string
    {
        if (! is_string($valor)) {
            throw new ArtefactoInvalido('Se esperaba un texto.');
        }

        $limpio = trim($this->limpiar($valor));

        if ($limpio === '' || mb_strlen($limpio) > $maximo) {
            throw new ArtefactoInvalido("Texto vacío o de más de {$maximo} caracteres.");
        }

        return $limpio;
    }

    private function opcional(mixed $valor, int $maximo): ?string
    {
        return $valor === null ? null : $this->texto($valor, $maximo);
    }

    /**
     * Texto plano: sin caracteres de control (salvo saltos de línea) ni UTF-8 inválido.
     */
    private function limpiar(string $valor): string
    {
        if (! mb_check_encoding($valor, 'UTF-8')) {
            throw new ArtefactoInvalido('Texto con codificación inválida.');
        }

        return (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $valor);
    }

    private function fecha(mixed $valor): string
    {
        return is_string($valor) && $this->esFecha($valor) ? $valor : throw new ArtefactoInvalido('Fecha de período inválida.');
    }

    private function esFecha(string $valor): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private function limite(string $clave): int
    {
        return (int) config("agente_mozi.limites.{$clave}");
    }
}
