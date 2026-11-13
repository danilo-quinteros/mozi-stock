<?php

namespace App\Domain\Despachos\Consultas;

use App\Enums\EstadoEntrega;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Unidades vendidas que siguen físicamente en el almacén (ESPECIFICACION_DESPACHOS.md § 5.4 y § 6.4):
 * venta_items de ventas Por despachar menos lo ya devuelto de esas líneas, por sucursal y producto.
 * El stock del sistema ya las descontó; sirve para que un conteo físico no genere ajustes falsos.
 *
 * Una sola consulta por pantalla: filtra por el índice (empresa, sucursal, estado, fecha) y resta las
 * devoluciones con una subconsulta por línea sobre el índice (empresa_id, venta_item_id) de devolucion_items.
 * Los productos sin control de stock no reservan nada: no tienen stock (ESPECIFICACION_PRODUCTOS_SIN_STOCK.md).
 */
final class ReservadoPorDespachar
{
    /**
     * @param  list<int>|null  $productoIds  null = todos los productos con reserva
     * @return array<int, array<int, string>> sucursal_id => [producto_id => cantidad]
     */
    public static function cantidades(int $empresaId, ?int $sucursalId = null, ?array $productoIds = null): array
    {
        $filas = DB::table('venta_items as vi')
            ->join('ventas as v', fn (JoinClause $j) => $j->on('v.id', '=', 'vi.venta_id')->on('v.empresa_id', '=', 'vi.empresa_id'))
            ->join('productos as p', fn (JoinClause $j) => $j->on('p.id', '=', 'vi.producto_id')->on('p.empresa_id', '=', 'vi.empresa_id'))
            ->where('v.empresa_id', $empresaId)
            ->where('p.controla_stock', true)
            ->where('v.estado_entrega', EstadoEntrega::PorDespachar->value)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('v.sucursal_id', $sucursalId))
            ->when($productoIds !== null, fn (Builder $q) => $q->whereIn('vi.producto_id', $productoIds === [] ? [0] : $productoIds))
            ->groupBy('v.sucursal_id', 'vi.producto_id')
            ->orderBy('v.sucursal_id')->orderBy('vi.producto_id')
            ->selectRaw('v.sucursal_id, vi.producto_id, SUM(vi.cantidad - COALESCE((SELECT SUM(di.cantidad) FROM devolucion_items di'
                .' WHERE di.empresa_id = vi.empresa_id AND di.venta_item_id = vi.id), 0)) AS reservado')
            ->get();

        $resultado = [];

        foreach ($filas as $fila) {
            if (Decimal::comparar((string) $fila->reservado, '0') > 0) {
                $resultado[(int) $fila->sucursal_id][(int) $fila->producto_id] = (string) $fila->reservado;
            }
        }

        return $resultado;
    }
}
