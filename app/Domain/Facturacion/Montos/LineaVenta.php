<?php

namespace App\Domain\Facturacion\Montos;

/**
 * Línea de una venta confirmada tal como la pagó el cliente: precios y descuentos FINALES, con IVA
 * incluido (decisión D1). Es la entrada de CalculadoraFiscal; los valores salen de venta_items.
 */
final class LineaVenta
{
    /**
     * @param  string  $cantidad  cantidad vendida (en facturas se admiten hasta 2 decimales, decisión D5)
     * @param  string  $precioUnitario  precio final por unidad, con IVA
     * @param  string  $descuento  descuento final de la línea, con IVA
     * @param  string  $total  cantidad × precio − descuento, redondeado a 2 decimales
     */
    public function __construct(
        public readonly string $cantidad,
        public readonly string $precioUnitario,
        public readonly string $descuento,
        public readonly string $total,
    ) {}
}
