<?php

namespace App\Domain\Reportes;

use Carbon\CarbonImmutable;

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
