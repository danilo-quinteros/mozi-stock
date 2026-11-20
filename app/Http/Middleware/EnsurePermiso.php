<?php

namespace App\Http\Middleware;

use App\Enums\Permiso;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ruta o exportación que exige un permiso (uso: ->middleware('permiso:ventas.devolver')), ESPECIFICACION_PERMISOS.md
 * § 6.4. Sin el permiso responde 403. Una clave que no existe en el catálogo también niega: un error de
 * escritura nunca abre una ruta.
 */
class EnsurePermiso
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permisos): Response
    {
        $usuario = $request->user();
        // Varias claves: basta con una (p. ej. el historial de inventario lo ven quien ajusta o quien traspasa).
        $permite = $usuario !== null && array_filter($permisos, function (string $clave) use ($usuario): bool {
            $permiso = Permiso::tryFrom($clave);

            return $permiso !== null && $usuario->tienePermiso($permiso);
        }) !== [];

        abort_unless($permite, 403);

        return $next($request);
    }
}
