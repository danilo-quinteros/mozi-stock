<?php

namespace MoziStudios\Siat\Constantes;

/** Tipo de factura o documento de ajuste (tipoFacturaDocumento). */
enum TipoFacturaDocumento: int
{
    case ConDerechoCreditoFiscal = 1;
    case SinDerechoCreditoFiscal = 2;
    case DocumentoAjuste = 3;
}
