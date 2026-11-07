<?php

namespace App\Policies;

use App\Models\Cobro;
use App\Models\User;

/**
 * Cobros de clientes: los consulta (y descarga su comprobante) cualquier usuario de la empresa,
 * igual que los créditos que cubren. Solo el administrador los anula (31/10/2026).
 */
class CobroPolicy
{
    public function view(User $user, Cobro $cobro): bool
    {
        return $user->perteneceAEmpresa() && $user->empresa_id === $cobro->empresa_id;
    }

    public function anular(User $user, Cobro $cobro): bool
    {
        return $user->esAdministrador() && $this->view($user, $cobro);
    }
}
