<?php

namespace App\Policies;

use App\Models\CategoriaEgreso;
use App\Models\User;

class CategoriaEgresoPolicy
{
    public function create(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function update(User $user, CategoriaEgreso $categoria): bool
    {
        return $user->esAdministrador() && $user->empresa_id === $categoria->empresa_id;
    }
}
