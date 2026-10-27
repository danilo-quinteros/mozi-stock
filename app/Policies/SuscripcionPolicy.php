<?php

namespace App\Policies;

use App\Models\Suscripcion;
use App\Models\User;

/**
 * Suscripción SaaS: la ve el administrador de la propia empresa y la configura solo
 * MoziStudios. Los vendedores no la ven. No existe en el modo standalone.
 */
class SuscripcionPolicy
{
    public function view(User $user, Suscripcion $suscripcion): bool
    {
        return self::saas() && ($user->esSuperadmin()
            || ($user->esAdministrador() && $user->empresa_id === $suscripcion->empresa_id));
    }

    /**
     * Precio, vencimiento y corrección manual del vencimiento.
     */
    public function configurar(User $user): bool
    {
        return self::saas() && $user->esSuperadmin();
    }

    /**
     * La empresa puede actualizar o cambiar su plan cuando guste.
     */
    public function cambiarPlan(User $user): bool
    {
        return self::saas() && $user->esAdministrador() && $user->empresa_id !== null;
    }

    /**
     * Superadmin gestiona los planes: títulos, descripciones y precios.
     */
    public function gestionarPlanes(User $user): bool
    {
        return self::saas() && $user->esSuperadmin();
    }

    public static function saas(): bool
    {
        return config('mozistock.deployment_mode') === 'saas';
    }
}
