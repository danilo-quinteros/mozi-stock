<?php

namespace App\Domain\Tenancy;

use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * Empresa activa de la petición, resuelta siempre en el servidor desde el usuario autenticado.
 * Nunca se toma de un empresa_id enviado por el cliente.
 */
final class TenantContext
{
    public function __construct(private readonly AuthFactory $auth) {}

    public function empresaId(): ?int
    {
        $user = $this->auth->guard()->user();

        return $user instanceof User && $user->perteneceAEmpresa() ? $user->empresa_id : null;
    }
}
