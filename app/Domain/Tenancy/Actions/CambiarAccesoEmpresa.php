<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Auditoria\Auditor;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Activación/desactivación manual por el superadministrador. Ningún vencimiento,
 * recordatorio ni comprobante invoca esta acción. No borra ni altera datos históricos.
 */
final class CambiarAccesoEmpresa
{
    public function __construct(private readonly Auditor $auditor) {}

    public function ejecutar(User $actor, Empresa $empresa, bool $activa): Empresa
    {
        Gate::forUser($actor)->authorize('cambiarAcceso', $empresa);

        return DB::transaction(function () use ($actor, $empresa, $activa): Empresa {
            $bloqueada = Empresa::query()->whereKey($empresa->id)->lockForUpdate()->firstOrFail();

            if ($bloqueada->activa !== $activa) {
                $bloqueada->activa = $activa;
                $bloqueada->save();
                $this->auditor->registrar($activa ? 'empresa.activada' : 'empresa.desactivada', $bloqueada, $actor,
                    $bloqueada->id, ['activa' => ! $activa], ['activa' => $activa]);
            }

            return $bloqueada;
        });
    }
}
