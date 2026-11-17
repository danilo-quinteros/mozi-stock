<?php

namespace App\Http\Controllers;

use App\Domain\Produccion\Consultas\ReporteProduccion;
use App\Domain\Reportes\Periodo;
use App\Models\OrdenProduccion;
use App\Models\User;
use App\Support\Csv;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV de los reportes de producción (ESPECIFICACION_PRODUCCION.md § 12, P4), con la misma consulta y filtros que cada
 * pestaña de la pantalla. Siempre la empresa del usuario: una sucursal de otra empresa no devuelve filas. La ruta exige
 * la capacidad y aquí se exige el gate usar-produccion. Importes con 2 decimales y costos unitarios con 4, como en la
 * pantalla; cantidades con sus 3 decimales.
 */
class ExportarReporteProduccionController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        Gate::forUser($actor)->authorize('usar-produccion');

        $empresaId = (int) $actor->empresa_id;
        $periodo = Periodo::rango((string) $request->query('desde', ''), (string) $request->query('hasta', ''));
        $valor = (string) $request->query('sucursal', '');
        $sucursalId = ctype_digit($valor) ? (int) $valor : null;
        $buscar = (string) $request->query('q', '');
        $sufijo = $periodo->desde->format('Y-m-d').'_'.$periodo->hasta->format('Y-m-d');
        $unitario = fn (?string $costo): string => $costo === null ? '' : Decimal::redondear($costo, 4);

        return match ((string) $request->query('pestana', 'productos')) {
            'ordenes' => Csv::descargar("produccion-ordenes-{$sufijo}.csv",
                [__('Orden'), __('Finalizada'), __('Producto'), __('Código'), __('Sucursal'), __('Unidad'), __('Planificado'), __('Obtenido'),
                    __('Rendimiento %'), __('Costo'), __('Costo unitario')],
                function () use ($empresaId, $periodo, $sucursalId, $buscar, $unitario): iterable {
                    foreach (ReporteProduccion::porOrden($empresaId, $periodo, $sucursalId, $buscar)->lazy(500) as $f) {
                        yield [OrdenProduccion::formatearCodigo((int) $f->numero), Carbon::parse($f->finalizada_en, 'UTC')->timezone(Periodo::zona())->format('d/m/Y H:i'),
                            $f->nombre, $f->sku ?? '', $f->sucursal, $f->unidad ?? '', (string) $f->planificada, (string) $f->obtenida,
                            ReporteProduccion::rendimiento((string) $f->planificada, (string) $f->obtenida) ?? '',
                            Decimal::redondear((string) $f->costo_total), $unitario((string) $f->costo_unitario)];
                    }
                }),
            'materias' => Csv::descargar("produccion-materias-primas-{$sufijo}.csv",
                [__('Materia prima'), __('Código'), __('Unidad'), __('Órdenes'), __('Teórico'), __('Usado'), __('Diferencia'), __('Diferencia %'), __('Costo')],
                function () use ($empresaId, $periodo, $sucursalId, $buscar): iterable {
                    foreach (ReporteProduccion::materias($empresaId, $periodo, $sucursalId, $buscar)->lazy(500) as $f) {
                        yield [$f->nombre, $f->sku ?? '', $f->unidad ?? '', (string) $f->ordenes, (string) $f->teorica, (string) $f->usada,
                            Decimal::restar((string) $f->usada, (string) $f->teorica), ReporteProduccion::desvio((string) $f->teorica, (string) $f->usada) ?? '',
                            Decimal::redondear((string) $f->costo)];
                    }
                }),
            default => Csv::descargar("produccion-productos-{$sufijo}.csv",
                [__('Producto'), __('Código'), __('Unidad'), __('Órdenes'), __('Planificado'), __('Obtenido'), __('Rendimiento %'), __('Costo'), __('Costo unitario')],
                function () use ($empresaId, $periodo, $sucursalId, $buscar, $unitario): iterable {
                    foreach (ReporteProduccion::porProducto($empresaId, $periodo, $sucursalId, $buscar)->lazy(500) as $f) {
                        yield [$f->nombre, $f->sku ?? '', $f->unidad ?? '', (string) $f->ordenes, (string) $f->planificada, (string) $f->obtenida,
                            ReporteProduccion::rendimiento((string) $f->planificada, (string) $f->obtenida) ?? '',
                            Decimal::redondear((string) $f->costo_total), $unitario(ReporteProduccion::costoUnitario((string) $f->costo_total, (string) $f->obtenida))];
                    }
                }),
        };
    }
}
