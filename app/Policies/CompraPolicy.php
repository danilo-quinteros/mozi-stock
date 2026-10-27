<?php

namespace App\Policies;

use App\Models\Compra;
use App\Models\User;

/**
 * Decisión del propietario (23/10/2026): compras solo para administradores.
 */
class CompraPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function view(User $user, Compra $compra): bool
    {
        return $user->esAdministrador() && $user->empresa_id === $compra->empresa_id;
    }

    public function create(User $user): bool
    {
        return $user->esAdministrador();
    }
}
