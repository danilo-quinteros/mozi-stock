<?php

namespace App\Domain\Identidad;

use App\Domain\Tenancy\CapacidadesEmpresa;
use App\Enums\Capacidad;
use App\Enums\Permiso;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Permisos efectivos de un usuario de empresa (ESPECIFICACION_PERMISOS.md § 6.1):
 *
 * - Administrador: todos los del catálogo cuya capacidad esté activa.
 * - Usuario: permisos de su rol ∪ extras ∪ requeridos, sin los de capacidades apagadas.
 * - Superadmin o cuenta sin empresa: ninguno.
 *
 * Se leen con UNA consulta por usuario y petición (UNION de rol_permisos y usuario_permisos) y se guardan
 * en memoria solo durante esa petición (binding scoped): quitar un permiso surte efecto en la acción
 * siguiente. Las acciones que cambian roles o extras llaman a olvidar() para el mismo proceso.
 */
final class PermisosUsuario
{
    /** @var array<int, array<string, true>> */
    private array $asignados = [];

    public function __construct(private readonly CapacidadesEmpresa $capacidades) {}

    public function tiene(User $usuario, Permiso $permiso): bool
    {
        if (! $usuario->perteneceAEmpresa() || ! $this->capacidadActiva((int) $usuario->empresa_id, $permiso)) {
            return false;
        }

        return $usuario->esAdministrador() || isset($this->asignados($usuario)[$permiso->value]);
    }

    /**
     * @return list<Permiso>
     */
    public function efectivos(User $usuario): array
    {
        return array_values(array_filter(Permiso::cases(), fn (Permiso $p): bool => $this->tiene($usuario, $p)));
    }

    /**
     * Olvida lo leído de un usuario, o de todos (al editar un rol).
     */
    public function olvidar(?int $usuarioId = null): void
    {
        if ($usuarioId === null) {
            $this->asignados = [];
        } else {
            unset($this->asignados[$usuarioId]);
        }
    }

    /**
     * @return array<string, true>
     */
    private function asignados(User $usuario): array
    {
        return $this->asignados[$usuario->id] ??= $this->cargar($usuario);
    }

    /**
     * @return array<string, true>
     */
    private function cargar(User $usuario): array
    {
        $extras = DB::table('usuario_permisos')->where('user_id', $usuario->id)->select('permiso');
        $claves = $usuario->rol_id === null
            ? $extras->pluck('permiso')
            : DB::table('rol_permisos')->where('rol_id', $usuario->rol_id)->select('permiso')->union($extras)->pluck('permiso');

        $permisos = Permiso::conRequeridos(Permiso::desdeClaves($claves));

        return array_fill_keys(array_map(fn (Permiso $p): string => $p->value, $permisos), true);
    }

    private function capacidadActiva(int $empresaId, Permiso $permiso): bool
    {
        $requeridas = $permiso->capacidades();

        return $requeridas === []
            || array_filter($requeridas, fn (Capacidad $c): bool => $this->capacidades->tiene($empresaId, $c)) !== [];
    }
}
