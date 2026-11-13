<?php

namespace App\Enums;

/**
 * Función concreta que se enciende o se apaga por empresa (ESPECIFICACION_RUBROS_Y_CAPACIDADES.md § 2).
 * Es independiente de planes y suscripciones (regla 17 de AGENTS.md).
 */
enum Capacidad: string
{
    case Variantes = 'variantes';
    case LotesVencimientos = 'lotes_vencimientos';
    case Despachos = 'despachos';
    case Cocina = 'cocina';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Variantes => __('Tallas y colores'),
            self::LotesVencimientos => __('Lotes y vencimientos'),
            self::Despachos => __('Despachos'),
            self::Cocina => __('Pedidos en cocina'),
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Variantes => __('Productos con variantes por talla y color.'),
            self::LotesVencimientos => __('Stock por lote; bloquea la venta de vencidos.'),
            self::Despachos => __('Ventas Por despachar → Despachado → Entregado.'),
            self::Cocina => __('Ventas En cocina → Entregado, con botón flotante.'),
        };
    }

    /**
     * Capacidades que no pueden estar activas a la vez con esta: cocina y despachos comparten el estado
     * de entrega de la venta con flujos distintos.
     *
     * @return list<self>
     */
    public function incompatibles(): array
    {
        return match ($this) {
            self::Despachos => [self::Cocina],
            self::Cocina => [self::Despachos],
            default => [],
        };
    }

    /**
     * Si el módulo de la capacidad ya se puede usar desde las pantallas. Mientras sea false, la pantalla lo
     * avisa con «Próximamente». Cada etapa cambió su caso: V1 (tallas y colores), L3 (lotes), D2 (despachos) y K2
     * (cocina, disponible desde el panel; decisión del propietario del 08/11/2026). Hoy todas están disponibles.
     */
    public function disponible(): bool
    {
        return match ($this) {
            self::Despachos, self::Cocina, self::LotesVencimientos, self::Variantes => true,
        };
    }

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(fn (self $c): string => $c->value, self::cases());
    }
}
