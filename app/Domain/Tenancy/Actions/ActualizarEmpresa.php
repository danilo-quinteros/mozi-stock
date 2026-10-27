<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Auditoria\Auditor;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Datos generales de la empresa. No cambia el acceso ni SIAT.
 */
final class ActualizarEmpresa
{
    private const CAMPOS = ['nombre', 'razon_social', 'nit', 'email', 'telefono'];

    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  array{nombre: string, razon_social?: string|null, nit?: string|null, email?: string|null, telefono?: string|null}  $datos
     */
    public function ejecutar(User $actor, Empresa $empresa, array $datos): Empresa
    {
        Gate::forUser($actor)->authorize('update', $empresa);

        return DB::transaction(function () use ($actor, $empresa, $datos): Empresa {
            $antes = $empresa->only(self::CAMPOS);
            $empresa->fill(array_intersect_key($datos, array_flip(self::CAMPOS)));

            if ($empresa->isDirty()) {
                $empresa->save();
                $this->auditor->registrar('empresa.actualizada', $empresa, $actor, $empresa->id,
                    $antes, $empresa->only(self::CAMPOS));
            }

            return $empresa;
        });
    }
}
