<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\Producto;
use App\Models\User;

/**
 * Todos los usuarios de la empresa consultan productos (base); gestionarlos exige productos.gestionar.
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
        return $user->tienePermiso(Permiso::ProductosGestionar);
    }

    public function update(User $user, Producto $producto): bool
    {
        return $this->create($user) && $user->empresa_id === $producto->empresa_id;
    }
}
