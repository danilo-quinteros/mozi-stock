<?php

namespace App\Domain\Facturacion\Montos;

/**
 * Montos netos (sin IVA) de una línea de la factura, listos para DetalleCompraVenta. Una línea de la venta
 * puede dar dos líneas fiscales del mismo producto ($indiceVenta indica de cuál viene).
 */
final class LineaFiscal
{
    /**
     * @param  string|null  $montoDescuento  null cuando esta parte de la línea no lleva descuento
     */
    public function __construct(
        public readonly int $indiceVenta,
        public readonly string $cantidad,
        public readonly string $precioNetoUnitario,
        public readonly ?string $montoDescuento,
        public readonly string $subTotal,
    ) {}
}
