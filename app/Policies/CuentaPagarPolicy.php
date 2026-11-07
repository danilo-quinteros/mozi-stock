<?php

namespace App\Policies;

use App\Models\CuentaPagar;
use App\Models\User;

/**
 * Cuentas por pagar: como Compras y Egresos, solo para administradores de la empresa.
 */
class CuentaPagarPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function view(User $user, CuentaPagar $cuenta): bool
    {
        return $user->esAdministrador() && $user->empresa_id === $cuenta->empresa_id;
    }

    public function create(User $user): bool
    {
        return $user->esAdministrador();
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
