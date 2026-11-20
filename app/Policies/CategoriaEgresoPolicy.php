<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\CategoriaEgreso;
use App\Models\User;

/** Categorías de egreso: permiso egresos.gestionar. */
class CategoriaEgresoPolicy
{
    public function create(User $user): bool
    {
        return $user->tienePermiso(Permiso::EgresosGestionar);
    }

    public function update(User $user, CategoriaEgreso $categoria): bool
    {
        return $this->create($user) && $user->empresa_id === $categoria->empresa_id;
    }
}
