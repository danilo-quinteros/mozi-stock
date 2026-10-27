<?php

namespace App\Domain\Ventas;

use App\Support\Decimal;

/**
 * Importes de una devolución. Decisión del propietario (23/10/2026): cada unidad vale lo que
 * realmente se cobró por ella, con el descuento de su línea y la parte prorrateada del
 * descuento global. Con redondeo acumulado, devolver la venta completa suma exactamente su
 * total y nunca se devuelve más de lo vendido. Pantalla y acción usan este mismo cálculo.
 */
final class CalculadoraDevolucion
{
    /**
     * Valor neto de cada línea: su total menos su parte del descuento global, repartido en
     * proporción al total de cada línea. La última línea recibe el resto del descuento, de
     * modo que la suma de netos es exactamente el total de la venta y ningún neto es negativo.
     *
     * @param  array<int, string>  $totales  línea => total de la línea (con su descuento), en el orden de la venta
     * @return array<int, string>
     */
    public static function netosPorLinea(array $totales, string $descuentoGlobal): array
    {
        $descuento = Decimal::redondear($descuentoGlobal);
        $suma = array_reduce($totales, fn (string $s, string $t) => Decimal::sumar($s, $t, 2), '0.00');
        $descuentoRestante = $descuento;
        $totalRestante = $suma;
        $ultimo = array_key_last($totales);
        $netos = [];

        foreach ($totales as $linea => $total) {
            if ($linea === $ultimo) {
                $parte = $descuentoRestante;
            } elseif (Decimal::comparar($suma, '0', 2) === 0) {
                $parte = '0.00';
            } else {
                $parte = Decimal::redondear(Decimal::dividir(Decimal::multiplicar($descuento, $total, 4), $suma, 10));
                // Ni más que la línea ni que el descuento que queda, ni dejar a las siguientes
                // más descuento que su importe: así la última línea nunca queda negativa.
                $minimo = Decimal::restar($total, Decimal::restar($totalRestante, $descuentoRestante, 2), 2);
                $parte = self::mayor(self::menor(self::menor($parte, $total), $descuentoRestante), $minimo);
            }

            $netos[$linea] = Decimal::restar($total, $parte, 2);
            $descuentoRestante = Decimal::restar($descuentoRestante, $parte, 2);
            $totalRestante = Decimal::restar($totalRestante, $total, 2);
        }

        return $netos;
    }

    /**
     * Importe de devolver $cantidad unidades de una línea con neto $neto cuando ya se
     * devolvieron $previas de $vendida. Redondeo acumulado: la suma de todas las devoluciones
     * de la línea nunca supera su neto y devolverla completa da exactamente el neto.
     */
    public static function importe(string $neto, string $vendida, string $previas, string $cantidad): string
    {
        $hasta = fn (string $devueltas): string => Decimal::comparar($devueltas, $vendida) >= 0
            ? Decimal::redondear($neto)
            : Decimal::redondear(Decimal::dividir(Decimal::multiplicar($neto, $devueltas, 5), $vendida, 10));

        return Decimal::restar($hasta(Decimal::sumar($previas, $cantidad)), $hasta($previas), 2);
    }

    private static function mayor(string $a, string $b): string
    {
        return Decimal::comparar($a, $b, 2) >= 0 ? $a : $b;
    }

    private static function menor(string $a, string $b): string
    {
        return Decimal::comparar($a, $b, 2) <= 0 ? $a : $b;
    }
}
