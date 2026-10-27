<?php

namespace App\Providers;

use App\Domain\Facturacion\RegistroLlamadasSiat;
use App\Domain\Identidad\Impersonacion;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use MoziStudios\Siat\ClienteSiat;
use MoziStudios\Siat\Configuracion\ConfiguracionSistema;
use MoziStudios\Siat\Constantes\Ambiente;
use MoziStudios\Siat\Excepciones\DatosInvalidos;
use MoziStudios\Siat\Soap\SoapTransporte;
use MoziStudios\Siat\Soap\Transporte;

/**
 * Conecta el paquete mozistudios/siat con Laravel. Todo se resuelve de forma perezosa: sin
 * configuración SIAT la aplicación arranca igual y solo fallan las acciones fiscales.
 */
class SiatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConfiguracionSistema::class, function (): ConfiguracionSistema {
            $ambiente = Ambiente::tryFrom((int) config('siat.ambiente'))
                ?? throw new DatosInvalidos('SIAT_AMBIENTE debe ser 1 (producción) o 2 (pruebas).');

            return new ConfiguracionSistema(
                (string) config('siat.codigo_sistema'),
                $ambiente,
                urlBase: (string) config('siat.url_base'),
                segundosConexion: (int) config('siat.segundos_conexion'),
                segundosRespuesta: (int) config('siat.segundos_respuesta'),
            );
        });

        $this->app->singleton(Transporte::class, function (Application $app): Transporte {
            $cacheWsdl = storage_path('framework/cache/siat-wsdl');
            File::ensureDirectoryExists($cacheWsdl);

            return new SoapTransporte($app->make(RegistroLlamadasSiat::class), $cacheWsdl);
        });

        $this->app->singleton(ClienteSiat::class, fn (Application $app): ClienteSiat => new ClienteSiat(
            $app->make(ConfiguracionSistema::class),
            $app->make(Transporte::class),
        ));
    }

    public function boot(): void
    {
        // Ver y operar la facturación (CUIS, CUFD, catálogos): el superadmin, o el administrador de la
        // empresa cuando MoziStudios la habilitó.
        Gate::define('facturacion-siat', fn (User $user, Empresa $empresa): bool => $user->esSuperadmin()
            || ($user->esAdministrador() && $user->empresa_id === $empresa->id && $empresa->siat_habilitado));

        // Cambiar datos fiscales, token, sucursales y puntos de venta: lo mismo, pero nunca durante
        // «Entrar como» (decisión D6).
        Gate::define('configurar-facturacion', fn (User $user, Empresa $empresa): bool => ! Impersonacion::activa()
            && Gate::forUser($user)->allows('facturacion-siat', $empresa));
    }
}
