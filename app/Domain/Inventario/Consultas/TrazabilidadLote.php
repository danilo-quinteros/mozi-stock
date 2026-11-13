<?php

namespace App\Domain\Inventario\Consultas;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Trazabilidad de un lote (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 4): cada movimiento del libro por lote con su
 * documento (compra y proveedor, venta y cliente, devolución, traspaso, ajuste o cambio de control) para responder
 * «¿a quién le vendí el lote X?». Una consulta con LEFT JOIN por tipo de documento, filtrada por empresa en cada tabla;
 * usa el índice movimientos_stock_lotes (empresa_id, lote_id, created_at).
 */
final class TrazabilidadLote
{
    public static function movimientos(int $empresaId, int $loteId): Builder
    {
        return DB::table('movimientos_stock_lotes as m')
            ->join('operaciones_inventario as o', 'o.id', '=', 'm.operacion_id')
            ->join('sucursales as s', 's.id', '=', 'm.sucursal_id')
            ->join('users as u', 'u.id', '=', 'o.usuario_id')
            ->leftJoin('sucursales as d', fn ($j) => $j->on('d.id', '=', 'o.sucursal_destino_id')->where('d.empresa_id', $empresaId))
            ->leftJoin('ventas as v', fn ($j) => $j->on('v.operacion_inventario_id', '=', 'o.id')->where('v.empresa_id', $empresaId))
            ->leftJoin('compras as c', fn ($j) => $j->on('c.operacion_inventario_id', '=', 'o.id')->where('c.empresa_id', $empresaId))
            ->leftJoin('proveedores as pr', fn ($j) => $j->on('pr.id', '=', 'c.proveedor_id')->where('pr.empresa_id', $empresaId))
            ->leftJoin('devoluciones as dv', fn ($j) => $j->on('dv.operacion_inventario_id', '=', 'o.id')->where('dv.empresa_id', $empresaId))
            ->leftJoin('ventas as vd', fn ($j) => $j->on('vd.id', '=', 'dv.venta_id')->where('vd.empresa_id', $empresaId))
            ->leftJoin('clientes as cl', fn ($j) => $j->on('cl.id', '=', DB::raw('COALESCE(v.cliente_id, vd.cliente_id)'))->where('cl.empresa_id', $empresaId))
            ->where('m.empresa_id', $empresaId)
            ->where('o.empresa_id', $empresaId)
            ->where('s.empresa_id', $empresaId)
            ->where('m.lote_id', $loteId)
            ->orderByDesc('m.created_at')
            ->orderByDesc('m.id')
            ->select([
                'm.id', 'm.created_at', 'm.cantidad', 'm.saldo_posterior', 's.nombre as sucursal',
                'o.serie', 'o.numero', 'o.tipo', 'o.motivo', 'o.nota', 'd.nombre as destino', 'u.name as usuario',
                'v.id as venta_id', 'c.id as compra_id', 'pr.nombre as proveedor', 'dv.venta_id as devolucion_venta_id', 'cl.nombre as cliente',
            ]);
    }

    /**
     * Stock actual del lote por sucursal (solo las que tienen).
     *
     * @return list<stdClass> filas con sucursal y cantidad
     */
    public static function stock(int $empresaId, int $loteId): array
    {
        return array_values(DB::table('stock_lotes as sl')
            ->join('sucursales as s', 's.id', '=', 'sl.sucursal_id')
            ->where('sl.empresa_id', $empresaId)
            ->where('s.empresa_id', $empresaId)
            ->where('sl.lote_id', $loteId)
            ->where('sl.cantidad', '>', 0)
            ->orderBy('s.nombre')
            ->get(['s.nombre as sucursal', 'sl.cantidad'])
            ->all());
    }
}
