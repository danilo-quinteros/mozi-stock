<?php

namespace App\Enums;

/**
 * Lista de precio de una línea. Consumidor es el predeterminado en pedidos.
 * Decisión del propietario (23/10/2026): no existe precio manual.
 */
enum TipoPrecio: string
{
    case Consumidor = 'consumidor';
    case Minorista = 'minorista';
    case Mayorista = 'mayorista';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Consumidor => __('Consumidor'),
            self::Minorista => __('Minorista'),
            self::Mayorista => __('Mayorista'),
        };
    }

    public function columna(): string
    {
        return 'precio_'.$this->value;
    }
}
