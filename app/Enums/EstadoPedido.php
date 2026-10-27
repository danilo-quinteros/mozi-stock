<?php

namespace App\Enums;

enum EstadoPedido: string
{
    case Pendiente = 'pendiente';
    case Vendido = 'vendido';
    case Cancelado = 'cancelado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => __('Pendiente'),
            self::Vendido => __('Vendido'),
            self::Cancelado => __('Cancelado'),
        };
    }

    public function insignia(): string
    {
        return match ($this) {
            self::Pendiente => 'warning',
            self::Vendido => 'success',
            self::Cancelado => 'danger',
        };
    }
}
