<?php

namespace App\Policies;

use App\Models\Cliente;
use App\Models\User;

/**
 * Decisión del propietario (23/10/2026): cualquier usuario de la empresa consulta, registra y
 * edita clientes (el vendedor ya los crea desde el pedido). Activar o desactivar es del
 * administrador. El historial de ventas visible sigue la regla de sucursal de VentaPolicy.
 */
class ClientePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->perteneceAEmpresa();
    }

    public function view(User $user, Cliente $cliente): bool
    {
        return $user->perteneceAEmpresa() && $user->empresa_id === $cliente->empresa_id;
    }

    public function create(User $user): bool
    {
        return $user->perteneceAEmpresa();
    }

    public function update(User $user, Cliente $cliente): bool
    {
        return $this->view($user, $cliente);
    }

    public function cambiarEstado(User $user, Cliente $cliente): bool
    {
        return $user->esAdministrador() && $user->empresa_id === $cliente->empresa_id;
    }
}
