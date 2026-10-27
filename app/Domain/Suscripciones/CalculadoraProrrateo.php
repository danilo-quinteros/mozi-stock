<?php

namespace App\Domain\Suscripciones;

use App\Models\Plan;
use App\Models\Suscripcion;
use App\Support\Decimal;
use Carbon\CarbonImmutable;

/**
 * Calculadora de prorrateo para cambios de plan de suscripción.
 *
 * Reglas rectoras:
 * - Si sube de plan (upgrade): paga únicamente la diferencia proporcional por los días restantes
 *   del período actual. La próxima renovación pagará el precio completo del nuevo plan.
 * - Si baja de plan (downgrade): conserva los beneficios del plan actual hasta su vencimiento
 *   y aplica el nuevo plan con su precio reducido en la siguiente renovación (sin cobro hoy).
 * - En ambos casos, las fechas de vencimiento originales se mantienen sin alteraciones.
 * - Toda la aritmética monetaria se realiza mediante Decimal sin usar floats.
 */
final class CalculadoraProrrateo
{
    /**
     * @return array{
     *     tipo: 'upgrade'|'downgrade'|'igual',
     *     precio_actual: string,
     *     precio_nuevo: string,
     *     diferencia_mensual: string,
     *     dias_restantes: int,
     *     dias_periodo: int,
     *     monto_prorrateado: string,
     *     precio_proxima_renovacion: string,
     *     vencimiento: string
     * }
     */
    public static function calcular(Suscripcion $suscripcion, Plan $nuevoPlan, ?CarbonImmutable $hoy = null): array
    {
        $hoy = $hoy ?? CalendarioSuscripcion::hoy();
        $precioActual = (string) ($suscripcion->plan !== null ? $suscripcion->plan->precio_mensual : $suscripcion->precio_mensual);
        $precioNuevo = (string) $nuevoPlan->precio_mensual;

        $comparacion = Decimal::comparar($precioNuevo, $precioActual, 2);

        $vencimiento = $suscripcion->vencimiento;
        $diasHastaVencimiento = CalendarioSuscripcion::diasHasta($vencimiento, $hoy);

        // Duración del período en días (mes previo al vencimiento)
        $inicioPeriodo = $vencimiento->subMonthsNoOverflow(1);
        $diasPeriodo = max(1, (int) round($inicioPeriodo->diffInDays($vencimiento)));

        // Días restantes en el período acotados entre 0 y los días del período
        $diasRestantes = min($diasPeriodo, max(0, $diasHastaVencimiento));

        if ($comparacion > 0) {
            $tipo = 'upgrade';
            $diferencia = Decimal::restar($precioNuevo, $precioActual, Decimal::ESCALA_DINERO);

            if ($diasRestantes > 0) {
                // (diferencia * dias_restantes) / dias_periodo con redondeo comercial a centavos
                $numerador = Decimal::multiplicar($diferencia, (string) $diasRestantes, 6);
                $cociente = Decimal::dividir($numerador, (string) $diasPeriodo, 6);
                $montoProrrateado = Decimal::redondear($cociente, Decimal::ESCALA_DINERO);
            } else {
                $montoProrrateado = '0.00';
            }
        } elseif ($comparacion < 0) {
            $tipo = 'downgrade';
            $diferencia = Decimal::restar($precioActual, $precioNuevo, Decimal::ESCALA_DINERO);
            $montoProrrateado = '0.00';
        } else {
            $tipo = 'igual';
            $diferencia = '0.00';
            $montoProrrateado = '0.00';
        }

        return [
            'tipo' => $tipo,
            'precio_actual' => $precioActual,
            'precio_nuevo' => $precioNuevo,
            'diferencia_mensual' => $diferencia,
            'dias_restantes' => $diasRestantes,
            'dias_periodo' => $diasPeriodo,
            'monto_prorrateado' => $montoProrrateado,
            'precio_proxima_renovacion' => $precioNuevo,
            'vencimiento' => $vencimiento->format('Y-m-d'),
        ];
    }
}
