<?php

namespace App\Http\Controllers;

use App\Domain\Catalogo\Consultas\ListadoProductos;
use App\Domain\Creditos\Consultas\CuentasPorCobrar;
use App\Domain\Reportes\Consultas\Cobros;
use App\Domain\Reportes\Consultas\PanelInicio;
use App\Domain\Reportes\Consultas\VentasPeriodo;
use App\Domain\Reportes\Periodo;
use App\Domain\Tenancy\GuiaConfiguracion;
use App\Enums\EstadoPagoSaas;
use App\Models\PagoSaas;
use App\Models\Sucursal;
use App\Models\Suscripcion;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Inicio de la empresa. Mientras el administrador no termina la guía de configuración solo se
 * muestra la guía. Después, un panel corto por rol (index.html): el administrador ve su empresa
 * o la sucursal fijada en el menú; el vendedor, su propia actividad y su sucursal asignada.
 */
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

        // Se calcula completa (no solo pendiente()) para que al terminar el último paso el panel aparezca en la misma visita.
        if (GuiaConfiguracion::para($user) !== null) {
            return view('dashboard', ['empresa' => $user->empresa, 'guiaPendiente' => true]);
        }

        // Aviso de vencimiento en el panel (§ 8.5), solo para administradores y en modo saas.
        $suscripcion = $user->esAdministrador() && config('mozistock.deployment_mode') === 'saas' ? Suscripcion::query()->first() : null;

        return view('dashboard', [
            'empresa' => $user->empresa,
            'guiaPendiente' => false,
            'suscripcion' => $suscripcion,
            'comprobanteEnRevision' => $suscripcion !== null && PagoSaas::query()->where('estado', EstadoPagoSaas::Pendiente)->exists(),
            'panel' => $user->esVendedor() ? $this->vendedor($user) : $this->administrador($user),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function administrador(User $user): array
    {
        $empresaId = (int) $user->empresa_id;
        $sucursalId = $user->sucursalActivaId();
        $hoy = Periodo::hoy();

        return [
            'sucursal' => $sucursalId === null ? null : Sucursal::query()->whereKey($sucursalId)->value('nombre'),
            'cobros' => Cobros::resumen($empresaId, $hoy, $sucursalId),
            'ventas' => VentasPeriodo::totales($empresaId, $hoy, $sucursalId),
            'porCobrar' => CuentasPorCobrar::resumen($empresaId, $sucursalId),
            'alertas' => ListadoProductos::contarAlertas($empresaId, $sucursalId),
            'serie' => PanelInicio::cobrosPorDia($empresaId, $sucursalId),
            'ultimas' => PanelInicio::ultimasVentas($empresaId, $sucursalId),
            'stockBajo' => PanelInicio::stockBajo($empresaId, $sucursalId),
            'masVendidos' => PanelInicio::masVendidos($empresaId, $sucursalId),
        ];
    }

    /**
     * El vendedor siempre tiene sucursal (users_vendedor_sucursal_check).
     *
     * @return array<string, mixed>
     */
    private function vendedor(User $user): array
    {
        $empresaId = (int) $user->empresa_id;
        $sucursalId = (int) $user->sucursal_id;

        return [
            'sucursal' => $user->sucursal?->nombre,
            'actividad' => PanelInicio::actividadDeHoy($empresaId, $sucursalId, $user->id),
            'pedidos' => PanelInicio::pedidosPendientes($empresaId, $sucursalId),
            'alertas' => ListadoProductos::contarAlertas($empresaId, $sucursalId),
            'serie' => PanelInicio::ventasPorDia($empresaId, $sucursalId, $user->id),
            'ultimas' => PanelInicio::ultimasVentas($empresaId, $sucursalId, $user->id),
            'stockBajo' => PanelInicio::stockBajo($empresaId, $sucursalId),
            'masVendidos' => PanelInicio::masVendidos($empresaId, $sucursalId),
        ];
    }
}
