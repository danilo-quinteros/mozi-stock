<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\Categoria;
use App\Models\User;

/** Categorías de productos: permiso productos.gestionar. */
class CategoriaPolicy
{
    public function create(User $user): bool
    {
        return $user->tienePermiso(Permiso::ProductosGestionar);
    }

    public function update(User $user, Categoria $categoria): bool
    {
        return $this->create($user) && $user->empresa_id === $categoria->empresa_id;
    }
}
