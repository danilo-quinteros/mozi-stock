<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Suscripciones\Actions\ConfigurarSuscripcion;
use App\Enums\Rubro;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Alta de una empresa por el superadmin: sucursal «Casa Matriz», su primer administrador y, si se
 * indica, su suscripción SaaS (precio y primer vencimiento), en una sola transacción.
 */
final class CrearEmpresa
{
    public function __construct(
        private readonly AprovisionarEmpresa $aprovisionar,
        private readonly ConfigurarSuscripcion $configurarSuscripcion,
    ) {}

    /**
     * @param  array{nombre: string, rubro: Rubro, razon_social?: string|null, nit?: string|null, email?: string|null, telefono?: string|null}  $datos  el rubro es obligatorio: enciende sus capacidades en la misma transacción
     * @param  array{name: string, email: string, password: string}  $administrador
     * @param  array{precio_mensual: string, vencimiento: string, plan_id?: int|null}|null  $suscripcion
     */
    public function ejecutar(User $actor, array $datos, array $administrador, ?array $suscripcion = null): Empresa
    {
        Gate::forUser($actor)->authorize('create', Empresa::class);

        return DB::transaction(function () use ($actor, $datos, $administrador, $suscripcion): Empresa {
            [$empresa] = $this->aprovisionar->ejecutar($actor, $datos, $administrador, correoVerificado: true);

            if ($suscripcion !== null) {
                $this->configurarSuscripcion->ejecutar($actor, $empresa, $suscripcion['precio_mensual'], $suscripcion['vencimiento'], null, $suscripcion['plan_id'] ?? null);
            }

            return $empresa;
        });
    }
}
