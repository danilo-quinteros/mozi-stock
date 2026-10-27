<?php

namespace App\Domain\Identidad\Actions;

use App\Domain\Auditoria\Auditor;
use App\Enums\Rol;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Usuarios de una empresa creados y editados por su administrador.
 * La empresa siempre es la del administrador; el rol nunca puede ser superadmin y
 * el vendedor queda fijado a una sucursal activa de la misma empresa.
 * Los usuarios no se eliminan: se desactivan para conservar la trazabilidad.
 */
final class GuardarUsuarioEmpresa
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  array{name: string, email: string, rol: Rol, sucursal_id: int|null, password: string, activo: bool}  $datos
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
     * @param  array{name: string, email: string, rol: Rol, sucursal_id: int|null, password?: string|null, activo: bool}  $datos
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
     * @param  array{name: string, email: string, rol: Rol, sucursal_id: int|null, activo: bool}  $datos
     */
    private function aplicar(User $actor, User $usuario, array $datos): void
    {
        if (! in_array($datos['rol'], Rol::asignablesPorEmpresa(), true)) {
            throw ValidationException::withMessages(['rol' => __('Selecciona un rol válido.')]);
        }

        $sucursalId = null;

        if ($datos['rol'] === Rol::Vendedor) {
            $sucursalId = $datos['sucursal_id'];
            $valida = $sucursalId !== null && Sucursal::query()
                ->whereKey($sucursalId)
                ->where('empresa_id', $actor->empresa_id)
                ->where('activa', true)
                ->exists();

            if (! $valida) {
                throw ValidationException::withMessages([
                    'sucursal_id' => __('El vendedor necesita una sucursal activa de tu empresa.'),
                ]);
            }
        }

        $usuario->name = trim($datos['name']);
        $usuario->email = Str::lower(trim($datos['email']));
        $usuario->rol = $datos['rol'];
        // El administrador opera en todas las sucursales; el vendedor solo en la asignada.
        $usuario->sucursal_id = $sucursalId;
        $usuario->activo = $datos['activo'];
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
            'activo' => $usuario->activo,
        ];
    }
}
