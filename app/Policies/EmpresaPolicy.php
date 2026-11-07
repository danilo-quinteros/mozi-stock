<?php

namespace App\Policies;

use App\Models\Empresa;
use App\Models\User;

class EmpresaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->esSuperadmin();
    }

    public function create(User $user): bool
    {
        return $user->esSuperadmin();
    }

    /**
     * Datos generales: el superadministrador o el administrador de esa misma empresa.
     */
    public function update(User $user, Empresa $empresa): bool
    {
        return $user->esSuperadmin()
            || ($user->esAdministrador() && $user->empresa_id === $empresa->id);
    }

    /**
     * Rubro y funciones del negocio: el administrador de esa misma empresa o el superadministrador.
     * Un vendedor nunca, aunque llame a la acción directamente.
     */
    public function cambiarFunciones(User $user, Empresa $empresa): bool
    {
        return $user->esSuperadmin()
            || ($user->esAdministrador() && $user->empresa_id === $empresa->id);
    }

    /**
     * Activar o desactivar el acceso es una decisión manual exclusiva del superadministrador.
     */
    public function cambiarAcceso(User $user, Empresa $empresa): bool
    {
        return $user->esSuperadmin();
    }

    /**
     * Habilitar la facturación SIAT de una empresa es una decisión del superadministrador (decisión D6).
     */
    public function habilitarFacturacion(User $user, Empresa $empresa): bool
    {
        return $user->esSuperadmin();
    }
}
