<?php

namespace App\Http\Middleware;

use App\Http\Controllers\ImpersonacionController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Perfil y seguridad (correo, contraseña, 2FA, passkeys) son del dueño de la cuenta: quien
 * entra «como» otro usuario no puede cambiarlos.
 */
class EnsureNotImpersonating
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->has(ImpersonacionController::SESION)) {
            return redirect()->route('appearance.edit');
        }

        return $next($request);
    }
}
