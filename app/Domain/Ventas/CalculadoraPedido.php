<?php

namespace App\Domain\Ventas;

use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Cálculo único de importes de pedidos y ventas (pantalla, guardado y confirmación usan
 * esta misma clase). Reglas aprobadas por el propietario (23/10/2026):
 *  - subtotal de línea = cantidad × precio de lista, redondeado a 2 decimales;
 *  - descuento de línea en Bs o en % del subtotal de esa línea;
 *  - descuento global en Bs o en % de la suma de líneas ya descontadas;
 *  - cada descuento se redondea a 2 decimales (mitad hacia arriba) y no puede superar su base.
 */
final class CalculadoraPedido
{
    /**
     * @param  list<array{cantidad: string, precio_unitario: string, descuento_tipo: string, descuento_valor: string}>  $lineas  valores ya normalizados
     * @return array{lineas: list<array{subtotal: string, descuento: string, total: string}>, subtotal: string, descuento_lineas: string, descuento_global: string, total: string}
     *
     * @throws ValidationException
     */
    public static function calcular(array $lineas, string $globalTipo, string $globalValor): array
    {
        $resultado = [];
        $subtotal = '0';
        $descuentos = '0';
        $base = '0';

        foreach ($lineas as $indice => $linea) {
            $bruto = Decimal::redondear(Decimal::multiplicar($linea['cantidad'], $linea['precio_unitario'], 6));
            $descuento = self::descuento($linea['descuento_tipo'], $linea['descuento_valor'], $bruto, "lineas.{$indice}.descuento_valor");
            $neto = Decimal::restar($bruto, $descuento, Decimal::ESCALA_DINERO);
            $resultado[] = ['subtotal' => $bruto, 'descuento' => $descuento, 'total' => $neto];
            $subtotal = Decimal::sumar($subtotal, $bruto, Decimal::ESCALA_DINERO);
            $descuentos = Decimal::sumar($descuentos, $descuento, Decimal::ESCALA_DINERO);
            $base = Decimal::sumar($base, $neto, Decimal::ESCALA_DINERO);
        }

        $global = self::descuento($globalTipo, $globalValor, $base, 'descuento_global_valor');

        return [
            'lineas' => $resultado,
            'subtotal' => $subtotal,
            'descuento_lineas' => $descuentos,
            'descuento_global' => $global,
            'total' => Decimal::restar($base, $global, Decimal::ESCALA_DINERO),
        ];
    }

    /**
     * @return numeric-string
     */
    private static function descuento(string $tipo, string $valor, string $base, string $campo): string
    {
        if (! in_array($tipo, ['bs', 'pct'], true)) {
            throw ValidationException::withMessages([$campo => __('Elige un descuento en Bs o en %.')]);
        }

        if (Decimal::comparar($valor, '0', Decimal::ESCALA_DINERO) < 0) {
            throw ValidationException::withMessages([$campo => __('Un descuento no puede ser negativo.')]);
        }

        if ($tipo === 'pct') {
            if (Decimal::comparar($valor, '100', Decimal::ESCALA_DINERO) > 0) {
                throw ValidationException::withMessages([$campo => __('Un descuento no puede superar el 100 %.')]);
            }

            return Decimal::redondear(Decimal::dividir(Decimal::multiplicar($base, $valor, 6), '100'));
        }

        if (Decimal::comparar($valor, $base, Decimal::ESCALA_DINERO) > 0) {
            throw ValidationException::withMessages([$campo => __('Un descuento no puede superar el importe (:importe).', ['importe' => Decimal::bs($base)])]);
        }

        return Decimal::redondear($valor);
    }
}
