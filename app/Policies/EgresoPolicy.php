<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\User;

/**
 * Egresos: permiso egresos.gestionar, dentro de su alcance de sucursales.
 */
class EgresoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tienePermiso(Permiso::EgresosGestionar);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }
}
