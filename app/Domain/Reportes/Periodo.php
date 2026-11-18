<?php

namespace App\Domain\Reportes;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Rango de días de negocio (America/La_Paz). Los timestamps se guardan en UTC; un día de
 * reporte va de 00:00 a 24:00 hora de La Paz. Bolivia no aplica horario de verano, por lo
 * que el desfase es fijo y CONVERT_TZ funciona sin tablas de zonas horarias en MySQL.
 */
final class Periodo
{
    public const MAX_DIAS = 366;

    private function __construct(
        public readonly CarbonImmutable $desde,
        public readonly CarbonImmutable $hasta,
    ) {}

    public static function zona(): string
    {
        return (string) config('mozistock.business_timezone');
    }

    public static function hoy(): self
    {
        $hoy = CarbonImmutable::now(self::zona())->startOfDay();

        return new self($hoy, $hoy);
    }

    /**
     * Del primer día del mes actual hasta hoy.
     */
    public static function mesActual(): self
    {
        $hoy = CarbonImmutable::now(self::zona())->startOfDay();

        return new self($hoy->startOfMonth(), $hoy);
    }

    /**
     * Rangos predefinidos del selector de fechas de Reportes (clave => etiqueta). Los «hasta hoy» terminan hoy; los
     * «pasados» son el período calendario completo anterior. La semana empieza el lunes.
     */
    public const PREAJUSTES = [
        'hoy' => 'Hoy',
        'ayer' => 'Ayer',
        'semana' => 'Esta semana hasta hoy',
        'semana_pasada' => 'La semana pasada',
        'mes' => 'Este mes hasta hoy',
        'mes_pasado' => 'El mes pasado',
        'trimestre' => 'Este trimestre hasta hoy',
        'trimestre_pasado' => 'El trimestre pasado',
        'anio' => 'Este año hasta hoy',
        'anio_pasado' => 'El año pasado',
    ];

    /**
     * Rango de un preajuste calculado hoy en la zona de negocio; null si la clave no existe.
     */
    public static function preajuste(string $clave): ?self
    {
        $hoy = CarbonImmutable::now(self::zona())->startOfDay();
        $lunes = $hoy->startOfWeek(CarbonInterface::MONDAY);
        $trimestre = $hoy->startOfQuarter();

        return match ($clave) {
            'hoy' => new self($hoy, $hoy),
            'ayer' => new self($hoy->subDay(), $hoy->subDay()),
            'semana' => new self($lunes, $hoy),
            'semana_pasada' => new self($lunes->subWeek(), $lunes->subDay()),
            'mes' => new self($hoy->startOfMonth(), $hoy),
            'mes_pasado' => new self($hoy->startOfMonth()->subMonthNoOverflow(), $hoy->startOfMonth()->subDay()),
            'trimestre' => new self($trimestre, $hoy),
            'trimestre_pasado' => new self($trimestre->subMonthsNoOverflow(3), $trimestre->subDay()),
            'anio' => new self($hoy->startOfYear(), $hoy),
            'anio_pasado' => new self($hoy->startOfYear()->subYearNoOverflow(), $hoy->startOfYear()->subDay()),
            default => null,
        };
    }

    public static function dia(?string $fecha): self
    {
        return self::rango($fecha, $fecha);
    }

    /**
     * Fechas Y-m-d del navegador; inválidas o vacías = hoy. Ordena y limita a un año.
     */
    public static function rango(?string $desde, ?string $hasta): self
    {
        $inicio = self::fecha($desde);
        $fin = self::fecha($hasta);

        if ($inicio->greaterThan($fin)) {
            [$inicio, $fin] = [$fin, $inicio];
        }

        if ($inicio->diffInDays($fin) > self::MAX_DIAS) {
            $inicio = $fin->subDays(self::MAX_DIAS);
        }

        return new self($inicio, $fin);
    }

    /**
     * Límites UTC [inicio, fin) para filtrar columnas de fecha y hora.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function limitesUtc(): array
    {
        return [$this->desde->utc(), $this->hasta->addDay()->utc()];
    }

    /**
     * Desfase de la zona de negocio, por ejemplo "-04:00", para agrupar por día en SQL.
     */
    public function desfase(): string
    {
        return $this->desde->format('P');
    }

    public function esUnDia(): bool
    {
        return $this->desde->equalTo($this->hasta);
    }

    private static function fecha(?string $valor): CarbonImmutable
    {
        if (is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1) {
            $fecha = CarbonImmutable::createFromFormat('!Y-m-d', $valor, self::zona());

            if ($fecha !== null && $fecha->format('Y-m-d') === $valor) {
                return $fecha;
            }
        }

        return CarbonImmutable::now(self::zona())->startOfDay();
    }
}
