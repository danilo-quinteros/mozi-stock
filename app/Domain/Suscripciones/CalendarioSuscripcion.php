<?php

namespace App\Domain\Suscripciones;

use App\Domain\Reportes\Periodo;
use App\Enums\EstadoSuscripcion;
use App\Enums\EventoAviso;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Calendario de la suscripción mensual. Decisión del propietario (23/10/2026, § 8.4): un
 * pago aprobado avanza el vencimiento los meses pagados **conservando el día de
 * vencimiento**, aunque se pague tarde; los meses atrasados se siguen debiendo. En meses
 * más cortos cae en el último día y después vuelve al día original (31/01 → 28/02 → 31/03).
 *
 * Las fechas de vencimiento son fechas de calendario de La Paz representadas a las 00:00
 * UTC, igual que el cast «date» de Eloquent, para poder compararlas sin conversiones.
 */
final class CalendarioSuscripcion
{
    public const MAX_MESES = 12;

    public static function hoy(): CarbonImmutable
    {
        return self::fecha(CarbonImmutable::now(Periodo::zona())->format('Y-m-d'));
    }

    public static function fecha(string $valor): CarbonImmutable
    {
        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $valor, 'UTC') : null;

        if ($fecha === null || $fecha->format('Y-m-d') !== $valor) {
            throw new InvalidArgumentException("Fecha de calendario no válida: {$valor}");
        }

        return $fecha;
    }

    public static function avanzar(CarbonImmutable $vencimiento, int $diaVencimiento, int $meses): CarbonImmutable
    {
        if ($meses < 1 || $meses > self::MAX_MESES || $diaVencimiento < 1 || $diaVencimiento > 31) {
            throw new InvalidArgumentException('Meses o día de vencimiento fuera de rango.');
        }

        $mes = $vencimiento->startOfMonth()->addMonthsNoOverflow($meses);

        return $mes->setDay(min($diaVencimiento, $mes->daysInMonth));
    }

    /**
     * Días que faltan para el vencimiento: 0 el mismo día, negativos si ya venció.
     */
    public static function diasHasta(CarbonImmutable $vencimiento, ?CarbonImmutable $hoy = null): int
    {
        return (int) round(($hoy ?? self::hoy())->diffInDays($vencimiento));
    }

    public static function estado(CarbonImmutable $vencimiento, ?CarbonImmutable $hoy = null): EstadoSuscripcion
    {
        $dias = self::diasHasta($vencimiento, $hoy);

        return match (true) {
            $dias < 0 => EstadoSuscripcion::Vencida,
            $dias <= EstadoSuscripcion::DIAS_POR_VENCER => EstadoSuscripcion::PorVencer,
            default => EstadoSuscripcion::AlDia,
        };
    }

    public static function fechaEvento(CarbonImmutable $vencimiento, EventoAviso $evento): CarbonImmutable
    {
        return $vencimiento->addDays($evento->desfase());
    }

    /**
     * Aviso cuya ventana incluye hoy, si hay alguno. Las ventanas no se superponen.
     */
    public static function eventoDelDia(CarbonImmutable $vencimiento, CarbonImmutable $hoy): ?EventoAviso
    {
        $respectoDelVencimiento = -self::diasHasta($vencimiento, $hoy);

        foreach (EventoAviso::cases() as $evento) {
            if ($respectoDelVencimiento >= $evento->desfase() && $respectoDelVencimiento <= $evento->hasta()) {
                return $evento;
            }
        }

        return null;
    }
}
