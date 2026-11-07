<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Caja\Actions\CrearCajaPrincipal;
use App\Enums\Rol;
use App\Models\Sucursal;
use App\Models\TurnoCaja;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Alta, edición y estado de sucursales. Nunca se eliminan: conservan su historial.
 */
final class GuardarSucursal
{
    private const CAMPOS = ['nombre', 'codigo', 'direccion', 'activa'];

    public function __construct(
        private readonly Auditor $auditor,
        private readonly CrearCajaPrincipal $crearCajaPrincipal,
    ) {}

    /**
     * @param  array{nombre: string, codigo: string, direccion?: string|null}  $datos
     */
    public function crear(User $actor, array $datos): Sucursal
    {
        Gate::forUser($actor)->authorize('create', Sucursal::class);

        return DB::transaction(function () use ($actor, $datos): Sucursal {
            $sucursal = new Sucursal;
            $sucursal->fill($this->normalizar($datos));
            $sucursal->empresa_id = (int) $actor->empresa_id;
            $sucursal->activa = true;
            $sucursal->save();

            $this->crearCajaPrincipal->ejecutar($actor, $sucursal);

            $this->auditor->registrar('sucursal.creada', $sucursal, $actor, $sucursal->empresa_id,
                despues: $sucursal->only(self::CAMPOS));

            return $sucursal;
        });
    }

    /**
     * @param  array{nombre: string, codigo: string, direccion?: string|null}  $datos
     */
    public function actualizar(User $actor, Sucursal $sucursal, array $datos): Sucursal
    {
        Gate::forUser($actor)->authorize('update', $sucursal);

        return DB::transaction(function () use ($actor, $sucursal, $datos): Sucursal {
            $antes = $sucursal->only(self::CAMPOS);
            $sucursal->fill($this->normalizar($datos));

            if ($sucursal->isDirty()) {
                $sucursal->save();
                $this->auditor->registrar('sucursal.actualizada', $sucursal, $actor, $sucursal->empresa_id,
                    $antes, $sucursal->only(self::CAMPOS));
            }

            return $sucursal;
        });
    }

    public function cambiarEstado(User $actor, Sucursal $sucursal, bool $activa): Sucursal
    {
        Gate::forUser($actor)->authorize('update', $sucursal);

        return DB::transaction(function () use ($actor, $sucursal, $activa): Sucursal {
            $bloqueada = Sucursal::query()->whereKey($sucursal->id)->lockForUpdate()->firstOrFail();

            if ($bloqueada->activa === $activa) {
                return $bloqueada;
            }

            if (! $activa && User::query()
                ->where('empresa_id', $bloqueada->empresa_id)
                ->where('sucursal_id', $bloqueada->id)
                ->where('rol', Rol::Vendedor)
                ->where('activo', true)
                ->exists()) {
                throw ValidationException::withMessages([
                    'sucursal' => __('Hay vendedores activos asignados a esta sucursal. Reasígnalos o desactívalos antes.'),
                ]);
            }

            // Un turno abierto debe cerrarse y arquearse en su sucursal antes de dejarla sin operaciones.
            if (! $activa && TurnoCaja::withoutGlobalScopes()
                ->where('empresa_id', $bloqueada->empresa_id)
                ->where('sucursal_id', $bloqueada->id)
                ->where('estado', 'abierto')
                ->exists()) {
                throw ValidationException::withMessages([
                    'sucursal' => __('Hay cajas con turno abierto en esta sucursal. Ciérralas antes de desactivarla.'),
                ]);
            }

            $bloqueada->activa = $activa;
            $bloqueada->save();
            $this->auditor->registrar($activa ? 'sucursal.activada' : 'sucursal.desactivada', $bloqueada, $actor,
                $bloqueada->empresa_id, ['activa' => ! $activa], ['activa' => $activa]);

            return $bloqueada;
        });
    }

    /**
     * @param  array{nombre: string, codigo: string, direccion?: string|null}  $datos
     * @return array{nombre: string, codigo: string, direccion: string|null}
     */
    private function normalizar(array $datos): array
    {
        $direccion = trim((string) ($datos['direccion'] ?? ''));

        return [
            'nombre' => trim($datos['nombre']),
            'codigo' => Str::upper(trim($datos['codigo'])),
            'direccion' => $direccion === '' ? null : $direccion,
        ];
    }
}
