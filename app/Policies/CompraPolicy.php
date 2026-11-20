<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\Compra;
use App\Models\User;

/**
 * Compras: permiso compras.gestionar, dentro de su alcance de sucursales (ESPECIFICACION_PERMISOS.md § 4.2).
 */
class CompraPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tienePermiso(Permiso::ComprasGestionar);
    }

    public function view(User $user, Compra $compra): bool
    {
        return $this->viewAny($user) && $user->empresa_id === $compra->empresa_id && $user->alcanzaSucursal($compra->sucursal_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }
}
