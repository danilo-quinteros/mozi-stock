<?php

namespace App\Domain\AgenteMozi\Datos;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Saldos por cobrar abiertos con su propio vencimiento, en una sola consulta para toda la empresa:
 * cada cuota pendiente de un plan, el saldo de una venta a crédito sin plan y cada cuenta manual no
 * anulada. Es el mismo reparto que usa Registrar cobro (PendientesCobro), así la suma coincide con
 * Cuentas por cobrar y lo vencido se mide por cuota, no por venta.
 */
final class SaldosPorCobrar
{
    /**
     * Columnas: tipo, cliente_id, sucursal_id, documento, vencimiento (DATE o null) y saldo.
     */
    public static function items(int $empresaId): Builder
    {
        $documento = CatalogoDatos::sqlCodigo('V', 'v.numero');

        $cuotas = DB::table('venta_cuotas as q')
            ->join('ventas as v', 'v.id', '=', 'q.venta_id')
            ->where('q.empresa_id', $empresaId)
            ->where('q.saldo', '>', 0)
            ->whereColumn('v.cobrado_inicial', '<', 'v.total')
            ->select([DB::raw("'cuota' AS tipo"), 'v.cliente_id', 'v.sucursal_id', DB::raw("{$documento} AS documento"), 'q.vencimiento', 'q.saldo']);

        $ventas = DB::table('ventas as v')
            ->where('v.empresa_id', $empresaId)
            ->where('v.saldo_pendiente', '>', 0)
            ->whereColumn('v.cobrado_inicial', '<', 'v.total')
            ->whereNotExists(fn (Builder $q) => $q->select(DB::raw(1))->from('venta_cuotas as qv')->whereColumn('qv.venta_id', 'v.id'))
            ->select([DB::raw("'venta' AS tipo"), 'v.cliente_id', 'v.sucursal_id', DB::raw("{$documento} AS documento"), 'v.vencimiento', DB::raw('v.saldo_pendiente AS saldo')]);

        $cuentas = DB::table('cuentas_cobrar as cc')
            ->where('cc.empresa_id', $empresaId)
            ->whereNull('cc.anulado_at')
            ->where('cc.saldo_pendiente', '>', 0)
            ->select([DB::raw("'cuenta' AS tipo"), 'cc.cliente_id', 'cc.sucursal_id', DB::raw(CatalogoDatos::sqlCodigo('CXC', 'cc.numero').' AS documento'),
                'cc.vencimiento', DB::raw('cc.saldo_pendiente AS saldo')]);

        return DB::query()->fromSub($cuotas->unionAll($ventas)->unionAll($cuentas), 'i');
    }
}
