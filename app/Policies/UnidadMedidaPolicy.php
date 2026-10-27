<?php

namespace App\Policies;

use App\Models\UnidadMedida;
use App\Models\User;

class UnidadMedidaPolicy
{
    public function create(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function update(User $user, UnidadMedida $unidad): bool
    {
        return $user->esAdministrador() && $user->empresa_id === $unidad->empresa_id;
    }
}
