<?php

namespace MoziStudios\Siat\Constantes;

/** Constantes de ambiente de la solicitud de autorización (codigoAmbiente). */
enum Ambiente: int
{
    case Produccion = 1;
    case Pruebas = 2;
}
