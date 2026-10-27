<?php

namespace MoziStudios\Siat\Cuf;

use MoziStudios\Siat\Algoritmos\Base16;
use MoziStudios\Siat\Algoritmos\Modulo11;
use MoziStudios\Siat\Excepciones\DatosInvalidos;

/**
 * CUF = Base16(cadena de 53 dígitos + dígito módulo 11) + código de control del CUFD.
 * Cadena: NIT(13) fecha(17) sucursal(4) modalidad(1) emisión(1) tipo factura(1) sector(2) número(10) punto de venta(4).
 */
final class GeneradorCuf
{
    public static function generar(DatosCuf $datos, string $codigoControl): string
    {
        if (preg_match('/^[A-Za-z0-9]+$/', $codigoControl) !== 1) {
            throw new DatosInvalidos('El código de control del CUFD está vacío o no es alfanumérico.');
        }

        $cadena = self::cadena($datos);

        return Base16::codificar($cadena.Modulo11::digito($cadena)).$codigoControl;
    }

    /** Los 53 dígitos previos al módulo 11 (público para poder auditarlo y probarlo). */
    public static function cadena(DatosCuf $datos): string
    {
        if ($datos->numeroFactura < 1) {
            throw new DatosInvalidos('El número de factura debe ser mayor a cero.');
        }

        return self::rellenar($datos->nit, 13, 'NIT')
            .$datos->fecha->cuf()
            .self::rellenar($datos->sucursal, 4, 'sucursal')
            .$datos->modalidad->value
            .$datos->tipoEmision->value
            .$datos->tipoFactura->value
            .self::rellenar($datos->documentoSector->value, 2, 'documento sector')
            .self::rellenar($datos->numeroFactura, 10, 'número de factura')
            .self::rellenar($datos->puntoVenta, 4, 'punto de venta');
    }

    private static function rellenar(int $valor, int $longitud, string $campo): string
    {
        $texto = (string) $valor;

        if ($valor < 0 || strlen($texto) > $longitud) {
            throw new DatosInvalidos("El {$campo} no cabe en {$longitud} dígitos.");
        }

        return str_pad($texto, $longitud, '0', STR_PAD_LEFT);
    }
}
