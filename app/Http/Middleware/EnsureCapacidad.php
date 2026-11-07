<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ruta de un módulo por rubro (uso: ->middleware('capacidad:despachos')). Con la capacidad apagada
 * responde 404, como si el módulo no existiera, aunque alguien llame al endpoint directamente.
 */
class EnsureCapacidad
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $capacidad): Response
    {
        abort_unless($request->user() !== null && Gate::forUser($request->user())->allows('capacidad', $capacidad), 404);

        return $next($request);
    }
}
