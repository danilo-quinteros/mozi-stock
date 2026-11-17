<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Collation explícita para expresiones de texto armadas en SQL (CONCAT, CONCAT_WS, LPAD…) que se comparan con LIKE.
 *
 * Al unir columnas o números, MySQL puede dejar el resultado como utf8mb4_bin sin collation definida (NONE) y el
 * LIKE contra el texto buscado (collation de la conexión) falla con «Illegal mix of collations». Pasó en producción
 * y la MariaDB local no lo reproduce. Un COLLATE explícito siempre gana, sin importar qué columna lo provoque.
 */
final class CollationConexion
{
    /**
     * « COLLATE utf8mb4_unicode_ci» (la de la conexión) en MySQL/MariaDB; vacío en otros motores.
     */
    public static function sufijo(?string $conexion = null): string
    {
        $db = DB::connection($conexion);

        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return '';
        }

        $collation = (string) $db->getConfig('collation');

        return preg_match('/^[A-Za-z0-9_]+$/', $collation) === 1 ? ' COLLATE '.$collation : '';
    }
}
