<?php

namespace MoziStudios\Siat\Constantes;

/**
 * Modalidad de facturación (codigoModalidad). El paquete solo emite en modalidad computarizada;
 * electrónica existe para el cálculo del CUF y para rechazarla con un mensaje claro.
 */
enum Modalidad: int
{
    case Electronica = 1;
    case Computarizada = 2;
}
