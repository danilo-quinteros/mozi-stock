<?php

namespace MoziStudios\Siat\Soap;

/** Servicios SOAP V3 usados por el paquete; el valor es la ruta relativa a la URL base (`/sfd/`). */
enum Servicio: string
{
    case Codigos = 'FacturacionCodigos';
    case Sincronizacion = 'FacturacionSincronizacion';
    case Operaciones = 'FacturacionOperaciones';
    case CompraVenta = 'ServicioFacturacionCompraVenta';
}
