<?php

namespace App\Domain\Suscripciones;

use App\Models\Plan;
use App\Models\Suscripcion;
use App\Support\Decimal;
use Carbon\CarbonImmutable;

/**
 * Mensualidad = precio del plan + sucursales extra × precio + paquetes de usuarios × precio
 * (decisión del propietario, 13/11/2026). Los precios de los extras son los del plan.
 *
 * Agregar extras a mitad del período ya pagado cuesta la diferencia mensual por el tiempo que
 * falta hasta el vencimiento: los meses completos más la fracción del mes en curso, por días.
 * Ej.: +Bs 120 el 15/10 con vencimiento 31/10 → 120 × 16/31 = Bs 61,94; con el vencimiento
 * en 31/10/2027 (12 meses adelantados) → 120 × 12 + 61,94 = Bs 1.501,94.
 * En prueba gratis o con la suscripción vencida no hay nada que prorratear: se aplica gratis
 * y el próximo pago ya lo incluye. Aritmética decimal, sin float.
 */
final class CalculadoraExtras
{
    /** Tope por pedido, para evitar errores de tipeo (un plan Empresa se negocia aparte). */
    public const MAXIMO = 99;

    /**
     * @return array{plan: string, sucursales: int, precio_sucursal: string, subtotal_sucursales: string, paquetes: int, precio_paquete: string, subtotal_paquetes: string, extras: string, total: string}
     */
    public static function desglose(string $precioPlan, ?Plan $plan, int $sucursales, int $paquetes): array
    {
        // Un plan sin límite de sucursales (o de usuarios) no cobra ese extra.
        $sucursales = $plan?->sucursales_incluidas === null ? 0 : $sucursales;
        $paquetes = $plan?->usuarios_incluidos === null ? 0 : $paquetes;
        $precioSucursal = (string) ($plan->precio_sucursal_extra ?? '0.00');
        $precioPaquete = (string) ($plan->precio_paquete_usuarios ?? '0.00');
        $subSucursales = Decimal::multiplicar($precioSucursal, (string) $sucursales, Decimal::ESCALA_DINERO);
        $subPaquetes = Decimal::multiplicar($precioPaquete, (string) $paquetes, Decimal::ESCALA_DINERO);
        $extras = Decimal::sumar($subSucursales, $subPaquetes, Decimal::ESCALA_DINERO);

        return [
            'plan' => Decimal::redondear($precioPlan, Decimal::ESCALA_DINERO),
            'sucursales' => $sucursales,
            'precio_sucursal' => Decimal::redondear($precioSucursal, Decimal::ESCALA_DINERO),
            'subtotal_sucursales' => $subSucursales,
            'paquetes' => $paquetes,
            'precio_paquete' => Decimal::redondear($precioPaquete, Decimal::ESCALA_DINERO),
            'subtotal_paquetes' => $subPaquetes,
            'extras' => $extras,
            'total' => Decimal::sumar($precioPlan, $extras, Decimal::ESCALA_DINERO),
        ];
    }

    /**
     * Lo que se paga hoy por pasar de los extras vigentes a los pedidos.
     *
     * @return array{incremento_mensual: string, meses_completos: int, dias_restantes: int, dias_periodo: int, importe: string, motivo_gratis: 'prueba'|'vencida'|null}
     */
    public static function diferencia(Suscripcion $suscripcion, int $sucursales, int $paquetes, ?CarbonImmutable $hoy = null): array
    {
        $hoy = $hoy ?? CalendarioSuscripcion::hoy();
        $plan = $suscripcion->plan;
        $precio = (string) $suscripcion->precio_mensual;
        $actual = self::desglose($precio, $plan, $suscripcion->sucursales_extra, $suscripcion->paquetes_usuarios);
        $nuevo = self::desglose($precio, $plan, $sucursales, $paquetes);
        $incremento = Decimal::restar($nuevo['extras'], $actual['extras'], Decimal::ESCALA_DINERO);
        $resultado = ['incremento_mensual' => $incremento, 'meses_completos' => 0, 'dias_restantes' => 0, 'dias_periodo' => 0, 'importe' => '0.00', 'motivo_gratis' => null];

        if (Decimal::comparar($incremento, '0', 2) <= 0) {
            return $resultado;
        }

        if ($suscripcion->enPrueba($hoy)) {
            return ['motivo_gratis' => 'prueba'] + $resultado;
        }

        $vencimiento = $suscripcion->vencimiento;

        if (! $vencimiento->greaterThan($hoy)) {
            return ['motivo_gratis' => 'vencida'] + $resultado;
        }

        // Meses completos entre hoy y el vencimiento; el resto es el mes en curso.
        $meses = 0;

        while ($vencimiento->subMonthsNoOverflow($meses + 1)->greaterThanOrEqualTo($hoy)) {
            $meses++;
        }

        $finParcial = $vencimiento->subMonthsNoOverflow($meses);
        $inicioParcial = $vencimiento->subMonthsNoOverflow($meses + 1);
        $diasPeriodo = max(1, (int) round($inicioParcial->diffInDays($finParcial)));
        $diasRestantes = (int) round($hoy->diffInDays($finParcial));

        // incremento × (meses × días_periodo + días_restantes) / días_periodo, redondeado una vez.
        $numerador = Decimal::multiplicar($incremento, (string) ($meses * $diasPeriodo + $diasRestantes), 6);
        $importe = Decimal::redondear(Decimal::dividir($numerador, (string) $diasPeriodo, 6), Decimal::ESCALA_DINERO);

        return [
            'incremento_mensual' => $incremento,
            'meses_completos' => $meses,
            'dias_restantes' => $diasRestantes,
            'dias_periodo' => $diasPeriodo,
            'importe' => $importe,
            'motivo_gratis' => null,
        ];
    }
}
