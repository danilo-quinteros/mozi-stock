<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\Cliente;
use App\Models\User;

/**
 * Consultar clientes es la base de todo usuario de la empresa (ESPECIFICACION_PERMISOS.md § 4.1). Registrarlos y
 * editarlos exige clientes.gestionar; activarlos o desactivarlos, clientes.desactivar. El historial de ventas visible
 * sigue la regla de alcance de VentaPolicy.
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
        return $user->tienePermiso(Permiso::ClientesGestionar);
    }

    public function update(User $user, Cliente $cliente): bool
    {
        return $this->create($user) && $this->view($user, $cliente);
    }

    public function cambiarEstado(User $user, Cliente $cliente): bool
    {
        return $user->tienePermiso(Permiso::ClientesDesactivar) && $this->view($user, $cliente);
    }
}
