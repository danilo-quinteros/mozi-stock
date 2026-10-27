<?php

namespace App\Policies;

use App\Models\Pedido;
use App\Models\User;

/**
 * El administrador ve y gestiona los pedidos de toda su empresa; el vendedor, los de su
 * sucursal asignada. Solo un pedido pendiente se edita, cancela o confirma.
 */
class PedidoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->perteneceAEmpresa();
    }

    public function view(User $user, Pedido $pedido): bool
    {
        return $user->empresa_id === $pedido->empresa_id
            && ($user->esAdministrador() || ($user->esVendedor() && $user->sucursal_id === $pedido->sucursal_id));
    }

    public function create(User $user): bool
    {
        return $user->perteneceAEmpresa();
    }

    public function update(User $user, Pedido $pedido): bool
    {
        return $this->view($user, $pedido) && $pedido->estaPendiente();
    }
}
