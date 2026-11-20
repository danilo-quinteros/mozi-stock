<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\CuentaPagar;
use App\Models\User;

/**
 * Cuentas por pagar: permiso cuentas_pagar.gestionar (ver, crear, pagar y anular).
 */
class CuentaPagarPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tienePermiso(Permiso::CuentasPagarGestionar);
    }

    public function view(User $user, CuentaPagar $cuenta): bool
    {
        return $this->viewAny($user) && $user->empresa_id === $cuenta->empresa_id;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function pagar(User $user, CuentaPagar $cuenta): bool
    {
        return $this->view($user, $cuenta);
    }

    /**
     * Anular la obligación manual o uno de sus pagos.
     */
    public function anular(User $user, CuentaPagar $cuenta): bool
    {
        return $this->view($user, $cuenta);
    }
}
