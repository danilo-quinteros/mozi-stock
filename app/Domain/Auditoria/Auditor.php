<?php

namespace App\Domain\Auditoria;

use App\Models\Auditoria;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Registra eventos críticos (activaciones, roles, sucursales). Los llamadores envían
 * solo campos permitidos: nunca contraseñas, tokens ni secretos.
 */
final class Auditor
{
    /**
     * @param  array<string, mixed>|null  $antes
     * @param  array<string, mixed>|null  $despues
     */
    public function registrar(
        string $accion,
        Model $entidad,
        ?User $actor,
        ?int $empresaId,
        ?array $antes = null,
        ?array $despues = null,
    ): Auditoria {
        $registro = new Auditoria;
        $registro->empresa_id = $empresaId;
        $registro->usuario_id = $actor?->id;
        $registro->accion = $accion;
        $registro->entidad = class_basename($entidad);
        $registro->entidad_id = (int) $entidad->getKey();
        $registro->antes = $antes;
        $registro->despues = $despues;
        $registro->ip = request()->ip();
        $registro->save();

        return $registro;
    }
}
