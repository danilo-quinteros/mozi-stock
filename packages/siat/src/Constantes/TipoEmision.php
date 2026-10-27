<?php

namespace MoziStudios\Siat\Constantes;

/** Tipo de emisión (codigoEmision): en línea, fuera de línea (contingencia) o masiva. */
enum TipoEmision: int
{
    case EnLinea = 1;
    case FueraDeLinea = 2;
    case Masiva = 3;
}
