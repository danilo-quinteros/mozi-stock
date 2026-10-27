<?php

namespace MoziStudios\Siat\Algoritmos;

use MoziStudios\Siat\Excepciones\DatosInvalidos;

/**
 * Dígito autoverificador módulo 11 del CUF. Traducción literal del algoritmo de referencia publicado
 * por el SIN (calculaDigitoMod11): un resto de 10 agrega «1»; los demás agregan el propio resto.
 */
final class Modulo11
{
    /** Uso del anexo para el CUF: numDig 1, limMult 9, x10 false. */
    public static function digito(string $cadena): string
    {
        return self::calcular($cadena, 1, 9, false);
    }

    public static function calcular(string $cadena, int $numDig, int $limMult, bool $x10): string
    {
        if (preg_match('/^\d+$/', $cadena) !== 1) {
            throw new DatosInvalidos('El módulo 11 solo acepta dígitos.');
        }

        if (! $x10) {
            $numDig = 1;
        }

        for ($n = 1; $n <= $numDig; $n++) {
            $suma = 0;
            $mult = 2;

            for ($i = strlen($cadena) - 1; $i >= 0; $i--) {
                $suma += $mult * (int) $cadena[$i];

                if (++$mult > $limMult) {
                    $mult = 2;
                }
            }

            $dig = $x10 ? (($suma * 10) % 11) % 10 : $suma % 11;

            // La referencia también trata un resto 11 (→ «0»), pero módulo 11 nunca lo produce.
            $cadena .= $dig === 10 ? '1' : (string) $dig;
        }

        return substr($cadena, -$numDig);
    }
}
