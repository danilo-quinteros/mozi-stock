<?php

namespace App\Enums;

/**
 * Recordatorios de vencimiento (§ 3.12): 5 días antes, el día del vencimiento y 3 días
 * después. Cada uno vale desde su fecha hasta el día previo al siguiente; el último, 3 días.
 */
enum EventoAviso: string
{
    case Previo = 'd-5';
    case Dia = 'd0';
    case Posterior = 'd+3';

    /**
     * Días respecto del vencimiento.
     */
    public function desfase(): int
    {
        return match ($this) {
            self::Previo => -5,
            self::Dia => 0,
            self::Posterior => 3,
        };
    }

    /**
     * Último día (respecto del vencimiento) en que todavía se envía si el servidor no pudo
     * hacerlo en su fecha. Las ventanas no se superponen: nunca dos avisos el mismo día.
     */
    public function hasta(): int
    {
        return match ($this) {
            self::Previo => -1,
            self::Dia => 2,
            self::Posterior => 5,
        };
    }

    public function codigo(): string
    {
        return match ($this) {
            self::Previo => 'D−5',
            self::Dia => 'D0',
            self::Posterior => 'D+3',
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Previo => __('5 días antes'),
            self::Dia => __('El día del vencimiento'),
            self::Posterior => __('3 días después'),
        };
    }
}
