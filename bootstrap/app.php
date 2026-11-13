<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureCapacidad;
use App\Http\Middleware\EnsureCompanyUser;
use App\Http\Middleware\EnsureNotImpersonating;
use App\Http\Middleware\EnsureSaasMode;
use App\Http\Middleware\EnsureSuperadmin;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SepararDominios;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cada petición web (incluido Livewire) revalida usuario y empresa activos.
        // El idioma de la interfaz sale de la preferencia del usuario o de la sesión.
        $middleware->web(append: [EnsureAccountIsActive::class, SetLocale::class, SecurityHeaders::class]);
        // Global (antes del enrutado y de auth): la página va al dominio principal y el sistema al de APP_URL.
        $middleware->append(SepararDominios::class);
        $middleware->alias([
            'empresa' => EnsureCompanyUser::class,
            'superadmin' => EnsureSuperadmin::class,
            'saas' => EnsureSaasMode::class,
            'sin-impersonar' => EnsureNotImpersonating::class,
            'capacidad' => EnsureCapacidad::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
