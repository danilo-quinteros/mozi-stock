<?php

namespace MoziStudios\Siat\Algoritmos;

use MoziStudios\Siat\Excepciones\DatosInvalidos;

/**
 * Aritmética decimal exacta (bcmath, nunca float) para montos y cantidades del XML.
 * El SIN expresa los montos con 2 decimales y redondea HALF-UP (anexo «Algoritmo de redondeo»).
 */
final class Decimal
{
    /**
     * Valida un valor no negativo con hasta `$decimales` decimales y lo devuelve con esa escala exacta.
     * Acepta solo punto decimal y sin separador de miles: el formato lo decide quien integra, no este paquete.
     *
     * @return numeric-string
     */
    public static function normalizar(string|int $valor, int $decimales, string $campo): string
    {
        $texto = trim((string) $valor);

        if (preg_match('/^\d{1,15}(\.\d{1,'.$decimales.'})?$/', $texto) !== 1) {
            throw new DatosInvalidos("{$campo}: «{$texto}» debe ser un número no negativo con hasta {$decimales} decimales.");
        }

        return bcadd(self::numero($texto), '0', $decimales);
    }

    /**
     * Redondeo tradicional HALF-UP: 3.14559 → 3.15 y 3.14159 → 3.14 (ejemplos del anexo).
     *
     * @return numeric-string
     */
    public static function redondear(string $valor, int $decimales = 2): string
    {
        $numero = self::numero($valor);
        $negativo = str_starts_with($numero, '-');
        $ajuste = self::numero('0.'.str_repeat('0', $decimales).'5');
        // bcadd trunca a la escala pedida: truncar |x| + 0,005 equivale a redondear hacia arriba desde la mitad.
        $resultado = bcadd(ltrim($numero, '-'), $ajuste, $decimales);

        return $negativo && bccomp($resultado, '0', $decimales) !== 0 ? self::numero('-'.$resultado) : $resultado;
    }

    /**
     * @return numeric-string
     */
    public static function sumar(string $a, string $b, int $escala = 2): string
    {
        return bcadd(self::numero($a), self::numero($b), $escala);
    }

    /**
     * @return numeric-string
     */
    public static function restar(string $a, string $b, int $escala = 2): string
    {
        return bcsub(self::numero($a), self::numero($b), $escala);
    }

    /**
     * Producto exacto (escala completa) para redondear después una sola vez.
     *
     * @return numeric-string
     */
    public static function multiplicar(string $a, string $b): string
    {
        return bcmul(self::numero($a), self::numero($b), 10);
    }

    /**
     * Cociente con escala amplia para redondear después una sola vez.
     *
     * @return numeric-string
     */
    public static function dividir(string $a, string $b): string
    {
        if (bccomp(self::numero($b), '0', 10) === 0) {
            throw new DatosInvalidos('División entre cero.');
        }

        return bcdiv(self::numero($a), self::numero($b), 10);
    }

    public static function iguales(string $a, string $b, int $escala = 2): bool
    {
        return bccomp(self::numero($a), self::numero($b), $escala) === 0;
    }

    /**
     * @return numeric-string
     */
    private static function numero(string $valor): string
    {
        if (preg_match('/^-?\d+(\.\d+)?$/', $valor) !== 1 || ! is_numeric($valor)) {
            throw new DatosInvalidos("«{$valor}» no es un número decimal válido.");
        }

        return $valor;
    }
}
