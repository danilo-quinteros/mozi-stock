<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\CuentaCobrar;
use App\Models\User;

/**
 * Cuentas por cobrar manuales. Las consulta quien tiene creditos.ver y las cobra quien tiene creditos.cobrar, de
 * cualquier sucursal (decisión del 23/10/2026); registrarlas exige creditos.crear y anularlas, cobros.anular dentro
 * de su alcance.
 */
class CuentaCobrarPolicy
{
    public function view(User $user, CuentaCobrar $cuenta): bool
    {
        return $user->tienePermiso(Permiso::CreditosVer) && $user->empresa_id === $cuenta->empresa_id;
    }

    public function cobrar(User $user, CuentaCobrar $cuenta): bool
    {
        return $user->tienePermiso(Permiso::CreditosCobrar) && $this->view($user, $cuenta);
    }

    public function create(User $user): bool
    {
        return $user->tienePermiso(Permiso::CreditosCrear);
    }

    public function anular(User $user, CuentaCobrar $cuenta): bool
    {
        return $user->tienePermiso(Permiso::CobrosAnular) && $this->view($user, $cuenta) && $user->alcanzaSucursal($cuenta->sucursal_id);
    }
}
