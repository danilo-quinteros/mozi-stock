<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rutas del panel de empresa: solo administradores y vendedores con empresa asignada.
 * El superadministrador usa su propio panel y no accede a datos comerciales de clientes.
 */
class EnsureCompanyUser
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->perteneceAEmpresa(), 403);

        return $next($request);
    }
}
