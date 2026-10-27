<?php

namespace MoziStudios\Siat\Algoritmos;

use MoziStudios\Siat\Excepciones\DatosInvalidos;

/**
 * Conversión entre un entero decimal de longitud arbitraria (54 dígitos en el CUF) y hexadecimal en
 * mayúsculas, como BigInteger.ToString("X") del anexo. Usa bcmath: los enteros de PHP no alcanzan.
 */
final class Base16
{
    public static function codificar(string $decimal): string
    {
        if (preg_match('/^\d+$/', $decimal) !== 1 || ! is_numeric($decimal)) {
            throw new DatosInvalidos('Base 16 solo acepta un entero decimal sin signo.');
        }

        $valor = bcadd($decimal, '0', 0);

        if ($valor === '0') {
            return '0';
        }

        $hex = '';

        while (bccomp($valor, '0', 0) > 0) {
            $hex = dechex((int) bcmod($valor, '16', 0)).$hex;
            $valor = bcdiv($valor, '16', 0);
        }

        return strtoupper($hex);
    }

    public static function decodificar(string $hex): string
    {
        if (preg_match('/^[0-9A-Fa-f]+$/', $hex) !== 1) {
            throw new DatosInvalidos('El valor no es hexadecimal.');
        }

        $decimal = '0';

        foreach (str_split(strtoupper($hex)) as $caracter) {
            $decimal = bcadd(bcmul($decimal, '16', 0), (string) hexdec($caracter), 0);
        }

        return $decimal;
    }
}
