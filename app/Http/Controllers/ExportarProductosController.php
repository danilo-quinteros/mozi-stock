<?php

namespace App\Http\Controllers;

use App\Domain\Catalogo\Consultas\ListadoProductos;
use App\Enums\Capacidad;
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
        $filtroStock = (string) $request->query('stock', '');
        // Tallas y colores § 5: el filtro Modelos / Sueltos solo existe con la capacidad; cada combinación sale como una
        // fila con su nombre completo.
        $tipo = (string) $request->query('tipo', '');
        $productos = ListadoProductos::consulta(
            (int) $actor->empresa_id,
            $sucursal?->id,
            (string) $request->query('q', ''),
            ctype_digit($categoria) ? (int) $categoria : null,
            in_array($filtroStock, ['con_stock', 'sin_stock'], true) ? $filtroStock : null,
            in_array($tipo, ListadoProductos::TIPOS, true) && Gate::forUser($actor)->allows('capacidad', Capacidad::Variantes) ? $tipo : null,
            (string) $request->query('marca', ''),
        )->lazy(500);

        return Csv::descargar('productos.csv',
            [__('Producto'), __('Marca'), 'SKU', __('Código de barras'), __('Categoría'), __('Unidad'), __('Consumidor'), __('Minorista'), __('Mayorista'),
                __('Sucursal'), __('Stock'), __('Mínimo'), __('Alerta'), __('Estado')],
            function () use ($productos, $sucursal): iterable {
                foreach ($productos as $producto) {
                    $alerta = ListadoProductos::alerta((string) $producto->stock, (string) $producto->minimo, $producto->controla_stock);
                    yield [
                        $producto->nombre,
                        $producto->marca ?? '',
                        $producto->sku,
                        $producto->codigo_barras ?? '',
                        $producto->categoria->nombre ?? '',
                        $producto->unidad->abreviatura,
                        $producto->precio_consumidor,
                        $producto->precio_minorista ?? '',
                        $producto->precio_mayorista ?? '',
                        $sucursal->nombre ?? '',
                        $alerta === 'sin_control' ? __('Sin control') : (string) $producto->stock,
                        $alerta === 'sin_control' ? '' : (string) $producto->minimo,
                        ['sin_control' => __('Sin control'), 'sin_stock' => __('Sin stock'), 'bajo' => __('Stock bajo'), 'normal' => __('Normal')][$alerta],
                        $producto->activo ? __('Activo') : __('Inactivo'),
                    ];
                }
            });
    }
}
