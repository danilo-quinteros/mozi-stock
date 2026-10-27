<?php

namespace MoziStudios\Siat\Factura;

use MoziStudios\Siat\Excepciones\DatosInvalidos;

/**
 * Limpieza mínima de textos del XML: UTF-8 válido y sin caracteres de control que XML 1.0 no admite.
 * Las longitudes las controla el XSD oficial. El escape de &, < y > lo hace DOM al generar.
 *
 * @internal
 */
final class Texto
{
    public static function requerido(string $valor, string $campo): string
    {
        $texto = self::opcional($valor, $campo);

        if ($texto === null) {
            throw new DatosInvalidos("{$campo} es obligatorio.");
        }

        return $texto;
    }

    public static function opcional(?string $valor, string $campo): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim($valor);

        if (preg_match('//u', $texto) !== 1) {
            throw new DatosInvalidos("{$campo} no es texto UTF-8 válido.");
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $texto) === 1) {
            throw new DatosInvalidos("{$campo} contiene caracteres de control no permitidos.");
        }

        return $texto === '' ? null : $texto;
    }
}
