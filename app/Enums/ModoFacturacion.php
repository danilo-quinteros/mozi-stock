<?php

namespace App\Enums;

/**
 * Decisión D1b del propietario: el administrador elige si toda venta confirmada se factura
 * (automática, por defecto) o si el vendedor decide con o sin factura al confirmar (manual).
 */
enum ModoFacturacion: string
{
    case Automatica = 'automatica';
    case Manual = 'manual';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Automatica => __('Automática'),
            self::Manual => __('Manual'),
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Automatica => __('Toda venta confirmada se factura.'),
            self::Manual => __('El vendedor elige con o sin factura al confirmar.'),
        };
    }
}
