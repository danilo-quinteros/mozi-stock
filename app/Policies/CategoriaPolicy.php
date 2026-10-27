<?php

namespace App\Policies;

use App\Models\Categoria;
use App\Models\User;

class CategoriaPolicy
{
    public function create(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function update(User $user, Categoria $categoria): bool
    {
        return $user->esAdministrador() && $user->empresa_id === $categoria->empresa_id;
    }
}
