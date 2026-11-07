<?php

namespace App\Domain\Caja\Actions;

use App\Domain\Auditoria\Auditor;
use App\Models\Caja;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class GuardarCaja
{
    private const CAMPOS = ['nombre', 'codigo', 'modalidad', 'activa', 'es_principal'];

    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  array{nombre: string, codigo?: string|null, modalidad?: string, sucursal_id: int}  $datos
     */
    public function crear(User $actor, array $datos): Caja
    {
        Gate::forUser($actor)->authorize('create', Sucursal::class);

        return DB::transaction(function () use ($actor, $datos): Caja {
            $sucursal = Sucursal::withoutGlobalScopes()
                ->where('empresa_id', $actor->empresa_id)
                ->whereKey($datos['sucursal_id'])
                ->firstOrFail();

            $caja = new Caja;
            $caja->empresa_id = $sucursal->empresa_id;
            $caja->sucursal_id = $sucursal->id;
            $caja->nombre = trim($datos['nombre']);
            $caja->codigo = isset($datos['codigo']) && trim($datos['codigo']) !== '' ? trim($datos['codigo']) : null;
            $caja->modalidad = in_array($datos['modalidad'] ?? '', ['compartida', 'individual'], true) ? $datos['modalidad'] : 'compartida';
            $caja->activa = true;
            $caja->es_principal = false;
            $caja->save();

            $this->auditor->registrar('caja.creada', $caja, $actor, $caja->empresa_id,
                despues: $caja->only(self::CAMPOS + ['sucursal_id']));

            return $caja;
        });
    }

    /**
     * @param  array{nombre: string, codigo?: string|null, modalidad?: string}  $datos
     */
    public function actualizar(User $actor, Caja $caja, array $datos): Caja
    {
        Gate::forUser($actor)->authorize('update', $caja->sucursal);

        return DB::transaction(function () use ($actor, $caja, $datos): Caja {
            $caja = Caja::withoutGlobalScopes()
                ->where('empresa_id', $actor->empresa_id)
                ->whereKey($caja->id)
                ->lockForUpdate()
                ->firstOrFail();
            $antes = $caja->only(self::CAMPOS);

            // Cambiar la modalidad a mitad de turno cambiaría quién puede operarlo.
            if (isset($datos['modalidad']) && $datos['modalidad'] !== $caja->modalidad && $caja->tieneTurnoAbierto()) {
                throw ValidationException::withMessages([
                    'modalidad' => __('Cierra el turno abierto de esta caja antes de cambiar su modalidad.'),
                ]);
            }

            $caja->nombre = trim($datos['nombre']);
            if (array_key_exists('codigo', $datos)) {
                $caja->codigo = trim((string) $datos['codigo']) !== '' ? trim((string) $datos['codigo']) : null;
            }
            if (isset($datos['modalidad']) && in_array($datos['modalidad'], ['compartida', 'individual'], true)) {
                $caja->modalidad = $datos['modalidad'];
            }

            if ($caja->isDirty()) {
                $caja->save();
                $this->auditor->registrar('caja.actualizada', $caja, $actor, $caja->empresa_id,
                    $antes, $caja->only(self::CAMPOS));
            }

            return $caja;
        });
    }

    public function cambiarEstado(User $actor, Caja $caja, bool $activa): Caja
    {
        Gate::forUser($actor)->authorize('update', $caja->sucursal);

        return DB::transaction(function () use ($actor, $caja, $activa): Caja {
            $bloqueada = Caja::withoutGlobalScopes()
                ->where('empresa_id', $actor->empresa_id)
                ->whereKey($caja->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($bloqueada->activa === $activa) {
                return $bloqueada;
            }

            if (! $activa && $bloqueada->tieneTurnoAbierto()) {
                throw ValidationException::withMessages([
                    'caja' => __('No se puede desactivar una caja con un turno abierto. Ciérralo primero.'),
                ]);
            }

            $bloqueada->activa = $activa;
            $bloqueada->save();

            $this->auditor->registrar($activa ? 'caja.activada' : 'caja.desactivada', $bloqueada, $actor,
                $bloqueada->empresa_id, ['activa' => ! $activa], ['activa' => $activa]);

            return $bloqueada;
        });
    }
}
