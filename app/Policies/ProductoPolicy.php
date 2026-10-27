<?php

namespace App\Policies;

use App\Models\Producto;
use App\Models\User;

/**
 * Todos los usuarios de la empresa consultan productos; solo el administrador los gestiona.
 */
class ProductoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->perteneceAEmpresa();
    }

    public function view(User $user, Producto $producto): bool
    {
        return $user->perteneceAEmpresa() && $user->empresa_id === $producto->empresa_id;
    }

    public function create(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function update(User $user, Producto $producto): bool
    {
        return $user->esAdministrador() && $user->empresa_id === $producto->empresa_id;
    }
}
