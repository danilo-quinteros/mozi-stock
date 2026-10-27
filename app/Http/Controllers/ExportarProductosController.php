<?php

namespace App\Http\Controllers;

use App\Domain\Catalogo\Consultas\ListadoProductos;
use App\Models\Producto;
use App\Models\User;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV del catálogo con el stock de una sucursal: misma consulta y filtros que la pantalla.
 * El vendedor exporta siempre el stock de su sucursal asignada.
 */
class ExportarProductosController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        Gate::forUser($actor)->authorize('viewAny', Producto::class);

        $sucursal = ListadoProductos::sucursalParaVista($actor, (string) $request->query('sucursal', ''));
        $categoria = (string) $request->query('categoria', '');
        $productos = ListadoProductos::consulta(
            (int) $actor->empresa_id,
            $sucursal?->id,
            (string) $request->query('q', ''),
            ctype_digit($categoria) ? (int) $categoria : null,
        )->lazy(500);

        return Csv::descargar('productos.csv',
            [__('Producto'), 'SKU', __('Código de barras'), __('Categoría'), __('Unidad'), __('Consumidor'), __('Minorista'), __('Mayorista'),
                __('Sucursal'), __('Stock'), __('Mínimo'), __('Alerta'), __('Estado')],
            function () use ($productos, $sucursal): iterable {
                foreach ($productos as $producto) {
                    $alerta = ListadoProductos::alerta((string) $producto->stock, (string) $producto->minimo);
                    yield [
                        $producto->nombre,
                        $producto->sku,
                        $producto->codigo_barras ?? '',
                        $producto->categoria->nombre ?? '',
                        $producto->unidad->abreviatura,
                        $producto->precio_consumidor,
                        $producto->precio_minorista ?? '',
                        $producto->precio_mayorista ?? '',
                        $sucursal->nombre ?? '',
                        (string) $producto->stock,
                        (string) $producto->minimo,
                        ['sin_stock' => __('Sin stock'), 'bajo' => __('Stock bajo'), 'normal' => __('Normal')][$alerta],
                        $producto->activo ? __('Activo') : __('Inactivo'),
                    ];
                }
            });
    }
}
