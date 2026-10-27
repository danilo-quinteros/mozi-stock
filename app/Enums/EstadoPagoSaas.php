<?php

namespace App\Enums;

/**
 * Revisión manual de un comprobante de pago SaaS. Pendiente y rechazado nunca renuevan (T25).
 */
enum EstadoPagoSaas: string
{
    case Pendiente = 'pendiente';
    case Aprobado = 'aprobado';
    case Rechazado = 'rechazado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => __('En revisión'),
            self::Aprobado => __('Aprobado'),
            self::Rechazado => __('Rechazado'),
        };
    }

    public function insignia(): string
    {
        return match ($this) {
            self::Pendiente => 'warning',
            self::Aprobado => 'success',
            self::Rechazado => 'danger',
        };
    }
}
