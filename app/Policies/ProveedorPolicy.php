<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\Proveedor;
use App\Models\User;

/**
 * Proveedores: permiso proveedores.gestionar. Quien solo registra compras elige proveedores existentes en el formulario.
 */
class ProveedorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tienePermiso(Permiso::ProveedoresGestionar);
    }

    public function view(User $user, Proveedor $proveedor): bool
    {
        return $this->viewAny($user) && $user->empresa_id === $proveedor->empresa_id;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Proveedor $proveedor): bool
    {
        return $this->view($user, $proveedor);
    }
}
