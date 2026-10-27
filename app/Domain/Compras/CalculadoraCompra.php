<?php

namespace App\Domain\Compras;

use App\Support\Decimal;

/**
 * Importes de una compra: subtotal = cantidad × costo unitario, redondeado a céntimos por
 * línea; total = suma de subtotales. Pantalla y acción usan este mismo cálculo.
 */
final class CalculadoraCompra
{
    public static function subtotal(string $cantidad, string $costoUnitario): string
    {
        return Decimal::redondear(Decimal::multiplicar($cantidad, $costoUnitario, 5));
    }
}
