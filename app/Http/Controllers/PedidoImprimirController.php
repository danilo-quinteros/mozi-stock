<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Cotización o nota de venta imprimible (o guardable como PDF desde el navegador). No es factura ni
 * comprobante fiscal y no afecta inventario ni caja.
 */
class PedidoImprimirController extends Controller
{
    public function __invoke(Request $request, Pedido $pedido): View
    {
        Gate::forUser($request->user())->authorize('view', $pedido);

        $pedido->load(['items.producto.unidad', 'cliente', 'sucursal', 'vendedor', 'empresa', 'venta:id,pedido_id,numero,nombre_mesa']);

        return view('pedidos.imprimir', [
            'pedido' => $pedido,
            'rollo' => $request->query('formato') === 'rollo',
        ]);
    }
}
