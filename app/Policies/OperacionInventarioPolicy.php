<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\User;

/**
 * Operaciones de inventario (ESPECIFICACION_PERMISOS.md § 4.2): el historial exige inventario.historial; entradas,
 * salidas, ajustes, stock inicial y regularización de lotes, inventario.ajustar; traspasos, inventario.traspasar.
 * La sucursal de cada operación la acota ResolverSucursal según el alcance del usuario.
 */
class OperacionInventarioPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tienePermiso(Permiso::InventarioHistorial);
    }

    public function create(User $user): bool
    {
        return $user->tienePermiso(Permiso::InventarioAjustar);
    }

    public function traspasar(User $user): bool
    {
        return $user->tienePermiso(Permiso::InventarioTraspasar);
    }
}
