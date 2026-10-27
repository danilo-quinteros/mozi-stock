<?php

namespace App\Enums;

/**
 * Modo de pago en la interfaz. Mixto no es una categoría contable: se guarda con su
 * desglose y genera un movimiento de caja por cada medio real.
 */
enum MetodoPago: string
{
    case Efectivo = 'efectivo';
    case Electronico = 'electronico';
    case Mixto = 'mixto';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Efectivo => __('Efectivo'),
            self::Electronico => __('Electrónico'),
            self::Mixto => __('Mixto'),
        };
    }
}
