<?php

namespace App\Policies;

use App\Models\Caja;
use App\Models\User;

class CajaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function view(User $user, Caja $caja): bool
    {
        return $user->empresa_id === $caja->empresa_id && ($user->esAdministrador() || $user->caja_id === $caja->id);
    }

    public function create(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function update(User $user, Caja $caja): bool
    {
        return $user->esAdministrador() && $user->empresa_id === $caja->empresa_id;
    }

    public function delete(User $user, Caja $caja): bool
    {
        return false;
    }
}
