<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Suscripción, QR y comprobantes de MoziStudios solo existen en el modo saas (§ 3.14).
 */
class EnsureSaasMode
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('mozistock.deployment_mode') === 'saas', 404);

        return $next($request);
    }
}
