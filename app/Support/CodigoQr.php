<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR en SVG para la representación gráfica de la factura. Usa bacon/bacon-qr-code, que ya está instalado
 * como dependencia de Laravel Fortify (doble factor): no agrega paquetes. Único punto de uso de la librería.
 */
final class CodigoQr
{
    public static function svg(string $texto, int $tamano = 160): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle($tamano, 1), new SvgImageBackEnd)))->writeString($texto);

        // Sin la declaración XML, para incrustarlo en el HTML.
        return trim(substr($svg, (int) strpos($svg, '<svg')));
    }
}
