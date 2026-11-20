<?php

namespace App\Providers;

use App\Domain\AgenteMozi\AccesoPorPlan;
use App\Domain\AgenteMozi\Contratos\Respondedor;
use App\Domain\AgenteMozi\Demo\RespondedorDemo;
use App\Domain\AgenteMozi\Ia\GestorProveedores;
use App\Domain\AgenteMozi\Ia\Orquestador;
use App\Domain\Identidad\PermisosUsuario;
use App\Domain\Tenancy\CapacidadesEmpresa;
use App\Enums\Capacidad;
use App\Enums\Permiso;
use App\Http\Middleware\EnsurePermiso;
use App\Livewire\Hooks\ErroresValidacion;
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
use Livewire\Livewire;

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
        // Permisos efectivos (ESPECIFICACION_PERMISOS.md § 6.1): una consulta por usuario y petición.
        $this->app->scoped(PermisosUsuario::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Aviso rojo de validación también para errores cuya clave no es una propiedad del componente (p. ej. `lineas`).
        ErroresValidacion::registrar();

        // Las actualizaciones Livewire de una página con permiso:<clave> vuelven a pasar por el middleware: quitar el
        // permiso surte efecto en la acción siguiente aunque el componente siga abierto (ESPECIFICACION_PERMISOS.md § 6.1).
        Livewire::addPersistentMiddleware([EnsurePermiso::class]);

        // Exportaciones CSV (§ 6: límite en operaciones sensibles): recorren tablas enteras de la empresa,
        // así que se limitan por usuario. Las subidas de archivos ya pasan por el límite de Livewire (60/min).
        RateLimiter::for('exportaciones', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('exportaciones|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Permisos de la empresa (ESPECIFICACION_PERMISOS.md): @can('permiso', Permiso::VentasDevolver) en Blade.
        Gate::define('permiso', fn (User $user, Permiso|string $permiso): bool => $user->tienePermiso($permiso));

        // Reportes generales, sus CSV y el panel de Inicio de administración, acotados al alcance (P7).
        Gate::define('ver-reportes', fn (User $user): bool => $user->tienePermiso(Permiso::ReportesVer));

        // Agente Mozi: herramientas de análisis de toda la empresa (cobranza, flujo, gastos, comparativas). Exigen
        // reportes.ver y todas las sucursales porque calculan sobre la empresa completa (§ 6.7).
        Gate::define('analizar-empresa', fn (User $user): bool => $user->tienePermiso(Permiso::ReportesVer) && $user->veTodasLasSucursales());

        // Agente Mozi, UC-27: vencimientos y trazabilidad de lotes de toda la empresa (con valor a costo).
        Gate::define('analizar-lotes-empresa', fn (User $user): bool => Gate::forUser($user)->allows('analizar-lotes')
            && Gate::forUser($user)->allows('analizar-empresa'));

        // Caja y arqueo: elegir cualquier caja de su alcance y ver o cerrar turnos ajenos (§ 6.3).
        Gate::define('supervisar-cajas', fn (User $user): bool => $user->supervisaCajas());

        // Funciones por rubro (variantes, lotes, despachos, cocina): rutas, Livewire, exports y menú.
        // Siempre la empresa del usuario autenticado; el superadmin no opera módulos de una empresa.
        Gate::define('capacidad', function (User $user, Capacidad|string $capacidad): bool {
            $capacidad = $capacidad instanceof Capacidad ? $capacidad : Capacidad::tryFrom($capacidad);

            return $capacidad !== null && $user->perteneceAEmpresa() && $user->empresa_id !== null
                && app(CapacidadesEmpresa::class)->tiene($user->empresa_id, $capacidad);
        });

        // Producción (ESPECIFICACION_PRODUCCION.md): el permiso, que solo cuenta con la capacidad activa.
        Gate::define('usar-produccion', fn (User $user): bool => $user->tienePermiso(Permiso::ProduccionGestionar));

        // Vencimientos, trazabilidad y análisis de lotes (también UC-27 de Agente Mozi): el permiso con la capacidad Lotes.
        Gate::define('analizar-lotes', fn (User $user): bool => $user->tienePermiso(Permiso::InventarioVencimientos));

        // Agente Mozi: usuarios de empresa mientras la bandera del servidor esté activa y, en SaaS, solo si
        // el plan de su empresa lo incluye. Cada herramienta vuelve a autorizar con su propio permiso
        // (p. ej. ver-reportes para costos, márgenes y deudas).
        Gate::define('usar-agente-mozi', fn (User $user): bool => $user->perteneceAEmpresa() && $user->empresa_id !== null
            && (bool) config('agente_mozi.habilitado')
            && app(AccesoPorPlan::class)->permite($user->empresa_id));

        // Agente Mozi fuera del plan (solo SaaS): el administrador lo sigue viendo en el menú y, al abrirlo, recibe el
        // aviso para cambiar de plan. No da acceso a nada del agente; los vendedores no lo ven.
        Gate::define('ofrecer-agente-mozi', fn (User $user): bool => $user->esAdministrador() && $user->empresa_id !== null
            && config('mozistock.deployment_mode') === 'saas'
            && (bool) config('agente_mozi.habilitado')
            && ! app(AccesoPorPlan::class)->permite($user->empresa_id));

        // Motor, modelo y cuotas de Agente Mozi: solo el superadministrador.
        Gate::define('configurar-agente-mozi', fn (User $user): bool => $user->esSuperadmin());

        // SEO del sitio web público (mozistock.com): solo el superadministrador.
        Gate::define('administrar-sitio-web', fn (User $user): bool => $user->esSuperadmin());

        // «Entrar como»: superadmin a cualquier cuenta de empresa (solo SaaS); administrador a los usuarios con rol
        // de su empresa, nunca a otro administrador (§ 6.4). Nunca a uno mismo ni a cuentas bloqueadas.
        Gate::define('impersonar', function (User $user, User $objetivo): bool {
            if ($objetivo->is($user) || ! $objetivo->perteneceAEmpresa() || $objetivo->motivoBloqueo() !== null) {
                return false;
            }

            return ($user->esSuperadmin() && config('mozistock.deployment_mode') === 'saas')
                || ($user->esAdministrador() && $objetivo->esUsuario() && $objetivo->empresa_id === $user->empresa_id);
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
