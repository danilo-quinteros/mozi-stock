<?php

namespace App\Domain\Identidad\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Identidad\PermisosUsuario;
use App\Enums\Permiso;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Crear, editar y duplicar roles de la empresa del administrador (ESPECIFICACION_PERMISOS.md § 6.5).
 *
 * - Solo el administrador (RolPolicy); la empresa siempre es la suya.
 * - Una clave que no está en el catálogo se rechaza; los requeridos se agregan aunque no lleguen.
 * - Guardar reemplaza los permisos del rol en una transacción con la fila bloqueada: un doble clic deja
 *   el mismo conjunto. Editar un rol cambia al instante los permisos de todos sus usuarios.
 */
final class GuardarRol
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly PermisosUsuario $permisos,
    ) {}

    /**
     * @param  array{nombre: string, descripcion?: string|null, permisos: list<string>}  $datos
     */
    public function crear(User $actor, array $datos): Rol
    {
        Gate::forUser($actor)->authorize('create', Rol::class);
        $permisos = $this->validar($actor, $datos, null);

        return DB::transaction(function () use ($actor, $datos, $permisos): Rol {
            $rol = new Rol;
            $rol->empresa_id = (int) $actor->empresa_id;
            $rol->nombre = trim($datos['nombre']);
            $rol->descripcion = $this->descripcion($datos);
            $rol->save();
            $this->escribirPermisos($rol, $permisos);

            $this->auditor->registrar('rol.creado', $rol, $actor, $rol->empresa_id, despues: $this->resumen($rol, $permisos));

            return $rol;
        });
    }

    /**
     * @param  array{nombre: string, descripcion?: string|null, permisos: list<string>}  $datos
     */
    public function actualizar(User $actor, Rol $rol, array $datos): Rol
    {
        Gate::forUser($actor)->authorize('update', $rol);
        $permisos = $this->validar($actor, $datos, $rol->id);

        return DB::transaction(function () use ($actor, $rol, $datos, $permisos): Rol {
            /** @var Rol $bloqueado */
            $bloqueado = Rol::query()->whereKey($rol->id)->lockForUpdate()->firstOrFail();
            $antes = $this->resumen($bloqueado, $bloqueado->clavesPermisos());

            $bloqueado->nombre = trim($datos['nombre']);
            $bloqueado->descripcion = $this->descripcion($datos);
            $bloqueado->save();
            $this->escribirPermisos($bloqueado, $permisos);
            $despues = $this->resumen($bloqueado, $permisos);

            if ($antes !== $despues) {
                $this->auditor->registrar('rol.actualizado', $bloqueado, $actor, $bloqueado->empresa_id, $antes, $despues);
            }

            return $bloqueado;
        });
    }

    /**
     * Copia con el nombre «Copia de X» (o «Copia de X (2)»…) para partir de ella.
     */
    public function duplicar(User $actor, Rol $rol): Rol
    {
        Gate::forUser($actor)->authorize('create', Rol::class);
        Gate::forUser($actor)->authorize('update', $rol);

        $base = mb_substr(__('Copia de :nombre', ['nombre' => $rol->nombre]), 0, 34);
        $nombre = $base;

        for ($n = 2; $this->nombreUsado((int) $actor->empresa_id, $nombre, null); $n++) {
            $nombre = "{$base} ({$n})";
        }

        return $this->crear($actor, [
            'nombre' => $nombre,
            'descripcion' => $rol->descripcion,
            'permisos' => array_map(fn (Permiso $p): string => $p->value, $rol->clavesPermisos()),
        ]);
    }

    /**
     * @param  array{nombre: string, descripcion?: string|null, permisos: list<string>}  $datos
     * @return list<Permiso>
     */
    private function validar(User $actor, array $datos, ?int $ignorar): array
    {
        $nombre = trim($datos['nombre']);

        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 40) {
            throw ValidationException::withMessages(['nombre' => __('El nombre del rol debe tener entre 2 y 40 caracteres.')]);
        }

        if ($this->nombreUsado((int) $actor->empresa_id, $nombre, $ignorar)) {
            throw ValidationException::withMessages(['nombre' => __('Ya existe un rol con ese nombre.')]);
        }

        if (mb_strlen((string) ($datos['descripcion'] ?? '')) > 160) {
            throw ValidationException::withMessages(['descripcion' => __('La descripción admite hasta 160 caracteres.')]);
        }

        $claves = array_values(array_unique(array_map('strval', $datos['permisos'])));
        $permisos = Permiso::desdeClaves($claves);

        if (count($permisos) !== count($claves)) {
            throw ValidationException::withMessages(['permisos' => __('Hay un permiso que no existe.')]);
        }

        return Permiso::conRequeridos($permisos);
    }

    private function nombreUsado(int $empresaId, string $nombre, ?int $ignorar): bool
    {
        return Rol::query()->where('empresa_id', $empresaId)
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])
            ->when($ignorar !== null, fn ($q) => $q->whereKeyNot($ignorar))
            ->exists();
    }

    /**
     * @param  array{descripcion?: string|null}  $datos
     */
    private function descripcion(array $datos): ?string
    {
        $texto = trim((string) ($datos['descripcion'] ?? ''));

        return $texto === '' ? null : $texto;
    }

    /**
     * @param  list<Permiso>  $permisos
     */
    private function escribirPermisos(Rol $rol, array $permisos): void
    {
        DB::table('rol_permisos')->where('rol_id', $rol->id)->delete();

        if ($permisos !== []) {
            DB::table('rol_permisos')->insert(array_map(fn (Permiso $p): array => ['rol_id' => $rol->id, 'permiso' => $p->value], $permisos));
        }

        $rol->unsetRelation('permisos');
        // Todos los usuarios del rol cambian en la acción siguiente (también en este mismo proceso).
        $this->permisos->olvidar();
    }

    /**
     * @param  list<Permiso>  $permisos
     * @return array{nombre: string, descripcion: string|null, permisos: list<string>}
     */
    private function resumen(Rol $rol, array $permisos): array
    {
        return [
            'nombre' => $rol->nombre,
            'descripcion' => $rol->descripcion,
            'permisos' => array_map(fn (Permiso $p): string => $p->value, $permisos),
        ];
    }
}
