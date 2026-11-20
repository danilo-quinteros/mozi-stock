<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\Pedido;
use App\Models\User;

/**
 * Pedidos de su alcance (ESPECIFICACION_PERMISOS.md § 6.2): quien trabaja en todas las sucursales ve los de toda la
 * empresa; el resto, los de su sucursal asignada. Verlos exige ventas.ver y crearlos o editarlos, ventas.vender.
 * Solo un pedido pendiente se edita, cancela o confirma.
 */
class PedidoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tienePermiso(Permiso::VentasVer);
    }

    public function view(User $user, Pedido $pedido): bool
    {
        return $user->empresa_id === $pedido->empresa_id && $this->viewAny($user) && $user->alcanzaSucursal($pedido->sucursal_id);
    }

    public function create(User $user): bool
    {
        return $user->tienePermiso(Permiso::VentasVender);
    }

    public function update(User $user, Pedido $pedido): bool
    {
        return $this->create($user) && $this->view($user, $pedido) && $pedido->estaPendiente();
    }
}
