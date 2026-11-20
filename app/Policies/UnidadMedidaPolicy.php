<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\UnidadMedida;
use App\Models\User;

/** Unidades de medida: permiso productos.gestionar. */
class UnidadMedidaPolicy
{
    public function create(User $user): bool
    {
        return $user->tienePermiso(Permiso::ProductosGestionar);
    }

    public function update(User $user, UnidadMedida $unidad): bool
    {
        return $this->create($user) && $user->empresa_id === $unidad->empresa_id;
    }
}
