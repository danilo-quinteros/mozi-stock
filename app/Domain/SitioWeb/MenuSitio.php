<?php

namespace App\Domain\SitioWeb;

/**
 * Textos cortos e iconos de módulos y rubros: los usan el menú, el pie, la página de
 * funcionalidades y los enlaces «relacionados». Iconos: símbolos del sprite x-sitio.iconos.
 */
final class MenuSitio
{
    /**
     * @return array<string, array{icono: string, resumen: string}>
     */
    public static function modulos(): array
    {
        return [
            'facturacion' => ['icono' => 'receipt', 'resumen' => 'Facturas en línea con el SIN desde tus ventas.'],
            'punto-de-venta' => ['icono' => 'cart', 'resumen' => 'Vende y cobra rápido, con código de barras.'],
            'inventario' => ['icono' => 'box', 'resumen' => 'Stock por sucursal, ajustes y traspasos.'],
            'multisucursal' => ['icono' => 'store', 'resumen' => 'Todas tus sucursales en un solo sistema.'],
            'compras' => ['icono' => 'truck', 'resumen' => 'Compras, costos y cuentas por pagar.'],
            'creditos' => ['icono' => 'wallet', 'resumen' => 'Créditos, abonos y saldo por cliente.'],
            'caja' => ['icono' => 'cash', 'resumen' => 'Caja por turno, arqueo y gastos.'],
            'reportes' => ['icono' => 'chart', 'resumen' => 'Lo vendido y lo cobrado, por separado.'],
            'agente-ia' => ['icono' => 'sparkles', 'resumen' => 'Pregunta a Mozi y responde con tus datos.'],
        ];
    }

    /**
     * @return array<string, array{icono: string, resumen: string}>
     */
    public static function rubros(): array
    {
        return [
            'rubro-ferreterias' => ['icono' => 'tool', 'resumen' => 'Miles de productos y precios por volumen.'],
            'rubro-farmacias' => ['icono' => 'pill', 'resumen' => 'Venta rápida y control de stock por sucursal.'],
            'rubro-ropa-calzado' => ['icono' => 'shirt', 'resumen' => 'Modelos, tallas, devoluciones y créditos.'],
            'rubro-minimarkets' => ['icono' => 'basket', 'resumen' => 'Cobro ágil y caja cuadrada cada turno.'],
            'rubro-distribuidoras' => ['icono' => 'truck', 'resumen' => 'Pedidos, mayoristas y cobranza.'],
        ];
    }
}
