<?php

namespace MoziStudios\Siat\Factura;

use MoziStudios\Siat\Algoritmos\Decimal;

/**
 * Comprueba las fórmulas oficiales de compra-venta (anexo «Validaciones Documentos Sector»):
 *   subTotal = cantidad × precioNetoUnitario − montoDescuento
 *   subTotalNeto = Σ subTotal
 *   totalPrecioNeto = subTotalNeto − montoGiftCard − descuentoAdicional
 *   iva = totalPrecioNeto × 0,13
 *   montoaPagar = totalPrecioNeto + iva
 *   montoTotalMoneda = montoaPagar / tipoCambio
 * Cada producto se redondea HALF-UP a 2 decimales una sola vez. No corrige nada: informa diferencias.
 */
final class VerificadorMontos
{
    private const TASA_IVA = '0.13';

    /**
     * @return list<string> diferencias encontradas; vacía si la factura cuadra
     */
    public static function diferencias(FacturaCompraVenta $factura): array
    {
        $diferencias = [];
        $suma = '0.00';

        foreach ($factura->detalles as $indice => $detalle) {
            $bruto = Decimal::multiplicar($detalle->cantidad, $detalle->precioNetoUnitario);
            $esperado = Decimal::redondear(Decimal::restar($bruto, $detalle->montoDescuento ?? '0', 10));

            if (! Decimal::iguales($esperado, $detalle->subTotal)) {
                $diferencias[] = 'Línea '.($indice + 1).": subTotal {$detalle->subTotal}, esperado {$esperado}.";
            }

            $suma = Decimal::sumar($suma, $detalle->subTotal);
        }

        self::comparar($diferencias, 'subTotalNeto', $factura->subTotalNeto, $suma);

        $neto = Decimal::restar(Decimal::restar($factura->subTotalNeto, $factura->montoGiftCard ?? '0'), $factura->descuentoAdicional ?? '0');
        self::comparar($diferencias, 'totalPrecioNeto', $factura->totalPrecioNeto, $neto);

        $iva = Decimal::redondear(Decimal::multiplicar($factura->totalPrecioNeto, self::TASA_IVA));
        self::comparar($diferencias, 'iva', $factura->iva, $iva);

        self::comparar($diferencias, 'montoaPagar', $factura->montoaPagar, Decimal::sumar($factura->totalPrecioNeto, $factura->iva));

        $enMoneda = Decimal::redondear(Decimal::dividir($factura->montoaPagar, $factura->tipoCambio));
        self::comparar($diferencias, 'montoTotalMoneda', $factura->montoTotalMoneda, $enMoneda);

        return $diferencias;
    }

    /**
     * @param  list<string>  $diferencias
     */
    private static function comparar(array &$diferencias, string $campo, string $valor, string $esperado): void
    {
        if (! Decimal::iguales($valor, $esperado)) {
            $diferencias[] = "{$campo} {$valor}, esperado {$esperado}.";
        }
    }
}
