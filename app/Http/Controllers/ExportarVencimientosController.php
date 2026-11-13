<?php

namespace App\Http\Controllers;

use App\Domain\Inventario\Consultas\ReporteVencimientos;
use App\Domain\Inventario\DatosLote;
use App\Models\OperacionInventario;
use App\Models\Producto;
use App\Models\User;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV del reporte «Vencimientos» con los mismos filtros y la misma consulta que la pantalla (lotes § 8): siempre la
 * empresa del usuario; la ruta exige la capacidad y aquí se exige la Policy del historial de inventario.
 */
class ExportarVencimientosController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        Gate::forUser($actor)->authorize('viewAny', OperacionInventario::class);

        $estado = (string) $request->query('estado', 'todos');
        $sucursal = (string) $request->query('sucursal', '');
        $filas = ReporteVencimientos::consulta((int) $actor->empresa_id, [
            'estado' => $estado,
            'sucursal_id' => ctype_digit($sucursal) ? (int) $sucursal : null,
            'buscar' => (string) $request->query('q', ''),
        ])->lazy(500);

        return Csv::descargar('vencimientos.csv',
            [__('Producto'), 'SKU', __('Lote'), __('Expiry'), __('Sucursal'), __('Stock'), __('Estado')],
            function () use ($filas): iterable {
                foreach ($filas as $fila) {
                    $lote = ReporteVencimientos::lote($fila);

                    yield [
                        Producto::formatoNombreCompleto($fila->producto, $fila->contenido, $fila->abreviatura),
                        $fila->sku ?? '',
                        $lote['regularizacion'] ? __('Sin lote (regularización)') : (string) $lote['numero'],
                        $lote['vencimiento'] === null ? '' : DatosLote::formatear($lote['vencimiento']),
                        $fila->sucursal,
                        $lote['cantidad'],
                        match (true) {
                            $lote['regularizacion'] => __('Sin fecha · por regularizar'),
                            $lote['vencido'] => __('Vencido'),
                            default => __('Vigente'),
                        },
                    ];
                }
            });
    }
}
