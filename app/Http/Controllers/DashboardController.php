<?php

namespace App\Http\Controllers;

use App\Domain\Catalogo\Consultas\ListadoProductos;
use App\Domain\Reportes\Consultas\Cobros;
use App\Domain\Reportes\Consultas\VentasPeriodo;
use App\Domain\Reportes\Periodo;
use App\Domain\Tenancy\GuiaConfiguracion;
use App\Enums\EstadoPagoSaas;
use App\Enums\Rol;
use App\Models\PagoSaas;
use App\Models\Sucursal;
use App\Models\Suscripcion;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->esSuperadmin() && config('mozistock.deployment_mode') === 'saas') {
            return redirect()->route('admin.panel');
        }

        if (! $user->perteneceAEmpresa()) {
            return view('sin-empresa');
        }

        $resumen = null;
        // Aviso de vencimiento en el panel (§ 8.5), solo para administradores y en modo saas.
        $suscripcion = $user->esAdministrador() && config('mozistock.deployment_mode') === 'saas' ? Suscripcion::query()->first() : null;

        if ($user->esAdministrador()) {
            $hoy = Periodo::hoy();
            $resumen = [
                'cobrado_hoy' => Cobros::resumen((int) $user->empresa_id, $hoy)['total'],
                'vendido_hoy' => VentasPeriodo::totales((int) $user->empresa_id, $hoy)['valor_vendido'],
                'sucursales' => Sucursal::query()->where('activa', true)->count(),
                'usuarios' => User::query()->where('empresa_id', $user->empresa_id)->where('activo', true)->count(),
                'vendedores' => User::query()->where('empresa_id', $user->empresa_id)
                    ->where('rol', Rol::Vendedor)->where('activo', true)->count(),
            ];
        }

        return view('dashboard', [
            'empresa' => $user->empresa,
            'sucursal' => $user->sucursal,
            'resumen' => $resumen,
            // Guía de configuración inicial (componente Inicio\Guia): solo administradores de una empresa que aún no la termina.
            // Se calcula completa (no solo pendiente()) para que el encabezado cambie en la misma visita en que se termina.
            'guiaPendiente' => GuiaConfiguracion::para($user) !== null,
            'suscripcion' => $suscripcion,
            'comprobanteEnRevision' => $suscripcion !== null && PagoSaas::query()->where('estado', EstadoPagoSaas::Pendiente)->exists(),
            // Vendedor: solo su sucursal; administrador: la fijada en el menú lateral o todas.
            'alertasStock' => ListadoProductos::contarAlertas((int) $user->empresa_id, $user->sucursalActivaId()),
        ]);
    }
}
