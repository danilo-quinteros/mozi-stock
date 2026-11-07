<?php

namespace App\Policies;

use App\Models\CuentaCobrar;
use App\Models\User;

/**
 * Cuentas por cobrar manuales. Como los créditos de venta, cualquier usuario de la empresa
 * las consulta y cobra (decisión del propietario, 23/10/2026); registrarlas y anularlas es del
 * administrador.
 */
class CuentaCobrarPolicy
{
    public function view(User $user, CuentaCobrar $cuenta): bool
    {
        return $user->perteneceAEmpresa() && $user->empresa_id === $cuenta->empresa_id;
    }

    public function cobrar(User $user, CuentaCobrar $cuenta): bool
    {
        return $this->view($user, $cuenta);
    }

    public function create(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function anular(User $user, CuentaCobrar $cuenta): bool
    {
        return $user->esAdministrador() && $this->view($user, $cuenta);
    }
}
