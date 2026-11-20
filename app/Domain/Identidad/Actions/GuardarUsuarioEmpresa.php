<?php

namespace App\Domain\Identidad\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Identidad\PermisosUsuario;
use App\Domain\Suscripciones\CupoSuscripcion;
use App\Enums\Permiso;
use App\Enums\TipoCuenta;
use App\Models\Caja;
use App\Models\Rol;
use App\Models\Sucursal;
use App\Models\TurnoCaja;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Usuarios de una empresa creados y editados por su administrador (ESPECIFICACION_PERMISOS.md § 6.4 y § 8).
 *
 * - La empresa siempre es la del administrador; el tipo nunca puede ser superadmin.
 * - Un usuario de tipo «usuario» tiene un rol de la misma empresa, una sucursal activa asignada (inmutable para
 *   él) y, si usa su caja sin supervisar cajas, una caja activa de esa sucursal; varios pueden compartirla.
 * - Los permisos extra solo suman a los del rol: se guardan con sus requeridos y sin los que ya da el rol.
 * - Nadie cambia su propio tipo ni se desactiva, y la empresa conserva siempre un administrador activo.
 * - Los usuarios no se eliminan: se desactivan para conservar la trazabilidad.
 */
final class GuardarUsuarioEmpresa
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly CupoSuscripcion $cupo,
        private readonly PermisosUsuario $permisos,
    ) {}

    /**
     * @param  array{name: string, email: string, tipo: TipoCuenta, rol_id?: int|null, sucursal_id: int|null, caja_id?: int|null, password: string, activo: bool, permisos_extra?: list<string>}  $datos
     */
    public function crear(User $actor, array $datos): User
    {
        Gate::forUser($actor)->authorize('create', User::class);

        return DB::transaction(function () use ($actor, $datos): User {
            // Usuarios del plan + paquetes (Mi suscripción). Un usuario creado inactivo no ocupa lugar.
            if ($datos['activo']) {
                $this->cupo->asegurarUsuarioNuevo((int) $actor->empresa_id);
            }

            $usuario = new User;
            $usuario->empresa_id = $actor->empresa_id;
            $extras = $this->aplicar($actor, $usuario, $datos);
            $usuario->password = $datos['password'];
            // Cuenta aprovisionada por el administrador; no depende de la entrega de correo.
            $usuario->email_verified_at = Carbon::now();
            $usuario->save();
            $this->escribirExtras($actor, $usuario, $extras);

            $this->auditor->registrar('usuario.creado', $usuario, $actor, $usuario->empresa_id,
                despues: $this->resumen($usuario));

            return $usuario;
        });
    }

    /**
     * @param  array{name: string, email: string, tipo: TipoCuenta, rol_id?: int|null, sucursal_id: int|null, caja_id?: int|null, password?: string|null, activo: bool, permisos_extra?: list<string>}  $datos
     */
    public function actualizar(User $actor, User $usuario, array $datos): User
    {
        Gate::forUser($actor)->authorize('update', $usuario);

        if ($usuario->is($actor) && ($datos['tipo'] !== TipoCuenta::Administrador || ! $datos['activo'])) {
            throw ValidationException::withMessages([
                'tipo' => __('No puedes quitarte el rol de administrador ni desactivar tu propia cuenta.'),
            ]);
        }

        return DB::transaction(function () use ($actor, $usuario, $datos): User {
            if ($datos['activo'] && ! $usuario->activo) {
                $this->cupo->asegurarUsuarioNuevo((int) $usuario->empresa_id);
            }

            $this->asegurarOtroAdministrador($usuario, $datos);

            $antes = $this->resumen($usuario);
            $extrasAntes = $this->extrasDe($usuario);
            $extras = $this->aplicar($actor, $usuario, $datos);
            $cambioPassword = filled($datos['password'] ?? null);

            if ($cambioPassword) {
                $usuario->password = (string) $datos['password'];
            }

            $cambioRol = $usuario->isDirty('rol_id') || $usuario->isDirty('tipo');

            if ($usuario->isDirty()) {
                $usuario->save();
                $this->auditor->registrar('usuario.actualizado', $usuario, $actor, $usuario->empresa_id,
                    $antes, $this->resumen($usuario) + ($cambioPassword ? ['password_restablecida' => true] : []));
            }

            if ($cambioRol) {
                $this->auditor->registrar('usuario.rol_cambiado', $usuario, $actor, $usuario->empresa_id,
                    ['tipo' => $antes['tipo'], 'rol' => $antes['rol']], ['tipo' => $usuario->tipo?->value, 'rol' => $this->nombreRol($usuario)]);
            }

            if ($extras !== null && $extras !== $extrasAntes) {
                $this->escribirExtras($actor, $usuario, $extras);
                $this->auditor->registrar('usuario.permisos_extra', $usuario, $actor, $usuario->empresa_id,
                    ['permisos_extra' => $extrasAntes], ['permisos_extra' => $extras]);
            }

            $this->permisos->olvidar($usuario->id);

            return $usuario;
        });
    }

    /**
     * Cambia solo la caja de uno o varios usuarios desde Sucursales y cajas (§ 6.3), todo o nada y
     * con las reglas del formulario de usuarios: caja activa de la sucursal de cada usuario y sin un
     * turno abierto por él que siga abierto. El rol y los permisos extra no cambian.
     *
     * @param  list<int>  $vendedorIds
     * @return int usuarios cuya caja cambió
     */
    public function asignarCaja(User $actor, int $cajaId, array $vendedorIds): int
    {
        Gate::forUser($actor)->authorize('create', User::class);
        $ids = array_values(array_unique(array_map('intval', $vendedorIds)));

        return DB::transaction(function () use ($actor, $cajaId, $ids): int {
            $vendedores = User::query()
                ->where('empresa_id', $actor->empresa_id)
                ->where('tipo', TipoCuenta::Usuario)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($vendedores->count() !== count($ids)) {
                throw ValidationException::withMessages(['caja_id' => __('Solo puedes asignar cajas a usuarios de tu empresa.')]);
            }

            $movidos = 0;

            foreach ($vendedores as $vendedor) {
                Gate::forUser($actor)->authorize('update', $vendedor);

                if ((int) $vendedor->caja_id === $cajaId) {
                    continue;
                }

                $antes = $this->resumen($vendedor);
                $this->aplicar($actor, $vendedor, [
                    'name' => $vendedor->name, 'email' => $vendedor->email, 'tipo' => TipoCuenta::Usuario, 'rol_id' => $vendedor->rol_id,
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
     * Aplica los datos validados al modelo (sin guardar).
     *
     * @param  array{name: string, email: string, tipo: TipoCuenta, rol_id?: int|null, sucursal_id: int|null, caja_id?: int|null, activo: bool, permisos_extra?: list<string>}  $datos
     * @return list<string>|null permisos extra a guardar; null = no cambian
     */
    private function aplicar(User $actor, User $usuario, array $datos): ?array
    {
        if (! in_array($datos['tipo'], TipoCuenta::asignablesPorEmpresa(), true)) {
            throw ValidationException::withMessages(['tipo' => __('Selecciona un tipo de usuario válido.')]);
        }

        $sucursalId = null;
        $cajaId = null;
        $rol = null;
        $extras = [];

        if ($datos['tipo'] === TipoCuenta::Usuario) {
            $rol = $this->rolDeEmpresa($actor, $datos['rol_id'] ?? null);
            $sucursalId = $datos['sucursal_id'];
            $valida = $sucursalId !== null && Sucursal::withoutGlobalScopes()
                ->whereKey($sucursalId)
                ->where('empresa_id', $actor->empresa_id)
                ->where('activa', true)
                ->exists();

            if (! $valida) {
                throw ValidationException::withMessages([
                    'sucursal_id' => __('El usuario necesita una sucursal activa de tu empresa.'),
                ]);
            }

            $extras = array_key_exists('permisos_extra', $datos) ? $this->extrasValidos($datos['permisos_extra'], $rol) : null;
            $efectivos = Permiso::conRequeridos([...$rol->clavesPermisos(), ...Permiso::desdeClaves($extras ?? $this->extrasDe($usuario))]);
            $cajaId = $datos['caja_id'] ?? null;
            // Quien usa su caja sin supervisar cajas necesita una asignada (§ 6.3); al supervisor se le deja opcional.
            $necesitaCaja = in_array(Permiso::CajaOperar, $efectivos, true) && ! in_array(Permiso::CajaSupervisar, $efectivos, true);

            if ($cajaId !== null || $necesitaCaja) {
                $cajaValida = $cajaId !== null && Caja::withoutGlobalScopes()
                    ->whereKey($cajaId)
                    ->where('empresa_id', $actor->empresa_id)
                    ->where('sucursal_id', $sucursalId)
                    ->where('activa', true)
                    ->exists();

                if (! $cajaValida) {
                    throw ValidationException::withMessages([
                        'caja_id' => __('El usuario necesita una caja activa de su propia sucursal.'),
                    ]);
                }
            }
        }

        // Un turno abierto por el usuario queda en su caja: no se le cambia de caja o sucursal
        // hasta cerrarlo, para que nadie trabaje en una caja de otra sucursal.
        if ($usuario->exists && $usuario->esUsuario()
            && ((int) $usuario->caja_id !== (int) $cajaId || (int) $usuario->sucursal_id !== (int) $sucursalId)) {
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
        $usuario->tipo = $datos['tipo'];
        $usuario->rol_id = $rol?->id;
        // El administrador opera en todas las sucursales; el usuario, según su rol, en la asignada o en todas.
        $usuario->sucursal_id = $sucursalId;
        $usuario->caja_id = $cajaId;
        $usuario->activo = $datos['activo'];

        // El administrador ya tiene todos los permisos: no conserva extras.
        return $datos['tipo'] === TipoCuenta::Administrador ? [] : $extras;
    }

    private function rolDeEmpresa(User $actor, ?int $rolId): Rol
    {
        /** @var Rol|null $rol */
        $rol = $rolId === null ? null : Rol::withoutGlobalScopes()->where('empresa_id', $actor->empresa_id)->find($rolId);

        if ($rol === null) {
            throw ValidationException::withMessages(['rol_id' => __('Elige un rol de tu empresa.')]);
        }

        return $rol;
    }

    /**
     * Claves del catálogo con sus requeridos, sin las que ya da el rol. Una clave desconocida se rechaza.
     *
     * @param  list<string>  $claves
     * @return list<string>
     */
    private function extrasValidos(array $claves, Rol $rol): array
    {
        $claves = array_values(array_unique(array_map('strval', $claves)));
        $permisos = Permiso::desdeClaves($claves);

        if (count($permisos) !== count($claves)) {
            throw ValidationException::withMessages(['permisos_extra' => __('Hay un permiso que no existe.')]);
        }

        $delRol = $rol->clavesPermisos();

        return array_values(array_map(
            fn (Permiso $p): string => $p->value,
            array_filter(Permiso::conRequeridos($permisos), fn (Permiso $p): bool => ! in_array($p, $delRol, true)),
        ));
    }

    /**
     * La empresa no puede quedarse sin un administrador activo (§ 6.4). Se bloquean sus administradores para que
     * dos cambios simultáneos no dejen a ninguno.
     *
     * @param  array{tipo: TipoCuenta, activo: bool}  $datos
     */
    private function asegurarOtroAdministrador(User $usuario, array $datos): void
    {
        if (! $usuario->esAdministrador() || ($datos['tipo'] === TipoCuenta::Administrador && $datos['activo'])) {
            return;
        }

        $otros = User::query()
            ->where('empresa_id', $usuario->empresa_id)
            ->where('tipo', TipoCuenta::Administrador)
            ->where('activo', true)
            ->lockForUpdate()
            ->pluck('id')
            ->reject(fn (mixed $id): bool => (int) $id === $usuario->id);

        if ($otros->isEmpty()) {
            throw ValidationException::withMessages([
                'tipo' => __('La empresa necesita al menos un administrador activo.'),
            ]);
        }
    }

    /**
     * @param  list<string>|null  $extras
     */
    private function escribirExtras(User $actor, User $usuario, ?array $extras): void
    {
        if ($extras === null) {
            return;
        }

        DB::table('usuario_permisos')->where('user_id', $usuario->id)->delete();

        if ($extras !== []) {
            $ahora = Carbon::now();
            DB::table('usuario_permisos')->insert(array_map(fn (string $p): array => [
                'user_id' => $usuario->id, 'permiso' => $p, 'otorgado_por' => $actor->id, 'created_at' => $ahora,
            ], $extras));
        }

        $this->permisos->olvidar($usuario->id);
    }

    /**
     * @return list<string>
     */
    private function extrasDe(User $usuario): array
    {
        if (! $usuario->exists) {
            return [];
        }

        $guardados = DB::table('usuario_permisos')->where('user_id', $usuario->id)->pluck('permiso')->all();

        return array_values(array_map(fn (Permiso $p): string => $p->value,
            array_filter(Permiso::cases(), fn (Permiso $p): bool => in_array($p->value, $guardados, true))));
    }

    private function nombreRol(User $usuario): ?string
    {
        return $usuario->rol_id === null ? null : Rol::withoutGlobalScopes()->whereKey($usuario->rol_id)->value('nombre');
    }

    /**
     * @return array<string, mixed>
     */
    private function resumen(User $usuario): array
    {
        return [
            'name' => $usuario->name,
            'email' => $usuario->email,
            'tipo' => $usuario->tipo?->value,
            'rol' => $this->nombreRol($usuario),
            'sucursal_id' => $usuario->sucursal_id,
            'caja_id' => $usuario->caja_id,
            'activo' => $usuario->activo,
        ];
    }
}
