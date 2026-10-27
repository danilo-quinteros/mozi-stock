<?php

namespace App\Policies;

use App\Models\PagoSaas;
use App\Models\User;

/**
 * Comprobantes de pago SaaS: los envía el administrador de la empresa y los revisa solo
 * el superadministrador (§ 3.12). Los vendedores no participan.
 */
class PagoSaasPolicy
{
    public function viewAny(User $user): bool
    {
        return SuscripcionPolicy::saas() && ($user->esSuperadmin() || $user->esAdministrador());
    }

    public function view(User $user, PagoSaas $pago): bool
    {
        return SuscripcionPolicy::saas() && ($user->esSuperadmin()
            || ($user->esAdministrador() && $user->empresa_id === $pago->empresa_id));
    }

    public function create(User $user): bool
    {
        return SuscripcionPolicy::saas() && $user->esAdministrador();
    }

    public function revisar(User $user): bool
    {
        return SuscripcionPolicy::saas() && $user->esSuperadmin();
    }
}
