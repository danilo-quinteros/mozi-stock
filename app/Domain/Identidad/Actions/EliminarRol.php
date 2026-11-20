<?php

namespace App\Domain\Identidad\Actions;

use App\Domain\Auditoria\Auditor;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Eliminar un rol sin usuarios (ESPECIFICACION_PERMISOS.md § 6.5). Con usuarios, aunque estén inactivos,
 * primero hay que reasignarlos. La fila se bloquea para que nadie le asigne un usuario mientras se borra
 * (la FK compuesta de users también lo impide).
 */
final class EliminarRol
{
    public function __construct(private readonly Auditor $auditor) {}

    public function ejecutar(User $actor, Rol $rol): void
    {
        Gate::forUser($actor)->authorize('delete', $rol);

        DB::transaction(function () use ($actor, $rol): void {
            /** @var Rol $bloqueado */
            $bloqueado = Rol::query()->whereKey($rol->id)->lockForUpdate()->firstOrFail();
            $usuarios = User::query()->where('rol_id', $bloqueado->id)->count();

            if ($usuarios > 0) {
                throw ValidationException::withMessages([
                    'rol' => trans_choice('{1} El rol «:nombre» tiene 1 usuario. Asígnale otro rol antes de eliminarlo.|[2,*] El rol «:nombre» tiene :n usuarios. Asígnales otro rol antes de eliminarlo.', $usuarios, ['nombre' => $bloqueado->nombre, 'n' => $usuarios]),
                ]);
            }

            $antes = [
                'nombre' => $bloqueado->nombre,
                'permisos' => array_map(fn ($p): string => $p->value, $bloqueado->clavesPermisos()),
            ];
            $bloqueado->delete();

            $this->auditor->registrar('rol.eliminado', $bloqueado, $actor, $bloqueado->empresa_id, $antes);
        });
    }
}
