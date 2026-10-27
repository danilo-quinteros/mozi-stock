<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Venta;

class VentaPolicy
{
    public function view(User $user, Venta $venta): bool
    {
        return $user->empresa_id === $venta->empresa_id
            && ($user->esAdministrador() || ($user->esVendedor() && $user->sucursal_id === $venta->sucursal_id));
    }

    /**
     * Decisión del propietario (23/10/2026): cualquier usuario de la empresa cobra cualquier
     * crédito de ella; el cobro queda en su sucursal y a su nombre.
     */
    public function cobrar(User $user, Venta $venta): bool
    {
        return $user->perteneceAEmpresa() && $user->empresa_id === $venta->empresa_id;
    }

    /**
     * Decisión del propietario (23/10/2026): solo el administrador registra devoluciones.
     */
    public function devolver(User $user, Venta $venta): bool
    {
        return $user->esAdministrador() && $user->empresa_id === $venta->empresa_id;
    }
}
