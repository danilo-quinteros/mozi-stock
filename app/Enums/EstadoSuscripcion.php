<?php

namespace App\Enums;

/**
 * Situación de cobro de una suscripción. Es informativa: ningún estado cambia el acceso
 * de la empresa, que solo el superadministrador activa o desactiva (T26).
 */
enum EstadoSuscripcion: string
{
    case AlDia = 'al_dia';
    case PorVencer = 'por_vencer';
    case Vencida = 'vencida';

    /** Días antes del vencimiento en que la suscripción pasa a «por vencer» (aviso D-5). */
    public const DIAS_POR_VENCER = 5;

    public function etiqueta(): string
    {
        return match ($this) {
            self::AlDia => __('Al día'),
            self::PorVencer => __('Por vencer'),
            self::Vencida => __('Vencida'),
        };
    }

    public function insignia(): string
    {
        return match ($this) {
            self::AlDia => 'success',
            self::PorVencer => 'warning',
            self::Vencida => 'danger',
        };
    }
}
