<?php

namespace MoziStudios\Siat\Factura;

use MoziStudios\Siat\Excepciones\DatosInvalidos;

/**
 * Enlace de consulta de una factura en el SIN, el que va en el QR de la representación gráfica (anexo
 * «Representación gráfica»): {base}?nit=…&cuf=…&numero=…&t=…, con t = 1 rollo y t = 2 media página.
 * La base del piloto es https://pilotosiat.impuestos.gob.bo/consulta/QR; la de producción la comunica el
 * SIN al autorizar el sistema, por eso se recibe como parámetro.
 */
final class EnlaceQr
{
    public const URL_PILOTO = 'https://pilotosiat.impuestos.gob.bo/consulta/QR';

    public const ROLLO = 1;

    public const MEDIA_PAGINA = 2;

    public static function url(string $base, int $nit, string $cuf, int $numeroFactura, int $tamano = self::ROLLO): string
    {
        if (preg_match('#^https://[^\s?]+$#', $base) !== 1) {
            throw new DatosInvalidos('La URL de consulta del QR debe ser https y sin parámetros.');
        }

        if (preg_match('/^[0-9A-Za-z]+$/', $cuf) !== 1 || $nit < 1 || $numeroFactura < 1 || ! in_array($tamano, [self::ROLLO, self::MEDIA_PAGINA], true)) {
            throw new DatosInvalidos('Datos inválidos para el enlace QR de la factura.');
        }

        return $base.'?'.http_build_query(['nit' => $nit, 'cuf' => $cuf, 'numero' => $numeroFactura, 't' => $tamano]);
    }
}
