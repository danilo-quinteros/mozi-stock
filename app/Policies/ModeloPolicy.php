<?php

namespace App\Policies;

use App\Models\Modelo;
use App\Models\User;

/**
 * Tallas y colores (ESPECIFICACION_TALLAS_Y_COLORES.md § 7): todos los usuarios de la empresa ven los modelos y
 * venden sus variantes; solo el administrador crea o edita modelos, cambia precios y administra las listas de
 * tallas y colores. La capacidad «variantes» se exige aparte (Gate «capacidad»).
 */
class ModeloPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->perteneceAEmpresa();
    }

    public function view(User $user, Modelo $modelo): bool
    {
        return $user->perteneceAEmpresa() && $user->empresa_id === $modelo->empresa_id;
    }

    public function create(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function update(User $user, Modelo $modelo): bool
    {
        return $user->esAdministrador() && $user->empresa_id === $modelo->empresa_id;
    }

    /** Listas de tallas y colores de la empresa (§ 3). */
    public function administrarListas(User $user): bool
    {
        return $user->esAdministrador();
    }
}
