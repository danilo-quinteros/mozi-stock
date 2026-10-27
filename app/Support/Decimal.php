<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Aritmética y formato decimal sin float (bcmath). Dinero con 2 decimales,
 * cantidades de inventario con 3. Todo valor se verifica como numérico antes de operar.
 */
final class Decimal
{
    public const ESCALA_DINERO = 2;

    public const ESCALA_CANTIDAD = 3;

    /**
     * Acepta "12", "12.5" o "12,5" (sin separador de miles). Devuelve el valor
     * canónico con la escala indicada, o null si no es un número válido o tiene más decimales.
     *
     * @return numeric-string|null
     */
    public static function normalizar(string|int|null $valor, int $escala): ?string
    {
        $texto = str_replace(',', '.', trim((string) $valor));

        if (preg_match('/^-?\d{1,15}(\.\d{1,'.$escala.'})?$/', $texto) !== 1) {
            return null;
        }

        return bcadd(self::numero($texto), '0', $escala);
    }

    /**
     * @return numeric-string
     */
    public static function sumar(string $a, string $b, int $escala = self::ESCALA_CANTIDAD): string
    {
        return bcadd(self::numero($a), self::numero($b), $escala);
    }

    /**
     * @return numeric-string
     */
    public static function restar(string $a, string $b, int $escala = self::ESCALA_CANTIDAD): string
    {
        return bcsub(self::numero($a), self::numero($b), $escala);
    }

    /**
     * @return numeric-string
     */
    public static function multiplicar(string $a, string $b, int $escala = self::ESCALA_CANTIDAD): string
    {
        return bcmul(self::numero($a), self::numero($b), $escala);
    }

    /**
     * @return numeric-string
     */
    public static function dividir(string $a, string $b, int $escala = 6): string
    {
        return bcdiv(self::numero($a), self::numero($b), $escala);
    }

    /**
     * Redondeo comercial (mitad alejándose de cero) a la escala indicada.
     *
     * @return numeric-string
     */
    public static function redondear(string $valor, int $escala = self::ESCALA_DINERO): string
    {
        $numero = self::numero($valor);
        $mitad = self::numero((str_starts_with($numero, '-') ? '-' : '').'0.'.str_repeat('0', $escala).'5');

        return bcadd($numero, $mitad, $escala);
    }

    public static function comparar(string $a, string $b, int $escala = self::ESCALA_CANTIDAD): int
    {
        return bccomp(self::numero($a), self::numero($b), $escala);
    }

    public static function esEntero(string $valor): bool
    {
        $numero = self::numero($valor);

        return bccomp($numero, bcadd($numero, '0', 0), self::ESCALA_CANTIDAD) === 0;
    }

    /**
     * Valor editable en un campo: sin separador de miles ni ceros sobrantes ("1000", "2.5").
     */
    public static function simple(string $valor): string
    {
        return str_contains($valor, '.') ? rtrim(rtrim($valor, '0'), '.') : $valor;
    }

    /**
     * Formato boliviano: miles con punto y decimales con coma, sin ceros sobrantes.
     */
    public static function formatear(string|int|null $valor, int $minDecimales = 0, int $maxDecimales = self::ESCALA_CANTIDAD): string
    {
        $canonico = bcadd(self::numero($valor ?? '0'), '0', $maxDecimales);
        $negativo = str_starts_with($canonico, '-');
        [$entero, $decimales] = array_pad(explode('.', ltrim($canonico, '-')), 2, '');
        $decimales = rtrim($decimales, '0');
        $decimales = str_pad($decimales, $minDecimales, '0');
        $entero = strrev(implode('.', str_split(strrev($entero), 3)));

        return ($negativo ? '−' : '').$entero.($decimales === '' ? '' : ','.$decimales);
    }

    public static function bs(string|int|null $valor): string
    {
        return 'Bs '.self::formatear($valor, self::ESCALA_DINERO, self::ESCALA_DINERO);
    }

    /**
     * @return numeric-string
     */
    private static function numero(string|int $valor): string
    {
        $texto = (string) $valor;

        if (! is_numeric($texto)) {
            throw new InvalidArgumentException("Valor decimal inválido: «{$texto}».");
        }

        return $texto;
    }
}
