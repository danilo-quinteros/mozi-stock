<?php

namespace App\Domain\Tenancy;

use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Sucursal en la que una operación afecta stock o caja.
 * El vendedor opera siempre en la sucursal que le asignó el administrador: cualquier
 * otra sucursal enviada por el navegador se ignora. El administrador elige entre las
 * sucursales activas de su propia empresa.
 */
final class ResolverSucursal
{
    public function paraOperacion(User $usuario, ?int $solicitada): Sucursal
    {
        $sucursalId = match (true) {
            $usuario->esVendedor() => $usuario->sucursal_id,
            $usuario->esAdministrador() => $solicitada,
            default => throw new AuthorizationException(__('Tu cuenta no puede operar en sucursales.')),
        };

        $sucursal = $sucursalId === null ? null : Sucursal::query()
            ->whereKey($sucursalId)
            ->where('empresa_id', $usuario->empresa_id)
            ->where('activa', true)
            ->first();

        if ($sucursal === null) {
            throw ValidationException::withMessages([
                'sucursal_id' => $usuario->esVendedor()
                    ? __('Tu sucursal asignada no está activa. Comunícate con el administrador.')
                    : __('Selecciona una sucursal activa de tu empresa.'),
            ]);
        }

        return $sucursal;
    }
}
