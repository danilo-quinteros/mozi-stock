<?php

namespace App\Providers;

use App\Domain\AgenteMozi\AccesoPorPlan;
use App\Domain\AgenteMozi\Contratos\Respondedor;
use App\Domain\AgenteMozi\Demo\RespondedorDemo;
use App\Domain\AgenteMozi\Ia\GestorProveedores;
use App\Domain\AgenteMozi\Ia\Orquestador;
use App\Domain\Tenancy\CapacidadesEmpresa;
use App\Enums\Capacidad;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Agente Mozi: el motor activo (demo sintético u orquestador con proveedor de IA) se resuelve en
        // cada uso según los ajustes del superadministrador; la pantalla, la persistencia y el Lienzo no cambian.
        $this->app->bind(Respondedor::class, fn (Application $app) => $app->make(GestorProveedores::class)->motor() === GestorProveedores::DEMO
            ? $app->make(RespondedorDemo::class)
            : $app->make(Orquestador::class));

        // Capacidades por empresa: una consulta por petición; la cola lo descarta entre jobs.
        $this->app->scoped(CapacidadesEmpresa::class);
        $this->app->scoped(AccesoPorPlan::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Exportaciones CSV (§ 6: límite en operaciones sensibles): recorren tablas enteras de la empresa,
        // así que se limitan por usuario. Las subidas de archivos ya pasan por el límite de Livewire (60/min).
        RateLimiter::for('exportaciones', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('exportaciones|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Decisión del propietario (23/10/2026): Caja, arqueo y reportes solo para administradores.
        Gate::define('ver-reportes', fn (User $user): bool => $user->esAdministrador());

        // Funciones por rubro (variantes, lotes, despachos, cocina): rutas, Livewire, exports y menú.
        // Siempre la empresa del usuario autenticado; el superadmin no opera módulos de una empresa.
        Gate::define('capacidad', function (User $user, Capacidad|string $capacidad): bool {
            $capacidad = $capacidad instanceof Capacidad ? $capacidad : Capacidad::tryFrom($capacidad);

            return $capacidad !== null && $user->perteneceAEmpresa() && $user->empresa_id !== null
                && app(CapacidadesEmpresa::class)->tiene($user->empresa_id, $capacidad);
        });

        // Agente Mozi: usuarios de empresa mientras la bandera del servidor esté activa y, en SaaS, solo si
        // el plan de su empresa lo incluye. Cada herramienta vuelve a autorizar con su propio permiso
        // (p. ej. ver-reportes para costos, márgenes y deudas).
        Gate::define('usar-agente-mozi', fn (User $user): bool => $user->perteneceAEmpresa() && $user->empresa_id !== null
            && (bool) config('agente_mozi.habilitado')
            && app(AccesoPorPlan::class)->permite($user->empresa_id));

        // Motor, modelo y cuotas de Agente Mozi: solo el superadministrador.
        Gate::define('configurar-agente-mozi', fn (User $user): bool => $user->esSuperadmin());

        // SEO del sitio web público (mozistock.com): solo el superadministrador.
        Gate::define('administrar-sitio-web', fn (User $user): bool => $user->esSuperadmin());

        // «Entrar como»: superadmin a cualquier cuenta de empresa (solo SaaS); administrador a
        // los vendedores de su empresa. Nunca a uno mismo ni a cuentas bloqueadas.
        Gate::define('impersonar', function (User $user, User $objetivo): bool {
            if ($objetivo->is($user) || ! $objetivo->perteneceAEmpresa() || $objetivo->motivoBloqueo() !== null) {
                return false;
            }

            return ($user->esSuperadmin() && config('mozistock.deployment_mode') === 'saas')
                || ($user->esAdministrador() && $objetivo->esVendedor() && $objetivo->empresa_id === $user->empresa_id);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
