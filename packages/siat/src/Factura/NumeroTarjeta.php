<?php

namespace MoziStudios\Siat\Factura;

use MoziStudios\Siat\Excepciones\DatosInvalidos;

/**
 * Número de tarjeta para el XML: primeros 4 y últimos 4 dígitos en claro y ceros al medio
 * (ejemplo del anexo: 4797000000007896). Nunca se recibe ni se guarda el número completo.
 */
final class NumeroTarjeta
{
    public static function ofuscado(string $primeros4, string $ultimos4): string
    {
        if (preg_match('/^\d{4}$/', $primeros4) !== 1 || preg_match('/^\d{4}$/', $ultimos4) !== 1) {
            throw new DatosInvalidos('Indica exactamente los primeros 4 y los últimos 4 dígitos de la tarjeta.');
        }

        return $primeros4.'00000000'.$ultimos4;
    }
}
