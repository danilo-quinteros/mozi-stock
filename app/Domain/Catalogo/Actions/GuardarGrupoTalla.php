<?php

namespace App\Domain\Catalogo\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Catalogo\Variantes;
use App\Models\GrupoTalla;
use App\Models\Modelo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Grupos de tallas de la empresa (ESPECIFICACION_TALLAS_Y_COLORES.md § 3.1). Solo el administrador, con la capacidad
 * activa. Un grupo en uso (algún modelo lo usa o alguna de sus tallas está en una variante) no se borra: se
 * desactiva y deja de ofrecerse para modelos nuevos.
 */
final class GuardarGrupoTalla
{
    public const MAX_NOMBRE = 40;

    public function __construct(
        private readonly Auditor $auditor,
        private readonly Variantes $variantes,
    ) {}

    public function crear(User $actor, string $nombre): GrupoTalla
    {
        $empresaId = $this->autorizar($actor);
        $nombre = $this->validarNombre($empresaId, $nombre, null);

        return DB::transaction(function () use ($actor, $empresaId, $nombre): GrupoTalla {
            $grupo = new GrupoTalla(['nombre' => $nombre]);
            $grupo->empresa_id = $empresaId;
            $grupo->orden = (int) DB::table('grupos_talla')->where('empresa_id', $empresaId)->max('orden') + 1;
            $grupo->save();
            $this->auditor->registrar('grupo_talla.creado', $grupo, $actor, $empresaId, despues: $grupo->only(['nombre', 'activo']));

            return $grupo;
        });
    }

    public function actualizar(User $actor, GrupoTalla $grupo, string $nombre, bool $activo): GrupoTalla
    {
        $empresaId = $this->autorizar($actor, $grupo->empresa_id);
        $nombre = $this->validarNombre($empresaId, $nombre, $grupo->id);
        $antes = $grupo->only(['nombre', 'activo']);
        $grupo->nombre = $nombre;
        $grupo->activo = $activo;

        if ($grupo->isDirty()) {
            DB::transaction(function () use ($actor, $grupo, $antes, $empresaId): void {
                $grupo->save();
                $this->auditor->registrar('grupo_talla.actualizado', $grupo, $actor, $empresaId, $antes, $grupo->only(['nombre', 'activo']));
            });
        }

        return $grupo;
    }

    /**
     * @return bool true si se eliminó; false si estaba en uso y solo se desactivó
     */
    public function eliminar(User $actor, GrupoTalla $grupo): bool
    {
        $empresaId = $this->autorizar($actor, $grupo->empresa_id);

        return DB::transaction(function () use ($actor, $grupo, $empresaId): bool {
            $bloqueado = GrupoTalla::query()->whereKey($grupo->id)->lockForUpdate()->firstOrFail();
            $enUso = Modelo::query()->where('grupo_talla_id', $bloqueado->id)->exists()
                || DB::table('productos as p')->join('tallas as t', 't.id', '=', 'p.talla_id')
                    ->where('p.empresa_id', $empresaId)->where('t.empresa_id', $empresaId)->where('t.grupo_talla_id', $bloqueado->id)->exists();

            if ($enUso) {
                $antes = $bloqueado->only(['nombre', 'activo']);
                $bloqueado->activo = false;
                $bloqueado->save();
                $this->auditor->registrar('grupo_talla.desactivado', $bloqueado, $actor, $empresaId, $antes, $bloqueado->only(['nombre', 'activo']));
                $grupo->activo = false;

                return false;
            }

            DB::table('tallas')->where('empresa_id', $empresaId)->where('grupo_talla_id', $bloqueado->id)->delete();
            $this->auditor->registrar('grupo_talla.eliminado', $bloqueado, $actor, $empresaId, $bloqueado->only(['nombre', 'activo']));
            $bloqueado->delete();

            return true;
        });
    }

    private function autorizar(User $actor, ?int $empresaRegistro = null): int
    {
        Gate::forUser($actor)->authorize('administrarListas', Modelo::class);
        $empresaId = (int) $actor->empresa_id;
        abort_if($empresaRegistro !== null && $empresaRegistro !== $empresaId, 404);
        $this->variantes->exigir($empresaId);

        return $empresaId;
    }

    private function validarNombre(int $empresaId, string $nombre, ?int $ignorar): string
    {
        $nombre = trim($nombre);

        if ($nombre === '' || mb_strlen($nombre) > self::MAX_NOMBRE) {
            throw ValidationException::withMessages(['nombre' => __('Escribe un nombre de hasta :max caracteres.', ['max' => self::MAX_NOMBRE])]);
        }

        if (GrupoTalla::query()->where('empresa_id', $empresaId)->where('nombre', $nombre)->when($ignorar !== null, fn ($q) => $q->whereKeyNot($ignorar))->exists()) {
            throw ValidationException::withMessages(['nombre' => __('Ya existe un grupo de tallas con ese nombre.')]);
        }

        return $nombre;
    }
}
