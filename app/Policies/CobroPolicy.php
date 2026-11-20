<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\Cobro;
use App\Models\User;

/**
 * Cobros de clientes: los consulta (y descarga su comprobante) quien ve las cuentas por cobrar de la empresa. Anularlos
 * exige cobros.anular y que el cobro sea de su alcance (ESPECIFICACION_PERMISOS.md § 6.2).
 */
class CobroPolicy
{
    public function view(User $user, Cobro $cobro): bool
    {
        return $user->tienePermiso(Permiso::CreditosVer) && $user->empresa_id === $cobro->empresa_id;
    }

    public function anular(User $user, Cobro $cobro): bool
    {
        return $user->tienePermiso(Permiso::CobrosAnular) && $this->view($user, $cobro) && $user->alcanzaSucursal($cobro->sucursal_id);
    }
}
