<?php

namespace App\Policies;

use App\Models\User;

/**
 * Decisión del propietario (23/10/2026): egresos solo para administradores.
 */
class EgresoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function create(User $user): bool
    {
        return $user->esAdministrador();
    }
}
