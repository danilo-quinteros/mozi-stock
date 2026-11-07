<?php

namespace App\Domain\Reportes\Consultas;

use App\Domain\Caja\Consultas\ReporteCaja;
use App\Domain\Reportes\Periodo;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Salidas reales de dinero desde movimientos_caja (única fuente monetaria): lo pagado al
 * registrar compras y egresos, los pagos posteriores de cuentas por pagar, los reembolsos de
 * devoluciones y el dinero devuelto al anular un cobro, cada evento una sola vez y en la fecha
 * en que salió. Una deuda pendiente no es
 * una salida. La pantalla de caja y el CSV del arqueo usan esta consulta.
 */
final class Salidas
{
    public static function detalle(int $empresaId, Periodo $periodo, ?int $sucursalId = null, string $metodo = '', ?int $cajaId = null): Builder
    {
        [$inicio, $fin] = $periodo->limitesUtc();
        $origen = fn (string $alias, string $tipo) => fn (JoinClause $join) => $join
            ->on("{$alias}.id", '=', 'm.origen_id')->where('m.origen_tipo', '=', $tipo);

        return DB::table('movimientos_caja as m')
            ->leftJoin('compras as c', $origen('c', 'compra'))
            ->leftJoin('proveedores as pr', 'pr.id', '=', 'c.proveedor_id')
            ->leftJoin('egresos as e', $origen('e', 'egreso'))
            ->leftJoin('categorias_egreso as ce', 'ce.id', '=', 'e.categoria_egreso_id')
            ->leftJoin('devoluciones as d', $origen('d', 'devolucion'))
            ->leftJoin('ventas as v', 'v.id', '=', 'd.venta_id')
            ->leftJoin('pagos_cuenta_pagar as pc', $origen('pc', 'pago_cxp'))
            ->leftJoin('cuentas_pagar as cp', 'cp.id', '=', 'pc.cuenta_pagar_id')
            ->leftJoin('proveedores as cpp', 'cpp.id', '=', 'cp.proveedor_id')
            ->leftJoin('cobros as ac', $origen('ac', 'anulacion_cobro'))
            ->leftJoin('clientes as acl', 'acl.id', '=', 'ac.cliente_id')
            ->join('sucursales as s', 's.id', '=', 'm.sucursal_id')
            ->leftJoin('cajas as cj', 'cj.id', '=', 'm.caja_id')
            ->join('users as u', 'u.id', '=', 'm.usuario_id')
            ->where('m.empresa_id', $empresaId)
            ->where('m.sentido', 'egreso')
            ->where('m.fecha_operacion', '>=', $inicio)
            ->where('m.fecha_operacion', '<', $fin)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('m.sucursal_id', $sucursalId))
            ->when($cajaId !== null, fn (Builder $q) => ReporteCaja::filtrarCaja($q, 'm.caja_id', $cajaId))
            ->when(in_array($metodo, ['efectivo', 'electronico'], true), fn (Builder $q) => $q->where('m.metodo', $metodo))
            ->groupBy('m.origen_tipo', 'm.origen_id', 'm.fecha_operacion', 'c.numero', 'pr.nombre', 'e.numero', 'e.descripcion',
                'ce.nombre', 'd.numero', 'd.motivo', 'v.id', 'v.numero', 'cp.numero', 'cp.concepto', 'cpp.nombre', 'ac.numero', 'ac.motivo_anulacion',
                'acl.nombre', 's.nombre', 'u.name')
            ->orderByDesc('m.fecha_operacion')
            ->orderByDesc('m.origen_id')
            ->select([
                'm.origen_tipo', 'm.origen_id', 'm.fecha_operacion',
                'c.numero as compra_numero', 'pr.nombre as proveedor',
                'e.numero as egreso_numero', 'e.descripcion as egreso_descripcion', 'ce.nombre as categoria',
                'd.numero as devolucion_numero', 'd.motivo as devolucion_motivo', 'v.id as venta_id', 'v.numero as venta_numero',
                'cp.numero as cuenta_numero', 'cp.concepto as cuenta_concepto', 'cpp.nombre as acreedor',
                'ac.numero as cobro_numero', 'ac.motivo_anulacion as cobro_motivo', 'acl.nombre as cobro_cliente',
                's.nombre as sucursal', 'u.name as usuario', DB::raw('MAX(cj.nombre) AS caja'),
                DB::raw("SUM(CASE WHEN m.metodo = 'efectivo' THEN m.monto ELSE 0 END) AS efectivo"),
                DB::raw("SUM(CASE WHEN m.metodo = 'electronico' THEN m.monto ELSE 0 END) AS electronico"),
                DB::raw('SUM(m.monto) AS total'),
            ]);
    }

    /**
     * Referencia (COM-00001, EGR-00001, DEV-00001, CXP-00001, COB-00001) y detalle legible de una fila de detalle().
     *
     * @return array{concepto: string, referencia: string, detalle: string}
     */
    public static function describir(\stdClass $fila): array
    {
        $codigo = fn (string $serie, int|string|null $numero) => $serie.'-'.str_pad((string) $numero, 5, '0', STR_PAD_LEFT);

        return match ($fila->origen_tipo) {
            'compra' => ['concepto' => __('Compra'), 'referencia' => $codigo('COM', $fila->compra_numero), 'detalle' => $fila->proveedor ?? __('Sin proveedor')],
            'egreso' => ['concepto' => __('Egreso'), 'referencia' => $codigo('EGR', $fila->egreso_numero), 'detalle' => $fila->categoria.' · '.$fila->egreso_descripcion],
            'pago_cxp' => ['concepto' => __('Pago de cuenta por pagar'), 'referencia' => $codigo('CXP', $fila->cuenta_numero), 'detalle' => $fila->acreedor.' · '.$fila->cuenta_concepto],
            'devolucion' => ['concepto' => __('Reembolso'), 'referencia' => $codigo('DEV', $fila->devolucion_numero), 'detalle' => __('Venta :codigo', ['codigo' => $codigo('V', $fila->venta_numero)]).' · '.$fila->devolucion_motivo],
            'anulacion_cobro' => ['concepto' => __('Cobro anulado'), 'referencia' => $codigo('COB', $fila->cobro_numero), 'detalle' => ($fila->cobro_cliente ?? __('Sin cliente')).' · '.$fila->cobro_motivo],
            default => ['concepto' => __('Salida'), 'referencia' => '', 'detalle' => ''],
        };
    }
}
