<?php

namespace App\Policies;

use App\Models\Rol;
use App\Models\User;

/**
 * Roles y permisos: exclusivos del administrador de la empresa (ESPECIFICACION_PERMISOS.md § 4.3 y § 6.4).
 * No existe un permiso para delegarlo: un usuario con todo el catálogo sigue sin poder hacerlo.
 */
class RolPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function view(User $user, Rol $rol): bool
    {
        return $user->esAdministrador() && $user->empresa_id === $rol->empresa_id;
    }

    public function create(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function update(User $user, Rol $rol): bool
    {
        return $this->view($user, $rol);
    }

    public function delete(User $user, Rol $rol): bool
    {
        return $this->view($user, $rol);
    }
}
