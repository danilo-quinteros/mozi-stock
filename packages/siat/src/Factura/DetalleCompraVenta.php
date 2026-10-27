<?php

namespace MoziStudios\Siat\Factura;

use MoziStudios\Siat\Algoritmos\Decimal;
use MoziStudios\Siat\Excepciones\DatosInvalidos;

/**
 * Línea de la factura compra-venta V3 (IVA transparente): precio NETO unitario, sin impuestos.
 * Los montos se reciben ya calculados por quien integra; este objeto solo valida su forma.
 */
final class DetalleCompraVenta
{
    public readonly string $actividadEconomica;

    public readonly string $codigoProducto;

    public readonly string $descripcion;

    public readonly string $cantidad;

    public readonly string $precioNetoUnitario;

    public readonly string $subTotal;

    public readonly ?string $montoDescuento;

    public readonly ?string $numeroSerie;

    public readonly ?string $numeroImei;

    public function __construct(
        string $actividadEconomica,
        public readonly int $codigoProductoSin,
        string $codigoProducto,
        string $descripcion,
        string $cantidad,
        public readonly int $unidadMedida,
        string $precioNetoUnitario,
        string $subTotal,
        ?string $montoDescuento = null,
        ?string $numeroSerie = null,
        ?string $numeroImei = null,
    ) {
        $this->actividadEconomica = Texto::requerido($actividadEconomica, 'actividadEconomica');
        $this->codigoProducto = Texto::requerido($codigoProducto, 'codigoProducto');
        $this->descripcion = Texto::requerido($descripcion, 'descripcion');
        $this->cantidad = Decimal::normalizar($cantidad, 2, 'cantidad');
        $this->precioNetoUnitario = Decimal::normalizar($precioNetoUnitario, 2, 'precioNetoUnitario');
        $this->subTotal = Decimal::normalizar($subTotal, 2, 'subTotal');
        $this->montoDescuento = $montoDescuento === null ? null : Decimal::normalizar($montoDescuento, 2, 'montoDescuento');
        $this->numeroSerie = Texto::opcional($numeroSerie, 'numeroSerie');
        $this->numeroImei = Texto::opcional($numeroImei, 'numeroImei');

        if ($codigoProductoSin < 1 || $unidadMedida < 1) {
            throw new DatosInvalidos('codigoProductoSin y unidadMedida deben venir de los catálogos del SIN.');
        }
    }
}
