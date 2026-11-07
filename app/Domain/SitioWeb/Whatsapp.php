<?php

namespace App\Domain\SitioWeb;

/**
 * Enlaces de WhatsApp del sitio público. El número lo define el superadmin (Sitio web › Ajustes
 * del sitio); mientras no exista, los botones llevan a la página de contacto.
 */
final class Whatsapp
{
    /** Código de país + número, solo dígitos (Bolivia: 591 + 8 dígitos). */
    public const PATRON = '/^\d{8,15}$/';

    public static function normalizar(?string $numero): ?string
    {
        $digitos = preg_replace('/\D+/', '', (string) $numero) ?? '';

        return $digitos === '' ? null : $digitos;
    }

    public static function enlace(?string $numero, string $mensaje): ?string
    {
        if ($numero === null || ! preg_match(self::PATRON, $numero)) {
            return null;
        }

        return 'https://wa.me/'.$numero.'?text='.rawurlencode($mensaje);
    }
}
