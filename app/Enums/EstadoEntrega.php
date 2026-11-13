<?php

namespace App\Enums;

/**
 * Estado de entrega de una venta confirmada (ESPECIFICACION_DESPACHOS.md § 3). Es independiente del
 * pago: ninguna transición registra dinero ni mueve stock. Sin las capacidades «despachos» o «cocina»,
 * toda venta nace Entregado, como siempre. Cocina (ESPECIFICACION_COCINA.md § 3) usa En cocina → Entregado.
 */
enum EstadoEntrega: string
{
    case PorDespachar = 'por_despachar';
    case Despachado = 'despachado';
    case EnCocina = 'en_cocina';
    case Entregado = 'entregado';
    case Cancelado = 'cancelado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PorDespachar => __('Por despachar'),
            self::Despachado => __('Despachado'),
            self::EnCocina => __('En cocina'),
            self::Entregado => __('Entregado'),
            self::Cancelado => __('Cancelado'),
        };
    }

    /**
     * Tono de la insignia con los tokens existentes (§ 4).
     */
    public function insignia(): string
    {
        return match ($this) {
            self::PorDespachar, self::EnCocina => 'warning',
            self::Despachado => 'info',
            self::Entregado => 'success',
            self::Cancelado => 'danger',
        };
    }

    /**
     * Estados del flujo de una capacidad, en orden: la pestaña Despachos muestra solo los suyos.
     *
     * @return list<self>
     */
    public static function delFlujo(Capacidad $capacidad): array
    {
        return match ($capacidad) {
            Capacidad::Despachos => [self::PorDespachar, self::Despachado, self::Entregado, self::Cancelado],
            Capacidad::Cocina => [self::EnCocina, self::Entregado, self::Cancelado],
            default => [self::Entregado],
        };
    }

    /**
     * Estado con el que nace una venta que no se entregó al vender.
     */
    public static function inicial(Capacidad $capacidad): self
    {
        return match ($capacidad) {
            Capacidad::Despachos => self::PorDespachar,
            Capacidad::Cocina => self::EnCocina,
            default => self::Entregado,
        };
    }

    /**
     * Estados que impiden apagar la capacidad: los productos todavía no llegaron al cliente.
     *
     * @return list<self>
     */
    public static function pendientes(Capacidad $capacidad): array
    {
        return match ($capacidad) {
            Capacidad::Despachos => [self::PorDespachar, self::Despachado],
            Capacidad::Cocina => [self::EnCocina],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(fn (self $e): string => $e->value, self::cases());
    }
}
