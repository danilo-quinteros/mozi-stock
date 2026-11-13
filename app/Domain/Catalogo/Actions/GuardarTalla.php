<?php

namespace App\Domain\Catalogo\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Catalogo\Variantes;
use App\Models\GrupoTalla;
use App\Models\Modelo;
use App\Models\Talla;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Tallas de un grupo (ESPECIFICACION_TALLAS_Y_COLORES.md § 3.1 y § 3.3). Solo el administrador, con la capacidad
 * activa. Renombrar una talla regenera con una sola sentencia el nombre de sus variantes (las ventas pasadas
 * conservan el suyo). Una talla en uso no se borra: se desactiva.
 */
final class GuardarTalla
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly Variantes $variantes,
    ) {}

    public function crear(User $actor, GrupoTalla $grupo, string $nombre): Talla
    {
        $empresaId = $this->autorizar($actor, $grupo->empresa_id);
        $nombre = $this->validarNombre($empresaId, $grupo->id, $nombre, null);

        return DB::transaction(function () use ($actor, $empresaId, $grupo, $nombre): Talla {
            $talla = new Talla(['nombre' => $nombre]);
            $talla->empresa_id = $empresaId;
            $talla->grupo_talla_id = $grupo->id;
            $talla->orden = (int) DB::table('tallas')->where('empresa_id', $empresaId)->where('grupo_talla_id', $grupo->id)->max('orden') + 1;
            $talla->save();
            $this->auditor->registrar('talla.creada', $talla, $actor, $empresaId, despues: ['grupo' => $grupo->nombre] + $talla->only(['nombre', 'orden', 'activa']));

            return $talla;
        });
    }

    /**
     * @return int variantes renombradas
     */
    public function actualizar(User $actor, Talla $talla, string $nombre, int $orden, bool $activa): int
    {
        $empresaId = $this->autorizar($actor, $talla->empresa_id);
        $nombre = $this->validarNombre($empresaId, $talla->grupo_talla_id, $nombre, $talla->id);

        if ($orden < 0 || $orden > 65535) {
            throw ValidationException::withMessages(['orden' => __('Escribe un orden entre 0 y 65535.')]);
        }

        return DB::transaction(function () use ($actor, $empresaId, $talla, $nombre, $orden, $activa): int {
            $bloqueada = Talla::query()->whereKey($talla->id)->lockForUpdate()->firstOrFail();
            $antes = $bloqueada->only(['nombre', 'orden', 'activa']);
            $bloqueada->nombre = $nombre;
            $bloqueada->orden = $orden;
            $bloqueada->activa = $activa;

            if (! $bloqueada->isDirty()) {
                return 0;
            }

            $renombrar = $bloqueada->isDirty('nombre');
            $bloqueada->save();
            $renombradas = $renombrar ? Variantes::regenerarNombres($empresaId, 'talla_id', $bloqueada->id) : 0;
            $this->auditor->registrar('talla.actualizada', $bloqueada, $actor, $empresaId, $antes,
                $bloqueada->only(['nombre', 'orden', 'activa']) + ($renombrar ? ['variantes_renombradas' => $renombradas] : []));
            $talla->setRawAttributes($bloqueada->getAttributes(), true);

            return $renombradas;
        });
    }

    /**
     * @return bool true si se eliminó; false si estaba en uso y solo se desactivó
     */
    public function eliminar(User $actor, Talla $talla): bool
    {
        $empresaId = $this->autorizar($actor, $talla->empresa_id);

        return DB::transaction(function () use ($actor, $empresaId, $talla): bool {
            $bloqueada = Talla::query()->whereKey($talla->id)->lockForUpdate()->firstOrFail();

            if (DB::table('productos')->where('empresa_id', $empresaId)->where('talla_id', $bloqueada->id)->exists()) {
                $antes = $bloqueada->only(['nombre', 'orden', 'activa']);
                $bloqueada->activa = false;
                $bloqueada->save();
                $this->auditor->registrar('talla.desactivada', $bloqueada, $actor, $empresaId, $antes, $bloqueada->only(['nombre', 'orden', 'activa']));
                $talla->activa = false;

                return false;
            }

            $this->auditor->registrar('talla.eliminada', $bloqueada, $actor, $empresaId, $bloqueada->only(['nombre', 'orden', 'activa']));
            $bloqueada->delete();

            return true;
        });
    }

    private function autorizar(User $actor, int $empresaRegistro): int
    {
        Gate::forUser($actor)->authorize('administrarListas', Modelo::class);
        $empresaId = (int) $actor->empresa_id;
        abort_if($empresaRegistro !== $empresaId, 404);
        $this->variantes->exigir($empresaId);

        return $empresaId;
    }

    private function validarNombre(int $empresaId, int $grupoId, string $nombre, ?int $ignorar): string
    {
        $nombre = trim($nombre);

        if ($nombre === '' || mb_strlen($nombre) > Variantes::MAX_NOMBRE_TALLA) {
            throw ValidationException::withMessages(['nombre' => __('Escribe una talla de hasta :max caracteres.', ['max' => Variantes::MAX_NOMBRE_TALLA])]);
        }

        if (Talla::query()->where('empresa_id', $empresaId)->where('grupo_talla_id', $grupoId)->where('nombre', $nombre)
            ->when($ignorar !== null, fn ($q) => $q->whereKeyNot($ignorar))->exists()) {
            throw ValidationException::withMessages(['nombre' => __('Esa talla ya está en el grupo.')]);
        }

        return $nombre;
    }
}
