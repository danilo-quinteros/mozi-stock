<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel global de MoziStudios. No existe en el modo standalone.
 */
class EnsureSuperadmin
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('mozistock.deployment_mode') === 'saas', 404);

        $user = $request->user();

        abort_unless($user instanceof User && $user->esSuperadmin(), 403);

        return $next($request);
    }
}
