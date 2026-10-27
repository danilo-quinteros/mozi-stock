<?php

namespace App\Http\Controllers;

use App\Domain\Ventas\Consultas\ListadoPedidos;
use App\Models\Pedido;
use App\Models\User;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV de Pedidos y ventas con la misma consulta y filtros de la pantalla. «Total» es valor
 * comercial; «Cobrado» es dinero recibido. Nunca se mezclan.
 */
class ExportarPedidosController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        Gate::forUser($actor)->authorize('viewAny', Pedido::class);

        $pestana = (string) $request->query('pestana', 'todos');
        $pedidos = ListadoPedidos::consulta(
            $actor,
            in_array($pestana, ListadoPedidos::PESTANAS, true) ? $pestana : 'todos',
            (string) $request->query('q', ''),
            (string) $request->query('estado', ''),
        )->lazyByIdDesc(500, 'pedidos.id', 'id');

        return Csv::descargar('pedidos-y-ventas.csv',
            [__('Pedido'), __('Venta'), __('Fecha'), __('Cliente'), __('Vendedor'), __('Estado'), __('Total vendido'), __('Cobrado'), __('Saldo pendiente')],
            function () use ($pedidos): iterable {
                foreach ($pedidos as $pedido) {
                    $venta = $pedido->venta;
                    yield [
                        $pedido->codigo(),
                        $venta?->codigo() ?? '',
                        $pedido->created_at?->timezone(config('mozistock.business_timezone'))->format('d/m/Y') ?? '',
                        $pedido->cliente->nombre ?? __('Consumidor final'),
                        $pedido->vendedor->name,
                        $pedido->estado->etiqueta(),
                        $pedido->total,
                        $venta === null ? '' : $venta->cobrado(),
                        $venta->saldo_pendiente ?? '',
                    ];
                }
            });
    }
}
