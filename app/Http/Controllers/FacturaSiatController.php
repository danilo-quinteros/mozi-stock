<?php

namespace App\Http\Controllers;

use App\Domain\Facturacion\CatalogoEmpresa;
use App\Domain\Facturacion\DeEmpresa;
use App\Enums\EstadoFactura;
use App\Models\SiatConfiguracion;
use App\Models\SiatCufd;
use App\Models\SiatFactura;
use App\Models\SiatPuntoVenta;
use App\Models\SiatSucursal;
use App\Models\Venta;
use App\Support\CodigoQr;
use App\Support\NumeroEnLetras;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use MoziStudios\Siat\Factura\EnlaceQr;
use MoziStudios\Siat\Sincronizacion\Catalogo;

/**
 * Representación gráfica (formato oficial V3 compra-venta) y XML de una factura VALIDADA por el SIN.
 * La factura llega por route model binding con EmpresaScope: la de otra empresa da 404. Además se
 * exige poder ver la venta (VentaPolicy). Reemplaza el `urlFactura` del sistema anterior: la factura
 * se imprime desde MoziStock y el QR lleva a la consulta pública del SIN.
 */
class FacturaSiatController extends Controller
{
    public function imprimir(Request $request, SiatFactura $factura): View
    {
        $venta = $this->autorizar($request, $factura);
        $empresaId = $factura->empresa_id;
        $configuracion = DeEmpresa::consulta(SiatConfiguracion::class, $empresaId)->firstOrFail();
        $punto = DeEmpresa::consulta(SiatPuntoVenta::class, $empresaId)->findOrFail($factura->siat_punto_venta_id);
        $sucursal = DeEmpresa::consulta(SiatSucursal::class, $empresaId)->findOrFail($punto->siat_sucursal_id);
        $cufd = DeEmpresa::consulta(SiatCufd::class, $empresaId)->findOrFail($factura->siat_cufd_id);
        $rollo = $request->query('formato') === 'rollo';
        $enlace = EnlaceQr::url((string) config('siat.url_qr'), (int) $configuracion->nit, (string) $factura->cuf, (int) $factura->numero,
            $rollo ? EnlaceQr::ROLLO : EnlaceQr::MEDIA_PAGINA);
        $unidades = CatalogoEmpresa::opciones($empresaId, Catalogo::UnidadesMedida)->pluck('descripcion', 'codigo');

        return view('facturas.imprimir', [
            'factura' => $factura,
            'venta' => $venta,
            'configuracion' => $configuracion,
            'punto' => $punto,
            'sucursal' => $sucursal,
            'cufd' => $cufd,
            'rollo' => $rollo,
            'enlace' => $enlace,
            'qr' => CodigoQr::svg($enlace, $rollo ? 130 : 150),
            'enLetras' => NumeroEnLetras::bolivianos($factura->total),
            'unidades' => $unidades,
        ]);
    }

    public function xml(Request $request, SiatFactura $factura): Response
    {
        $this->autorizar($request, $factura);

        return response((string) $factura->xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="factura-'.$factura->numero.'.xml"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function autorizar(Request $request, SiatFactura $factura): Venta
    {
        $venta = Venta::query()->findOrFail($factura->venta_id);
        Gate::forUser($request->user())->authorize('view', $venta);

        abort_unless($factura->estado === EstadoFactura::Validada && $factura->xml !== null, 404);

        return $venta;
    }
}
