<?php

namespace App\Policies;

use App\Models\Proveedor;
use App\Models\User;

/**
 * Proveedores acompañan a Compras: solo administradores (decisión del propietario, 23/10/2026).
 */
class ProveedorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function view(User $user, Proveedor $proveedor): bool
    {
        return $user->esAdministrador() && $user->empresa_id === $proveedor->empresa_id;
    }

    public function create(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function update(User $user, Proveedor $proveedor): bool
    {
        return $this->view($user, $proveedor);
    }
}
