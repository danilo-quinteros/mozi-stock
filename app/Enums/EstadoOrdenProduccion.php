<?php

namespace App\Enums;

/**
 * Estados de una orden de producción (ESPECIFICACION_PRODUCCION.md § 6.2): emitida → aprobada → finalizada, o anulada.
 */
enum EstadoOrdenProduccion: string
{
    case Emitida = 'emitida';
    case Aprobada = 'aprobada';
    case Finalizada = 'finalizada';
    case Anulada = 'anulada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Emitida => __('Emitida'),
            self::Aprobada => __('Aprobada'),
            self::Finalizada => __('Finalizada'),
            self::Anulada => __('Anulada'),
        };
    }

    public function insignia(): string
    {
        return match ($this) {
            self::Emitida => 'warning',
            self::Aprobada => 'info',
            self::Finalizada => 'success',
            self::Anulada => 'danger',
        };
    }

    /**
     * Abiertas: impiden apagar la capacidad Producción (§ 8).
     *
     * @return list<self>
     */
    public static function abiertas(): array
    {
        return [self::Emitida, self::Aprobada];
    }
}
