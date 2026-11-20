<?php

/*
 * ESPECIFICACION_PERMISOS.md § 10: esVendedor() desaparece y esAdministrador() solo queda en lo exclusivo del
 * administrador (§ 4.3). Un chequeo nuevo de rol en otro archivo debe usar tienePermiso(), veTodasLasSucursales()
 * o supervisaCajas(); si de verdad es exclusivo del administrador, se agrega aquí con su motivo.
 */

/**
 * @return list<string> rutas relativas a la raíz de la app, con barras normales
 */
function archivosConTexto(string $texto): array
{
    $raiz = dirname(__DIR__, 2);
    $encontrados = [];

    foreach (['app', 'resources/views'] as $carpeta) {
        $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz.'/'.$carpeta, FilesystemIterator::SKIP_DOTS));

        foreach ($iterador as $archivo) {
            if ($archivo->isFile() && $archivo->getExtension() === 'php' && str_contains((string) file_get_contents($archivo->getPathname()), $texto)) {
                $encontrados[] = str_replace('\\', '/', substr($archivo->getPathname(), strlen($raiz) + 1));
            }
        }
    }

    sort($encontrados);

    return $encontrados;
}

test('esVendedor() ya no existe en el código', function () {
    expect(archivosConTexto('esVendedor()'))->toBe([])
        ->and(archivosConTexto('puede_despachar'))->toBe([])
        ->and(file_exists(dirname(__DIR__, 2).'/app/Enums/Rol.php'))->toBeFalse();
});

test('esAdministrador() solo queda en funciones exclusivas del administrador', function () {
    $permitidos = [
        'app/Domain/Identidad/Actions/GuardarUsuarioEmpresa.php', // último administrador activo (§ 6.4)
        'app/Domain/Identidad/PermisosUsuario.php', // el administrador tiene todos los permisos (§ 6.1)
        'app/Domain/Migracion/Cyclone/MigrarCyclone.php', // migración de datos
        'app/Domain/Tenancy/GuiaConfiguracion.php', // guía de configuración inicial
        'app/Http/Controllers/DashboardController.php', // aviso de suscripción en Inicio
        'app/Http/Controllers/QrCobroController.php', // QR de cobro de MoziStudios
        'app/Livewire/Inicio/Guia.php', // guía de configuración inicial
        'app/Models/User.php', // definición y alcance
        'app/Policies/CajaPolicy.php', // gestionar sucursales y cajas
        'app/Policies/EmpresaPolicy.php', // datos y funciones de la empresa
        'app/Policies/PagoSaasPolicy.php', // pagos SaaS
        'app/Policies/RolPolicy.php', // roles
        'app/Policies/SucursalPolicy.php', // sucursales
        'app/Policies/SuscripcionPolicy.php', // suscripción
        'app/Policies/UserPolicy.php', // usuarios y permisos extra
        'app/Providers/AppServiceProvider.php', // impersonar y ofrecer Agente Mozi del plan
        'app/Providers/SiatServiceProvider.php', // facturación electrónica
        'resources/views/components/empresa-nav.blade.php', // pestañas Empresa, Mi suscripción y Facturación
        'resources/views/components/navigation.blade.php', // menú: Usuarios, Sucursales y configuración
    ];

    expect(array_values(array_diff(archivosConTexto('esAdministrador()'), $permitidos)))->toBe([]);
});
