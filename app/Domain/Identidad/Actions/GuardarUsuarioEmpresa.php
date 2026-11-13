<?php

namespace App\Domain\Identidad\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Tenancy\CapacidadesEmpresa;
use App\Enums\Capacidad;
use App\Enums\Rol;
use App\Models\Caja;
use App\Models\Sucursal;
use App\Models\TurnoCaja;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Usuarios de una empresa creados y editados por su administrador.
 * La empresa siempre es la del administrador; el rol nunca puede ser superadmin y
 * el vendedor queda fijado a una sucursal activa de la misma empresa y a una caja activa de
 * esa sucursal (Decisión C); varios vendedores pueden compartir la misma caja.
 * Los usuarios no se eliminan: se desactivan para conservar la trazabilidad.
 * Con la capacidad «despachos», el vendedor puede llevar el permiso de almacén (puede despachar las ventas de
 * su sucursal); el administrador siempre puede, así que la marca solo se guarda en vendedores.
 */
final class GuardarUsuarioEmpresa
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly CapacidadesEmpresa $capacidades,
    ) {}

    /**
     * @param  array{name: string, email: string, rol: Rol, sucursal_id: int|null, caja_id?: int|null, password: string, activo: bool, puede_despachar?: bool}  $datos
     */
    public function crear(User $actor, array $datos): User
    {
        Gate::forUser($actor)->authorize('create', User::class);

        return DB::transaction(function () use ($actor, $datos): User {
            $usuario = new User;
            $usuario->empresa_id = $actor->empresa_id;
            $this->aplicar($actor, $usuario, $datos);
            $usuario->password = $datos['password'];
            // Cuenta aprovisionada por el administrador; no depende de la entrega de correo.
            $usuario->email_verified_at = Carbon::now();
            $usuario->save();

            $this->auditor->registrar('usuario.creado', $usuario, $actor, $usuario->empresa_id,
                despues: $this->resumen($usuario));

            return $usuario;
        });
    }

    /**
     * @param  array{name: string, email: string, rol: Rol, sucursal_id: int|null, caja_id?: int|null, password?: string|null, activo: bool, puede_despachar?: bool}  $datos
     */
    public function actualizar(User $actor, User $usuario, array $datos): User
    {
        Gate::forUser($actor)->authorize('update', $usuario);

        if ($usuario->is($actor) && ($datos['rol'] !== Rol::Administrador || ! $datos['activo'])) {
            throw ValidationException::withMessages([
                'rol' => __('No puedes quitarte el rol de administrador ni desactivar tu propia cuenta.'),
            ]);
        }

        return DB::transaction(function () use ($actor, $usuario, $datos): User {
            $antes = $this->resumen($usuario);
            $this->aplicar($actor, $usuario, $datos);
            $cambioPassword = filled($datos['password'] ?? null);

            if ($cambioPassword) {
                $usuario->password = (string) $datos['password'];
            }

            if ($usuario->isDirty()) {
                $usuario->save();
                $this->auditor->registrar('usuario.actualizado', $usuario, $actor, $usuario->empresa_id,
                    $antes, $this->resumen($usuario) + ($cambioPassword ? ['password_restablecida' => true] : []));
            }

            return $usuario;
        });
    }

    /**
     * Cambia solo la caja de uno o varios vendedores desde Sucursales y cajas (§ 6.3), todo o nada y
     * con las reglas del formulario de usuarios: caja activa de la sucursal de cada vendedor y sin un
     * turno abierto por él que siga abierto.
     *
     * @param  list<int>  $vendedorIds
     * @return int vendedores cuya caja cambió
     */
    public function asignarCaja(User $actor, int $cajaId, array $vendedorIds): int
    {
        Gate::forUser($actor)->authorize('create', User::class);
        $ids = array_values(array_unique(array_map('intval', $vendedorIds)));

        return DB::transaction(function () use ($actor, $cajaId, $ids): int {
            $vendedores = User::query()
                ->where('empresa_id', $actor->empresa_id)
                ->where('rol', Rol::Vendedor)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($vendedores->count() !== count($ids)) {
                throw ValidationException::withMessages(['caja_id' => __('Solo puedes asignar cajas a vendedores de tu empresa.')]);
            }

            $movidos = 0;

            foreach ($vendedores as $vendedor) {
                Gate::forUser($actor)->authorize('update', $vendedor);

                if ((int) $vendedor->caja_id === $cajaId) {
                    continue;
                }

                $antes = $this->resumen($vendedor);
                $this->aplicar($actor, $vendedor, [
                    'name' => $vendedor->name, 'email' => $vendedor->email, 'rol' => Rol::Vendedor,
                    'sucursal_id' => $vendedor->sucursal_id, 'caja_id' => $cajaId, 'activo' => $vendedor->activo,
                ]);
                $vendedor->save();
                $this->auditor->registrar('usuario.caja_asignada', $vendedor, $actor, $vendedor->empresa_id, $antes, $this->resumen($vendedor));
                $movidos++;
            }

            return $movidos;
        });
    }

    /**
     * @param  array{name: string, email: string, rol: Rol, sucursal_id: int|null, caja_id?: int|null, activo: bool, puede_despachar?: bool}  $datos
     */
    private function aplicar(User $actor, User $usuario, array $datos): void
    {
        if (! in_array($datos['rol'], Rol::asignablesPorEmpresa(), true)) {
            throw ValidationException::withMessages(['rol' => __('Selecciona un rol válido.')]);
        }

        $sucursalId = null;
        $cajaId = null;

        if ($datos['rol'] === Rol::Vendedor) {
            $sucursalId = $datos['sucursal_id'];
            $valida = $sucursalId !== null && Sucursal::withoutGlobalScopes()
                ->whereKey($sucursalId)
                ->where('empresa_id', $actor->empresa_id)
                ->where('activa', true)
                ->exists();

            if (! $valida) {
                throw ValidationException::withMessages([
                    'sucursal_id' => __('El vendedor necesita una sucursal activa de tu empresa.'),
                ]);
            }

            $cajaId = $datos['caja_id'] ?? null;
            $cajaValida = $cajaId !== null && Caja::withoutGlobalScopes()
                ->whereKey($cajaId)
                ->where('empresa_id', $actor->empresa_id)
                ->where('sucursal_id', $sucursalId)
                ->where('activa', true)
                ->exists();

            if (! $cajaValida) {
                throw ValidationException::withMessages([
                    'caja_id' => __('El vendedor necesita una caja activa de su propia sucursal.'),
                ]);
            }
        }

        // Un turno abierto por el usuario queda en su caja: no se le cambia de caja o sucursal
        // hasta cerrarlo, para que nadie trabaje en una caja de otra sucursal.
        if ($usuario->exists && $usuario->esVendedor() && (int) $usuario->caja_id !== (int) $cajaId) {
            $abierto = TurnoCaja::withoutGlobalScopes()
                ->where('empresa_id', $usuario->empresa_id)
                ->where('usuario_apertura_id', $usuario->id)
                ->where('estado', 'abierto')
                ->with('caja:id,nombre')
                ->first();

            if ($abierto !== null) {
                throw ValidationException::withMessages([
                    'caja_id' => __(':nombre tiene abierto el turno #:turno en :caja. Ciérralo antes de cambiar su caja o sucursal.', [
                        'nombre' => $usuario->name, 'turno' => $abierto->id, 'caja' => $abierto->caja->nombre,
                    ]),
                ]);
            }
        }

        $usuario->name = trim($datos['name']);
        $usuario->email = Str::lower(trim($datos['email']));
        $usuario->rol = $datos['rol'];
        // El administrador opera en todas las sucursales; el vendedor solo en la asignada.
        $usuario->sucursal_id = $sucursalId;
        $usuario->caja_id = $cajaId;
        $usuario->activo = $datos['activo'];

        // Permiso de almacén: sin la capacidad, o si el dato no llega (p. ej. al asignar caja), se conserva.
        if ($datos['rol'] !== Rol::Vendedor) {
            $usuario->puede_despachar = false;
        } elseif (array_key_exists('puede_despachar', $datos)
            && $this->capacidades->tiene((int) $actor->empresa_id, Capacidad::Despachos)) {
            $usuario->puede_despachar = $datos['puede_despachar'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resumen(User $usuario): array
    {
        return [
            'name' => $usuario->name,
            'email' => $usuario->email,
            'rol' => $usuario->rol?->value,
            'sucursal_id' => $usuario->sucursal_id,
            'caja_id' => $usuario->caja_id,
            'activo' => $usuario->activo,
            'puede_despachar' => $usuario->puede_despachar,
        ];
    }
}
