<?php

namespace App\Domain\AgenteMozi\Herramientas;

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\Datos\ConsultaDatos;
use App\Domain\AgenteMozi\DTOs\DatasetPropuesto;
use App\Domain\Reportes\Periodo;
use App\Enums\Rol;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Base de las herramientas con datos reales (UC-11 a UC-18 y la consulta genérica): período en días
 * de La Paz, alcance por rol (el vendedor queda en su sucursal), metadatos de procedencia y columnas
 * tipadas. Los cálculos reutilizan las consultas de los módulos; los importes viajan como texto.
 */
abstract class HerramientaNegocio implements Herramienta
{
    public function permiso(): ?string
    {
        return 'ver-reportes';
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected function propiedadesPeriodo(string $porDefecto): array
    {
        return [
            'date_from' => ['type' => 'string', 'description' => "Primer día (AAAA-MM-DD). Por defecto {$porDefecto}."],
            'date_to' => ['type' => 'string', 'description' => 'Último día (AAAA-MM-DD), incluido. Por defecto hoy. Máximo un año.'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    protected function reglasPeriodo(): array
    {
        return [
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Período pedido o, sin fechas, los últimos $dias días hasta hoy (0 = mes en curso).
     *
     * @param  array<string, mixed>  $argumentos
     */
    protected function periodo(ContextoAgente $contexto, array $argumentos, int $dias): Periodo
    {
        $hasta = is_string($argumentos['date_to'] ?? null) ? $argumentos['date_to'] : $contexto->fechaReferencia->format('Y-m-d');
        $fin = Periodo::dia($hasta)->hasta;
        $desde = is_string($argumentos['date_from'] ?? null) ? $argumentos['date_from']
            : ($dias === 0 ? $fin->startOfMonth() : $fin->subDays($dias - 1))->format('Y-m-d');

        return Periodo::rango($desde, $fin->format('Y-m-d'));
    }

    /** Período inmediatamente anterior y de igual duración, para comparar. */
    protected function anterior(Periodo $periodo): Periodo
    {
        $dias = (int) $periodo->desde->diffInDays($periodo->hasta);
        $hasta = $periodo->desde->subDay();

        return Periodo::rango($hasta->subDays($dias)->format('Y-m-d'), $hasta->format('Y-m-d'));
    }

    /** Sucursal fija del vendedor; null para el administrador (toda la empresa). */
    protected function sucursalVendedor(ContextoAgente $contexto): ?int
    {
        return $contexto->rol === Rol::Administrador ? null : ($contexto->sucursalId ?? 0);
    }

    protected function alcance(ContextoAgente $contexto): string
    {
        $sucursal = $this->sucursalVendedor($contexto);

        if ($sucursal === null) {
            return __('Todas las sucursales');
        }

        $nombre = DB::table('sucursales')->where('empresa_id', $contexto->empresaId)->where('id', $sucursal)->value('nombre');

        return is_string($nombre) ? mb_substr(__('Sucursal :nombre', ['nombre' => $nombre]), 0, 120) : __('Sin sucursal asignada');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function meta(ContextoAgente $contexto, string $fuente, ?Periodo $periodo, string $calidad = 'actual', ?string $metodo = null, array $extra = []): array
    {
        return array_filter($extra + [
            'quality' => $calidad,
            'source' => "mozistock.{$fuente}",
            'scope' => $this->alcance($contexto),
            'period' => $periodo === null ? null : ['from' => $periodo->desde->format('Y-m-d'), 'to' => $periodo->hasta->format('Y-m-d')],
            'method' => $metodo === null ? null : mb_substr($metodo, 0, 240),
            'calculated_at' => $contexto->fechaReferencia->toIso8601String(),
            'currency' => 'BOB',
            'timezone' => $contexto->zonaHoraria,
        ], fn ($valor) => $valor !== null);
    }

    /**
     * @return array{field: string, label: string, type: string, nullable: bool}
     */
    protected function col(string $campo, string $etiqueta, string $tipo): array
    {
        return ['field' => $campo, 'label' => $etiqueta, 'type' => $tipo, 'nullable' => true];
    }

    /**
     * Arma el dataset convirtiendo cada valor al tipo de su columna (texto decimal, nunca float).
     *
     * @param  list<array{field: string, label: string, type: string, nullable: bool}>  $columnas
     * @param  iterable<array<string, mixed>|object>  $filas
     * @param  array<string, mixed>  $meta
     * @return array{dataset: DatasetPropuesto, descripcion: string}
     */
    protected function dataset(string $clave, array $columnas, iterable $filas, array $meta, string $descripcion): array
    {
        $tipos = array_column($columnas, 'type', 'field');
        $limpias = [];

        foreach ($filas as $fila) {
            $valores = (array) $fila;
            $limpia = [];

            foreach ($tipos as $campo => $tipo) {
                $limpia[$campo] = ConsultaDatos::valor($valores[$campo] ?? null, $tipo);
            }

            $limpias[] = $limpia;
        }

        return ['dataset' => new DatasetPropuesto($clave, $columnas, $limpias, $meta), 'descripcion' => $descripcion];
    }

    /** Variación porcentual con un decimal; null si la base es 0 (no hay contra qué comparar). */
    protected function variacion(string $actual, string $anterior): ?string
    {
        return Decimal::comparar($anterior, '0', 2) === 0 ? null
            : Decimal::redondear(Decimal::dividir(Decimal::multiplicar(Decimal::restar($actual, $anterior, 2), '100', 2), $anterior, 4), 1);
    }

    /** Parte de un total en porcentaje con un decimal; null si el total es 0. */
    protected function porcentaje(string $parte, string $total): ?string
    {
        return Decimal::comparar($total, '0', 2) <= 0 ? null
            : Decimal::redondear(Decimal::dividir(Decimal::multiplicar($parte, '100', 3), $total, 4), 1);
    }

    /**
     * Columna UTC en hora local; el desfase va como binding (desfase()), como en Reportes.
     *
     * @param  literal-string  $columna
     * @return literal-string
     */
    protected function local(string $columna): string
    {
        return "CONVERT_TZ({$columna}, '+00:00', ?)";
    }

    protected function desfase(ContextoAgente $contexto): string
    {
        return $contexto->fechaReferencia->format('P');
    }
}
