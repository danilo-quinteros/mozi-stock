<?php

namespace App\Enums;

/**
 * Tipo de negocio de la empresa: una etiqueta más un conjunto predefinido de capacidades
 * (ESPECIFICACION_RUBROS_Y_CAPACIDADES.md § 2). Ventas, stock, caja, créditos y reportes son los
 * mismos para todos los rubros.
 */
enum Rubro: string
{
    /** Se muestra como «Tienda» (decisión del propietario, 09/11/2026); el valor guardado sigue siendo «general». */
    case General = 'general';
    case Ropa = 'ropa';
    case Farmacia = 'farmacia';
    case Distribuidora = 'distribuidora';
    case Restaurante = 'restaurante';

    public function etiqueta(): string
    {
        return match ($this) {
            self::General => __('Tienda'),
            self::Ropa => __('Tienda de ropa y calzado'),
            self::Farmacia => __('Farmacia'),
            self::Distribuidora => __('Distribuidora'),
            self::Restaurante => __('Restaurante'),
        };
    }

    /** Etiqueta del campo «marca» del producto: en farmacias importa también el laboratorio. */
    public function etiquetaMarca(): string
    {
        return $this === self::Farmacia ? __('Marca / laboratorio') : __('Marca');
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

    /**
     * Categorías de productos base que se precargan al registrar una empresa con este rubro.
     * Si devuelve un arreglo vacío (como en General / Tienda), no se crea ninguna categoría.
     *
     * @return list<string>
     */
    public function categoriasSugeridas(): array
    {
        return match ($this) {
            self::General => [],
            self::Ropa => [
                'Ropa de hombre',
                'Ropa de mujer',
                'Ropa infantil',
                'Calzado',
                'Accesorios',
                'Ropa deportiva',
                'Ropa interior',
                'Bolsos y carteras',
            ],
            self::Farmacia => [
                'Medicamentos',
                'Vitaminas y suplementos',
                'Cuidado personal',
                'Higiene y cuidado infantil',
                'Primeros auxilios',
                'Material médico',
                'Productos dermatológicos',
                'Productos veterinarios',
            ],
            self::Distribuidora => [
                'Bebidas',
                'Abarrotes',
                'Lácteos',
                'Snacks y golosinas',
                'Limpieza',
                'Higiene personal',
                'Alimentos envasados',
                'Productos para el hogar',
            ],
            self::Restaurante => [
                'Platos',
                'Entradas',
                'Bebidas',
                'Postres',
                'Ensaladas',
                'Sopas y caldos',
                'Combos y promociones',
            ],
        };
    }
}
