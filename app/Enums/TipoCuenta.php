<?php

namespace App\Enums;

/**
 * Tipo de cuenta (ESPECIFICACION_PERMISOS.md § 3). Lo que puede hacer un usuario de tipo «usuario» lo
 * deciden su rol de empresa y sus permisos extra; el administrador tiene todos los permisos.
 */
enum TipoCuenta: string
{
    case Superadmin = 'superadmin';
    case Administrador = 'administrador';
    case Usuario = 'usuario';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Superadmin => __('Superadministrador'),
            self::Administrador => __('Administrador'),
            self::Usuario => __('Usuario con rol'),
        };
    }

    /**
     * Tipos que un administrador de empresa puede asignar. Nunca incluye superadmin.
     *
     * @return list<self>
     */
    public static function asignablesPorEmpresa(): array
    {
        return [self::Usuario, self::Administrador];
    }
}
