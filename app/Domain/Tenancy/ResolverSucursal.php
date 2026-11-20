<?php

namespace App\Domain\Tenancy;

use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Sucursal en la que una operación afecta stock o caja.
 * Quien no trabaja en todas las sucursales opera siempre en la que le asignó el administrador:
 * cualquier otra sucursal enviada por el navegador se ignora. El administrador y quien tiene
 * «Trabajar en todas las sucursales» eligen entre las sucursales activas de su propia empresa.
 */
final class ResolverSucursal
{
    public function paraOperacion(User $usuario, ?int $solicitada): Sucursal
    {
        // Sin «Trabajar en todas las sucursales» se ignora la sucursal pedida (ESPECIFICACION_PERMISOS.md § 6.2).
        $sucursalId = match (true) {
            ! $usuario->perteneceAEmpresa() => throw new AuthorizationException(__('Tu cuenta no puede operar en sucursales.')),
            $usuario->veTodasLasSucursales() => $solicitada,
            default => $usuario->sucursal_id,
        };

        $sucursal = $sucursalId === null ? null : Sucursal::query()
            ->whereKey($sucursalId)
            ->where('empresa_id', $usuario->empresa_id)
            ->where('activa', true)
            ->first();

        if ($sucursal === null) {
            throw ValidationException::withMessages([
                'sucursal_id' => $usuario->veTodasLasSucursales()
                    ? __('Selecciona una sucursal activa de tu empresa.')
                    : __('Tu sucursal asignada no está activa. Comunícate con el administrador.'),
            ]);
        }

        return $sucursal;
    }
}
