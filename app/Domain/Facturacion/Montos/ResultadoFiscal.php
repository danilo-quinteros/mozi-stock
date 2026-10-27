<?php

namespace App\Domain\Facturacion\Montos;

/**
 * Resultado de CalculadoraFiscal. Si $cuadra es false la factura no se emite: $motivo explica por qué
 * y, cuando el problema es el total, $totalInferior y $totalSuperior son los totales facturables vecinos.
 */
final class ResultadoFiscal
{
    /**
     * @param  list<LineaFiscal>  $lineas
     */
    private function __construct(
        public readonly bool $cuadra,
        public readonly ?string $motivo,
        public readonly array $lineas,
        public readonly string $subTotalNeto,
        public readonly ?string $descuentoAdicional,
        public readonly string $totalPrecioNeto,
        public readonly string $iva,
        public readonly string $montoaPagar,
        public readonly ?string $totalInferior = null,
        public readonly ?string $totalSuperior = null,
    ) {}

    /**
     * @param  list<LineaFiscal>  $lineas
     */
    public static function cuadra(array $lineas, string $subTotalNeto, ?string $descuentoAdicional, string $totalPrecioNeto, string $iva, string $montoaPagar): self
    {
        return new self(true, null, $lineas, $subTotalNeto, $descuentoAdicional, $totalPrecioNeto, $iva, $montoaPagar);
    }

    public static function bloqueada(string $motivo, string $total, ?string $inferior = null, ?string $superior = null): self
    {
        return new self(false, $motivo, [], '0.00', null, '0.00', '0.00', $total, $inferior, $superior);
    }
}
