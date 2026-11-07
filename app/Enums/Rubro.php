<?php

namespace App\Enums;

/**
 * Tipo de negocio de la empresa: una etiqueta más un conjunto predefinido de capacidades
 * (ESPECIFICACION_RUBROS_Y_CAPACIDADES.md § 2). Ventas, stock, caja, créditos y reportes son los
 * mismos para todos los rubros.
 */
enum Rubro: string
{
    case General = 'general';
    case Ropa = 'ropa';
    case Farmacia = 'farmacia';
    case Distribuidora = 'distribuidora';
    case Restaurante = 'restaurante';

    public function etiqueta(): string
    {
        return match ($this) {
            self::General => __('General'),
            self::Ropa => __('Tienda de ropa y calzado'),
            self::Farmacia => __('Farmacia'),
            self::Distribuidora => __('Distribuidora'),
            self::Restaurante => __('Restaurante'),
        };
    }

    /** Para quién es, en una línea (columna «Para quién» de la especificación). */
    public function descripcion(): string
    {
        return match ($this) {
            self::General => __('Tiendas, minimarkets, ferreterías, librerías y cualquier otro negocio.'),
            self::Ropa => __('Ropa, calzado y accesorios con tallas o colores.'),
            self::Farmacia => __('Farmacias, boticas y veterinarias con medicamentos.'),
            self::Distribuidora => __('Mayoristas que entregan pedidos.'),
            self::Restaurante => __('Restaurantes, cafeterías y comida rápida.'),
        };
    }

    /**
     * Capacidades que el rubro enciende al elegirlo.
     *
     * @return list<Capacidad>
     */
    public function capacidades(): array
    {
        return match ($this) {
            self::General => [],
            self::Ropa => [Capacidad::Variantes],
            self::Farmacia => [Capacidad::LotesVencimientos],
            self::Distribuidora => [Capacidad::Despachos],
            self::Restaurante => [Capacidad::Cocina],
        };
    }
}
