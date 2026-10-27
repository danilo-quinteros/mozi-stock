<?php

namespace App\Policies;

use App\Models\User;

/**
 * Gestión de usuarios dentro de una empresa. El superadministrador no administra
 * usuarios de clientes desde estas pantallas.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function create(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function update(User $user, User $target): bool
    {
        return $user->esAdministrador()
            && $target->empresa_id === $user->empresa_id
            && ! $target->esSuperadmin();
    }
}
