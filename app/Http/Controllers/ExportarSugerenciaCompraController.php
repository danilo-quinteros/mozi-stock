<?php

namespace App\Http\Controllers;

use App\Domain\Catalogo\Consultas\ListadoProductos;
use App\Domain\Produccion\Consultas\ListadoMateriasPrimas;
use App\Models\User;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV de la sugerencia de compra de materias primas (ESPECIFICACION_PRODUCCION.md § 4.3), con la misma consulta que la
 * pantalla: siempre la empresa del usuario; la ruta exige la capacidad y aquí se exige el gate usar-produccion. La
 * sucursal se resuelve como en la pantalla (una de la empresa, activa).
 */
class ExportarSugerenciaCompraController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        Gate::forUser($actor)->authorize('usar-produccion');

        $sucursal = ListadoProductos::sucursalParaVista($actor, (string) $request->query('sucursal', ''));
        $filas = ListadoMateriasPrimas::consulta((int) $actor->empresa_id, $sucursal?->id, '', 'sugerencia')->lazy(500);

        return Csv::descargar('sugerencia-compra.csv',
            [__('Código'), __('Materia prima'), __('Unidad'), __('Sucursal'), __('Stock'), __('Mínimo'), __('Máximo'), __('Comprar')],
            function () use ($filas, $sucursal): iterable {
                foreach ($filas as $fila) {
                    $maximo = $fila->stock_maximo === null ? null : (string) $fila->stock_maximo;

                    yield [
                        $fila->sku ?? '',
                        $fila->nombre,
                        $fila->unidad->abreviatura,
                        $sucursal->nombre ?? '',
                        (string) $fila->stock,
                        (string) $fila->minimo,
                        $maximo ?? '',
                        ListadoMateriasPrimas::sugerido((string) $fila->stock, (string) $fila->minimo, $maximo) ?? '0',
                    ];
                }
            });
    }
}
