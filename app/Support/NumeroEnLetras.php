<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Importe en letras para la representación gráfica de la factura: «Son: Ciento trece 00/100 Bolivianos».
 * Trabaja con el texto decimal (sin float), hasta 999.999.999.999,99.
 */
final class NumeroEnLetras
{
    private const UNIDADES = ['cero', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez',
        'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve', 'veinte',
        'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve'];

    private const DECENAS = [3 => 'treinta', 4 => 'cuarenta', 5 => 'cincuenta', 6 => 'sesenta', 7 => 'setenta', 8 => 'ochenta', 9 => 'noventa'];

    private const CENTENAS = [1 => 'ciento', 2 => 'doscientos', 3 => 'trescientos', 4 => 'cuatrocientos', 5 => 'quinientos',
        6 => 'seiscientos', 7 => 'setecientos', 8 => 'ochocientos', 9 => 'novecientos'];

    public static function bolivianos(string $importe): string
    {
        if (preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/', trim($importe), $partes) !== 1) {
            throw new InvalidArgumentException("Importe inválido para escribir en letras: {$importe}");
        }

        $entero = (int) $partes[1];
        $centavos = str_pad($partes[2] ?? '0', 2, '0');

        // Va en el documento fiscal: siempre en español, sin traducir.
        return self::capitalizar(self::letras($entero))." {$centavos}/100 Bolivianos";
    }

    public static function letras(int $numero): string
    {
        if ($numero < 0 || $numero > 999_999_999_999) {
            throw new InvalidArgumentException('Número fuera de rango.');
        }

        if ($numero === 0) {
            return self::UNIDADES[0];
        }

        $millones = intdiv($numero, 1_000_000);
        $resto = $numero % 1_000_000;
        $texto = [];

        if ($millones > 0) {
            $texto[] = $millones === 1 ? 'un millón' : self::apocope(self::menorDeUnMillon($millones)).' millones';
        }

        if ($resto > 0) {
            $texto[] = self::menorDeUnMillon($resto);
        }

        return implode(' ', $texto);
    }

    private static function menorDeUnMillon(int $numero): string
    {
        $miles = intdiv($numero, 1000);
        $resto = $numero % 1000;
        $texto = [];

        if ($miles > 0) {
            $texto[] = $miles === 1 ? 'mil' : self::apocope(self::menorDeMil($miles)).' mil';
        }

        if ($resto > 0) {
            $texto[] = self::menorDeMil($resto);
        }

        return implode(' ', $texto);
    }

    private static function menorDeMil(int $numero): string
    {
        if ($numero === 100) {
            return 'cien';
        }

        $centenas = intdiv($numero, 100);
        $resto = $numero % 100;
        $texto = $centenas > 0 ? [self::CENTENAS[$centenas]] : [];

        if ($resto > 0) {
            $texto[] = $resto < 30 ? self::UNIDADES[$resto]
                : self::DECENAS[intdiv($resto, 10)].($resto % 10 > 0 ? ' y '.self::UNIDADES[$resto % 10] : '');
        }

        return implode(' ', $texto);
    }

    /** «uno» delante de mil o millones: «veintiún mil», «treinta y un millones». */
    private static function apocope(string $texto): string
    {
        return (string) preg_replace(['/veintiuno$/u', '/uno$/u'], ['veintiún', 'un'], $texto);
    }

    private static function capitalizar(string $texto): string
    {
        return mb_strtoupper(mb_substr($texto, 0, 1)).mb_substr($texto, 1);
    }
}
