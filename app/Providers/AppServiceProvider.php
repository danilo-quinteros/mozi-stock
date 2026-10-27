<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Decisión del propietario (23/10/2026): Caja, arqueo y reportes solo para administradores.
        Gate::define('ver-reportes', fn (User $user): bool => $user->esAdministrador());

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
