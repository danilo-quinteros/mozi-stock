<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Restricciones CHECK en migraciones (MySQL 8 y MariaDB). Una base restaurada desde un respaldo puede
 * no traer un CHECK con el nombre esperado: quitarlo solo si existe evita que el despliegue se corte.
 */
final class RestriccionCheck
{
    /**
     * @param  literal-string  $tabla
     * @param  literal-string  $nombre
     */
    public static function quitar(string $tabla, string $nombre): void
    {
        $existe = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', $tabla)
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->where('CONSTRAINT_NAME', $nombre)
            ->exists();

        if ($existe) {
            DB::statement("ALTER TABLE `{$tabla}` DROP CONSTRAINT `{$nombre}`");
        }
    }
}
