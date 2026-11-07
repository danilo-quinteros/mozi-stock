<?php

namespace App\Policies;

use App\Models\Sucursal;
use App\Models\User;

class SucursalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function view(User $user, Sucursal $sucursal): bool
    {
        return $user->esAdministrador() && $user->empresa_id === $sucursal->empresa_id;
    }

    public function create(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function update(User $user, Sucursal $sucursal): bool
    {
        return $user->esAdministrador() && $user->empresa_id === $sucursal->empresa_id;
    }
}
