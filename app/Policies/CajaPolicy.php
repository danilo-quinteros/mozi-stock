<?php

namespace App\Policies;

use App\Models\Caja;
use App\Models\User;

/**
 * Alta y edición de cajas: exclusivas del administrador (ESPECIFICACION_PERMISOS.md § 4.3). Ver una caja: quien
 * supervisa cajas dentro de su alcance o quien la tiene asignada (§ 6.3).
 */
class CajaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function view(User $user, Caja $caja): bool
    {
        return $user->empresa_id === $caja->empresa_id
            && (($user->supervisaCajas() && $user->alcanzaSucursal($caja->sucursal_id)) || $user->caja_id === $caja->id);
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
