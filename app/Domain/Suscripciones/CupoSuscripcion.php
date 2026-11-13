<?php

namespace App\Domain\Suscripciones;

use App\Models\Scopes\EmpresaScope;
use App\Models\Sucursal;
use App\Models\Suscripcion;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Cuántas sucursales y usuarios activos permite el plan de una empresa: lo incluido en el plan
 * más los extras. Solo frena dar de alta o reactivar por encima del cupo; lo que ya existe nunca
 * se desactiva por esto. Sin suscripción o sin plan (modo standalone, empresas antiguas), no hay
 * límite. Las comprobaciones bloquean la fila de la suscripción para que dos altas simultáneas
 * no pasen ambas el último lugar; deben llamarse dentro de la transacción del alta.
 */
final class CupoSuscripcion
{
    /**
     * @return array{sucursales: int|null, usuarios: int|null}
     */
    public static function limites(?Suscripcion $suscripcion, ?int $sucursalesExtra = null, ?int $paquetes = null): array
    {
        $plan = $suscripcion?->plan;

        if ($suscripcion === null || $plan === null) {
            return ['sucursales' => null, 'usuarios' => null];
        }

        $sucursalesExtra ??= $suscripcion->sucursales_extra;
        $paquetes ??= $suscripcion->paquetes_usuarios;

        return [
            'sucursales' => $plan->sucursales_incluidas === null ? null : $plan->sucursales_incluidas + $sucursalesExtra,
            'usuarios' => $plan->usuarios_incluidos === null ? null : $plan->usuarios_incluidos + $paquetes * $plan->usuarios_por_paquete,
        ];
    }

    /**
     * @return array{sucursales: int, usuarios: int}
     */
    public static function uso(int $empresaId): array
    {
        return [
            'sucursales' => Sucursal::withoutGlobalScopes()->where('empresa_id', $empresaId)->where('activa', true)->count(),
            'usuarios' => User::query()->where('empresa_id', $empresaId)->where('activo', true)->count(),
        ];
    }

    public function asegurarSucursalNueva(int $empresaId, string $campo = 'nombre'): void
    {
        $limite = self::limites($this->bloquear($empresaId))['sucursales'];

        if ($limite !== null && self::uso($empresaId)['sucursales'] >= $limite) {
            throw ValidationException::withMessages([$campo => trans_choice(
                'Tu plan permite :count sucursal activa y ya la usas. Agrega una sucursal extra en Mi suscripción.|Tu plan permite :count sucursales activas y ya las usas todas. Agrega una sucursal extra en Mi suscripción.',
                $limite,
            )]);
        }
    }

    public function asegurarUsuarioNuevo(int $empresaId, string $campo = 'name'): void
    {
        $limite = self::limites($this->bloquear($empresaId))['usuarios'];

        if ($limite !== null && self::uso($empresaId)['usuarios'] >= $limite) {
            throw ValidationException::withMessages([$campo => trans_choice(
                'Tu plan permite :count usuario activo y ya lo usas. Agrega un paquete de usuarios en Mi suscripción.|Tu plan permite :count usuarios activos y ya los usas todos. Agrega un paquete de usuarios en Mi suscripción.',
                $limite,
            )]);
        }
    }

    private function bloquear(int $empresaId): ?Suscripcion
    {
        return Suscripcion::withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $empresaId)->with('plan')->lockForUpdate()->first();
    }
}
