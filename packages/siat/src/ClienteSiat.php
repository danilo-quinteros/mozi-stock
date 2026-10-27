<?php

namespace MoziStudios\Siat;

use MoziStudios\Siat\Configuracion\ConfiguracionSistema;
use MoziStudios\Siat\Configuracion\Contribuyente;
use MoziStudios\Siat\Factura\PreparadorFactura;
use MoziStudios\Siat\Factura\ValidadorXsd;
use MoziStudios\Siat\Fecha\Reloj;
use MoziStudios\Siat\Fecha\RelojSistema;
use MoziStudios\Siat\Servicios\ServicioCodigos;
use MoziStudios\Siat\Servicios\ServicioCompraVenta;
use MoziStudios\Siat\Servicios\ServicioOperaciones;
use MoziStudios\Siat\Servicios\ServicioSincronizacion;
use MoziStudios\Siat\Soap\Transporte;

/**
 * Punto de entrada del paquete. Se crea una vez por sistema (configuración del proveedor) y entrega
 * servicios atados a un contribuyente concreto, de modo que cada empresa usa siempre su NIT y su token.
 */
final class ClienteSiat
{
    public function __construct(
        private readonly ConfiguracionSistema $configuracion,
        private readonly Transporte $transporte,
        private readonly Reloj $reloj = new RelojSistema,
    ) {}

    public function configuracion(): ConfiguracionSistema
    {
        return $this->configuracion;
    }

    public function codigos(Contribuyente $contribuyente): ServicioCodigos
    {
        return new ServicioCodigos($this->configuracion, $this->transporte, $contribuyente);
    }

    public function sincronizacion(Contribuyente $contribuyente): ServicioSincronizacion
    {
        return new ServicioSincronizacion($this->configuracion, $this->transporte, $contribuyente);
    }

    public function operaciones(Contribuyente $contribuyente): ServicioOperaciones
    {
        return new ServicioOperaciones($this->configuracion, $this->transporte, $contribuyente);
    }

    public function compraVenta(Contribuyente $contribuyente): ServicioCompraVenta
    {
        return new ServicioCompraVenta($this->configuracion, $this->transporte, $contribuyente, $this->reloj);
    }

    public function preparador(): PreparadorFactura
    {
        return new PreparadorFactura($this->configuracion, ValidadorXsd::compraVentaComputarizada());
    }
}
