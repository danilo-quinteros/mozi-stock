<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aplica el idioma de la interfaz: preferencia guardada del usuario, luego la sesión
 * (pantalla de acceso) y por último el idioma predeterminado. Solo admite idiomas configurados.
 */
class SetLocale
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $idioma = $user instanceof User ? $user->idioma : null;
        $idioma ??= $request->hasSession() ? $request->session()->get(User::SESION_IDIOMA) : null;

        if (is_string($idioma) && array_key_exists($idioma, config('mozistock.idiomas'))) {
            App::setLocale($idioma);
        }

        return $next($request);
    }
}
