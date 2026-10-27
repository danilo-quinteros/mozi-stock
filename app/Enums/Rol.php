<?php

namespace App\Enums;

enum Rol: string
{
    case Superadmin = 'superadmin';
    case Administrador = 'administrador';
    case Vendedor = 'vendedor';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Superadmin => __('Superadministrador'),
            self::Administrador => __('Administrador'),
            self::Vendedor => __('Vendedor'),
        };
    }

    /**
     * Roles que un administrador de empresa puede asignar. Nunca incluye superadmin.
     *
     * @return list<self>
     */
    public static function asignablesPorEmpresa(): array
    {
        return [self::Vendedor, self::Administrador];
    }
}
