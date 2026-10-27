<?php

namespace App\Http\Controllers;

use App\Domain\Inventario\Consultas\HistorialMovimientos;
use App\Enums\TipoOperacionInventario;
use App\Models\OperacionInventario;
use App\Models\User;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV del historial con los mismos filtros y la misma consulta que la pantalla.
 */
class ExportarMovimientosController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        Gate::forUser($actor)->authorize('viewAny', OperacionInventario::class);

        $tipo = (string) $request->query('tipo', '');
        $sucursal = (string) $request->query('sucursal', '');
        $filas = HistorialMovimientos::consulta((int) $actor->empresa_id, [
            'buscar' => (string) $request->query('q', ''),
            'tipo' => in_array($tipo, array_column(TipoOperacionInventario::cases(), 'value'), true) ? $tipo : '',
            'sucursal_id' => ctype_digit($sucursal) ? (int) $sucursal : null,
        ])->lazyByIdDesc(500, 'm.id', 'id');

        return Csv::descargar('movimientos-inventario.csv',
            [__('Fecha'), __('Referencia'), __('Tipo'), __('Producto'), 'SKU', __('Sucursal'), __('Destino'), __('Cantidad'), __('Saldo anterior'), __('Saldo posterior'), __('Usuario'), __('Motivo'), __('Nota')],
            function () use ($filas): iterable {
                foreach ($filas as $fila) {
                    yield [
                        Carbon::parse($fila->created_at, 'UTC')->timezone(config('mozistock.business_timezone'))->format('d/m/Y H:i'),
                        $fila->serie.'-'.str_pad((string) $fila->numero, 5, '0', STR_PAD_LEFT),
                        TipoOperacionInventario::from($fila->tipo)->etiqueta(),
                        $fila->producto,
                        $fila->sku,
                        $fila->sucursal,
                        $fila->destino ?? '',
                        (string) $fila->cantidad,
                        (string) $fila->saldo_anterior,
                        (string) $fila->saldo_posterior,
                        $fila->usuario,
                        TipoOperacionInventario::etiquetaMotivo($fila->motivo) ?? '',
                        $fila->nota ?? '',
                    ];
                }
            });
    }
}
