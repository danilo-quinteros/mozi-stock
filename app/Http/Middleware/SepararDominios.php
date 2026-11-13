<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Página pública en el dominio principal (MOZISTOCK_DOMINIO_SITIO, p. ej. mozistock.com) y sistema en
 * el host de APP_URL (p. ej. app.mozistock.com), servidos por la misma aplicación. Una página del
 * sistema pedida en el dominio principal se redirige al subdominio, y una del sitio pedida en el
 * subdominio vuelve al principal; la raíz del subdominio abre el panel. Sin MOZISTOCK_DOMINIO_SITIO
 * (localhost) no hace nada. Livewire, idioma, robots e imágenes del sitio funcionan en ambos.
 */
final class SepararDominios
{
    private const NEUTRALES = ['idioma', 'sitio.robots', 'sitio.imagen'];

    public function handle(Request $request, Closure $next): Response
    {
        $sitio = config('mozistock.dominio_sitio');
        $sistema = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($sitio) || $sitio === '' || ! is_string($sistema) || strcasecmp($sitio, $sistema) === 0
            || ! $request->isMethod('GET')) {
            return $next($request);
        }

        $host = $request->getHost();
        // Middleware global: la ruta aún no está resuelta, se busca aquí (una 404 sigue su curso).
        try {
            $nombre = app('router')->getRoutes()->match($request)->getName();
        } catch (HttpExceptionInterface) {
            return $next($request);
        }

        if (strcasecmp($host, $sistema) === 0 && $nombre === 'home') {
            return redirect()->to($request->getScheme().'://'.$sistema.'/dashboard');
        }

        $destino = match (true) {
            strcasecmp($host, $sitio) === 0 && self::esSistema($nombre) => $sistema,
            strcasecmp($host, $sistema) === 0 && self::esSitio($nombre) => $sitio,
            default => null,
        };

        return $destino === null
            ? $next($request)
            : redirect()->to($request->getScheme().'://'.$destino.$request->getRequestUri());
    }

    private static function neutral(?string $nombre): bool
    {
        return $nombre === null || in_array($nombre, self::NEUTRALES, true) || str_starts_with($nombre, 'livewire.');
    }

    private static function esSitio(?string $nombre): bool
    {
        return ! self::neutral($nombre) && ($nombre === 'home' || str_starts_with((string) $nombre, 'sitio.'));
    }

    private static function esSistema(?string $nombre): bool
    {
        return ! self::neutral($nombre) && ! self::esSitio($nombre);
    }
}
