<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad de toda respuesta web. Una respuesta que ya define la suya (p. ej. el
 * documento aislado del Agente Mozi) la conserva. HSTS solo en producción y por HTTPS, sin
 * subdominios: el dominio raíz puede servir la landing desde otro hosting.
 */
final class SecurityHeaders
{
    private const CABECERAS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $cabeceras = self::CABECERAS;

        if ($request->isSecure() && app()->isProduction()) {
            $cabeceras['Strict-Transport-Security'] = 'max-age=31536000';
        }

        foreach ($cabeceras as $nombre => $valor) {
            if (! $response->headers->has($nombre)) {
                $response->headers->set($nombre, $valor);
            }
        }

        return $response;
    }
}
