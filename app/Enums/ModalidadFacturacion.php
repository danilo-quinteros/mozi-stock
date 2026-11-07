<?php

namespace App\Enums;

use MoziStudios\Siat\Constantes\Modalidad as ModalidadSiat;

/**
 * Modalidad de facturación en línea ante el SIN:
 * - Computarizada: Facturas en línea estándar sin firma digital.
 * - Electrónica: Facturas en línea firmadas digitalmente con XMLDSig y certificado PKCS#12 (.p12) (para GRACO/PRICO).
 */
enum ModalidadFacturacion: string
{
    case Computarizada = 'computarizada';
    case Electronica = 'electronica';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Computarizada => __('Computarizada en Línea'),
            self::Electronica => __('Electrónica en Línea'),
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Computarizada => __('Emisión computarizada en línea (por defecto).'),
            self::Electronica => __('Emisión electrónica en línea con firma digital XMLDSig (.p12).'),
        };
    }

    public function siat(): ModalidadSiat
    {
        return match ($this) {
            self::Computarizada => ModalidadSiat::Computarizada,
            self::Electronica => ModalidadSiat::Electronica,
        };
    }
}
