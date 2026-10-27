<?php

namespace App\Policies;

use App\Models\User;

/**
 * Decisión del propietario (23/10/2026): ajustes, traspasos y su historial son
 * exclusivos del administrador. El vendedor solo consulta productos y su stock.
 */
class OperacionInventarioPolicy
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
