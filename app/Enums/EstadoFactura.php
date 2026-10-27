<?php

namespace App\Enums;

/**
 * Estado fiscal de una factura SIAT. Solo «Validada» (908) es una factura válida ante el SIN;
 * «Observada» (904) tampoco lo es. Ninguno de estos estados toca la venta, la caja ni el stock.
 */
enum EstadoFactura: string
{
    /** No se envió: el total no cuadra con el IVA del SIN o falta configuración. Se puede reintentar. */
    case Bloqueada = 'bloqueada';

    /** Lista para enviar, en envío o esperando la respuesta del SIN (901). */
    case Pendiente = 'pendiente';

    case Validada = 'validada';

    case Observada = 'observada';

    case Rechazada = 'rechazada';

    case Anulada = 'anulada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Bloqueada => __('Bloqueada'),
            self::Pendiente => __('Pendiente'),
            self::Validada => __('Validada'),
            self::Observada => __('Observada'),
            self::Rechazada => __('Rechazada'),
            self::Anulada => __('Anulada'),
        };
    }

    public function insignia(): string
    {
        return match ($this) {
            self::Validada => 'success',
            self::Pendiente => 'info',
            self::Bloqueada, self::Observada => 'warning',
            self::Rechazada, self::Anulada => 'danger',
        };
    }

    /** Se puede volver a calcular y enviar (con el mismo número si ya tenía uno). */
    public function reintentable(): bool
    {
        return in_array($this, [self::Bloqueada, self::Rechazada, self::Observada], true);
    }
}
