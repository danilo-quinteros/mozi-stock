<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Se ejecuta en cada petición web autenticada: un usuario desactivado o de una empresa
 * desactivada pierde el acceso aunque su sesión siga abierta.
 */
class EnsureAccountIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ($motivo = $user->motivoBloqueo()) !== null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                abort(403, $motivo);
            }

            return redirect()->route('login')->with('bloqueo', $motivo);
        }

        return $next($request);
    }
}
