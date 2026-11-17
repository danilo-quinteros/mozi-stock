<?php

namespace App\Domain\Produccion;

use App\Support\Decimal;

/**
 * Escalado de una receta a la cantidad de una orden (ESPECIFICACION_PRODUCCION.md § 6.1 y M5):
 *
 *     teórica = cantidad a producir × cantidad de la receta ÷ cantidad base
 *
 * redondeada a 3 decimales (mitad hacia arriba). Las materias primas que llevan cantidades enteras (unidad sin
 * decimales o venta por unidad, como en LibroStock) se redondean hacia arriba al entero: 1,5 botellas → 2.
 * Cuentas exactas con bcmath, nunca float.
 */
final class Escalado
{
    public static function esEntera(?string $tipoVenta, bool $admiteDecimales): bool
    {
        return ($tipoVenta ?? 'unidad') === 'unidad' || ! $admiteDecimales;
    }

    /**
     * @return numeric-string con 3 decimales
     */
    public static function teorica(string $planificada, string $base, string $cantidadReceta, bool $entera): string
    {
        // Las dos cantidades tienen 3 decimales: el producto es exacto con 6.
        $producto = Decimal::multiplicar($planificada, $cantidadReceta, 6);

        if (! $entera) {
            return Decimal::redondear(Decimal::dividir($producto, $base, 9), 3);
        }

        // Techo exacto: el cociente entero, más uno si la división deja resto.
        $piso = Decimal::dividir($producto, $base, 0);
        $resto = Decimal::comparar(Decimal::multiplicar($piso, $base, 6), $producto, 6) < 0;

        return Decimal::sumar($piso, $resto ? '1' : '0', 3);
    }

    /**
     * Diferencia entre lo obtenido y lo planificado, en porcentaje con 1 decimal (negativa = merma).
     */
    public static function variacion(string $planificada, string $obtenida): string
    {
        $diferencia = Decimal::restar($obtenida, $planificada);

        return Decimal::redondear(Decimal::dividir(Decimal::multiplicar($diferencia, '100', 6), $planificada, 6), 1);
    }
}
