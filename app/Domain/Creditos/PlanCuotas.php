<?php

namespace App\Domain\Creditos;

use App\Support\Decimal;
use Illuminate\Support\Carbon;

/**
 * Propuesta de plan de cuotas: importes iguales (el redondeo va en la última cuota) y
 * vencimientos cada semana, quincena o mes desde la primera fecha. Solo sugiere filas que el
 * usuario puede editar; DefinirCondicionesCredito valida el plan final.
 */
final class PlanCuotas
{
    public const FRECUENCIAS = ['semanal' => 'Semanal', 'quincenal' => 'Quincenal', 'mensual' => 'Mensual'];

    public const MAX_CUOTAS = 60;

    /**
     * @return list<array{vencimiento: string, monto: string}>
     */
    public static function generar(string $saldo, int $cantidad, string $primera, string $frecuencia): array
    {
        $cantidad = max(1, min(self::MAX_CUOTAS, $cantidad));
        $base = Decimal::redondear(Decimal::dividir($saldo, (string) $cantidad, 6));

        // Truncar a centavos para que la última cuota absorba la diferencia sin superar el saldo.
        if (Decimal::comparar(Decimal::multiplicar($base, (string) $cantidad, 2), $saldo, 2) > 0) {
            $base = Decimal::restar($base, '0.01', Decimal::ESCALA_DINERO);
        }

        $inicio = Carbon::parse($primera);
        $filas = [];
        $acumulado = '0.00';

        for ($i = 0; $i < $cantidad; $i++) {
            $monto = $i === $cantidad - 1 ? Decimal::restar($saldo, $acumulado, Decimal::ESCALA_DINERO) : $base;
            $acumulado = Decimal::sumar($acumulado, $monto, Decimal::ESCALA_DINERO);
            $fecha = match ($frecuencia) {
                'semanal' => $inicio->copy()->addWeeks($i),
                'quincenal' => $inicio->copy()->addDays(15 * $i),
                default => $inicio->copy()->addMonthsNoOverflow($i),
            };
            $filas[] = ['vencimiento' => $fecha->format('Y-m-d'), 'monto' => $monto];
        }

        return $filas;
    }
}
