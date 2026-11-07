<?php

namespace App\Http\Controllers;

use App\Domain\Caja\Consultas\ReporteCaja;
use App\Domain\Creditos\Consultas\HistorialPagos;
use App\Domain\Reportes\Consultas\Cobros;
use App\Domain\Reportes\Consultas\Salidas;
use App\Domain\Reportes\Periodo;
use App\Models\Caja;
use App\Models\Compra;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Versiones imprimibles de crédito (con desglose de pagos), compra y detalle de caja. Usan
 * la misma autorización y las mismas consultas que sus pantallas; solo leen datos.
 */
class ImprimirController extends Controller
{
    public function credito(Request $request, Venta $venta): View
    {
        Gate::forUser($this->actor($request))->authorize('cobrar', $venta);

        $venta->load(['empresa', 'cliente:id,nombre,documento', 'sucursal:id,nombre', 'pedido:id,numero', 'vendedor:id,name', 'cuotas']);

        return view('imprimir.credito', ['venta' => $venta, 'pagos' => HistorialPagos::de($venta)]);
    }

    public function compra(Request $request, Compra $compra): View
    {
        Gate::forUser($this->actor($request))->authorize('view', $compra);

        $compra->load(['empresa', 'items', 'sucursal:id,nombre', 'proveedor:id,nombre,documento,telefono', 'usuario:id,name', 'operacion']);

        return view('imprimir.compra', ['compra' => $compra]);
    }

    public function caja(Request $request): View
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('ver-reportes');
        $empresaId = (int) $actor->empresa_id;
        $fecha = (string) $request->query('fecha', '');
        $dia = Periodo::rango((string) $request->query('desde', $fecha), $fecha);
        $valor = (string) $request->query('sucursal', '');
        $sucursal = ctype_digit($valor) ? Sucursal::query()->find((int) $valor) : null;
        $metodo = in_array($request->query('metodo'), ['efectivo', 'electronico'], true) ? (string) $request->query('metodo') : '';
        $cajaId = ReporteCaja::cajaElegida((string) $request->query('caja', ''));
        $caja = $cajaId ? Caja::query()->find($cajaId) : null;
        $cajaId = $cajaId === ReporteCaja::FUERA_DE_CAJA ? $cajaId : $caja?->id;

        return view('imprimir.caja', [
            'empresa' => $actor->empresa,
            'dia' => $dia,
            'sucursal' => $sucursal,
            'caja' => $caja,
            'fueraDeCaja' => $cajaId === ReporteCaja::FUERA_DE_CAJA,
            'metodo' => $metodo,
            'resumen' => Cobros::resumen($empresaId, $dia, $sucursal?->id, $metodo, $cajaId),
            'cobros' => Cobros::detalle($empresaId, $dia, $sucursal?->id, $metodo, cajaId: $cajaId)->get(),
            'salidas' => Salidas::detalle($empresaId, $dia, $sucursal?->id, $metodo, $cajaId)->get(),
            'filtros' => array_filter(['fecha' => $dia->hasta->format('Y-m-d'), 'desde' => $dia->esUnDia() ? null : $dia->desde->format('Y-m-d'), 'sucursal' => $sucursal?->id, 'caja' => $cajaId === ReporteCaja::FUERA_DE_CAJA ? 'externo' : $caja?->id, 'metodo' => $metodo]),
        ]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
