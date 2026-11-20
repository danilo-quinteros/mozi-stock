<?php

namespace App\Domain\Identidad\Actions;

use App\Enums\Permiso;
use App\Models\Rol;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Roles por defecto de una empresa (ESPECIFICACION_PERMISOS.md § 5): Vendedor y Encargado de sucursal.
 * Idempotente: la clave única (empresa_id, plantilla) impide duplicarlos y un rol ya creado no se toca,
 * aunque la empresa lo haya renombrado o editado. Escribe sin el scope de empresa porque se llama al dar
 * de alta la empresa, cuando nadie de ella está autenticado.
 */
final class CrearRolesPorDefecto
{
    /**
     * @return int id del rol Vendedor de la empresa
     */
    public function ejecutar(int $empresaId): int
    {
        $this->crear($empresaId, 'Vendedor', Rol::PLANTILLA_VENDEDOR, Permiso::deVendedor());
        $this->crear($empresaId, 'Encargado de sucursal', Rol::PLANTILLA_ENCARGADO, Permiso::deEncargado());

        return (int) DB::table('roles')->where('empresa_id', $empresaId)->where('plantilla', Rol::PLANTILLA_VENDEDOR)->value('id');
    }

    /**
     * @param  list<Permiso>  $permisos
     */
    private function crear(int $empresaId, string $nombre, string $plantilla, array $permisos): void
    {
        if (DB::table('roles')->where('empresa_id', $empresaId)->where('plantilla', $plantilla)->exists()) {
            return;
        }

        $ahora = Carbon::now();
        $insertadas = DB::table('roles')->insertOrIgnore([
            'empresa_id' => $empresaId, 'nombre' => $nombre, 'descripcion' => null, 'plantilla' => $plantilla,
            'created_at' => $ahora, 'updated_at' => $ahora,
        ]);

        // 0 filas: otra petición lo creó a la vez o la empresa ya usa ese nombre en otro rol.
        if ($insertadas === 0) {
            return;
        }

        $rolId = (int) DB::table('roles')->where('empresa_id', $empresaId)->where('plantilla', $plantilla)->value('id');
        DB::table('rol_permisos')->insertOrIgnore(array_map(
            fn (Permiso $p): array => ['rol_id' => $rolId, 'permiso' => $p->value],
            Permiso::conRequeridos($permisos),
        ));
    }
}
