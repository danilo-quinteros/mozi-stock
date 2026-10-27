<?php

namespace App\Domain\Reportes\Consultas;

use App\Domain\Reportes\Periodo;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Salidas reales de dinero desde movimientos_caja (única fuente monetaria): compras pagadas,
 * egresos operativos y reembolsos de devoluciones, cada evento una sola vez y en la fecha en
 * que se pagó. La pantalla de caja y el CSV del arqueo usan esta consulta.
 */
final class Salidas
{
    public static function detalle(int $empresaId, Periodo $periodo, ?int $sucursalId = null, string $metodo = ''): Builder
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
            ->join('sucursales as s', 's.id', '=', 'm.sucursal_id')
            ->join('users as u', 'u.id', '=', 'm.usuario_id')
            ->where('m.empresa_id', $empresaId)
            ->where('m.sentido', 'egreso')
            ->where('m.fecha_operacion', '>=', $inicio)
            ->where('m.fecha_operacion', '<', $fin)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('m.sucursal_id', $sucursalId))
            ->when(in_array($metodo, ['efectivo', 'electronico'], true), fn (Builder $q) => $q->where('m.metodo', $metodo))
            ->groupBy('m.origen_tipo', 'm.origen_id', 'm.fecha_operacion', 'c.numero', 'pr.nombre', 'e.numero', 'e.descripcion',
                'ce.nombre', 'd.numero', 'd.motivo', 'v.id', 'v.numero', 's.nombre', 'u.name')
            ->orderByDesc('m.fecha_operacion')
            ->orderByDesc('m.origen_id')
            ->select([
                'm.origen_tipo', 'm.origen_id', 'm.fecha_operacion',
                'c.numero as compra_numero', 'pr.nombre as proveedor',
                'e.numero as egreso_numero', 'e.descripcion as egreso_descripcion', 'ce.nombre as categoria',
                'd.numero as devolucion_numero', 'd.motivo as devolucion_motivo', 'v.id as venta_id', 'v.numero as venta_numero',
                's.nombre as sucursal', 'u.name as usuario',
                DB::raw("SUM(CASE WHEN m.metodo = 'efectivo' THEN m.monto ELSE 0 END) AS efectivo"),
                DB::raw("SUM(CASE WHEN m.metodo = 'electronico' THEN m.monto ELSE 0 END) AS electronico"),
                DB::raw('SUM(m.monto) AS total'),
            ]);
    }

    /**
     * Referencia (COM-00001, EGR-00001, DEV-00001) y detalle legible de una fila de detalle().
     *
     * @return array{concepto: string, referencia: string, detalle: string}
     */
    public static function describir(\stdClass $fila): array
    {
        $codigo = fn (string $serie, int|string|null $numero) => $serie.'-'.str_pad((string) $numero, 5, '0', STR_PAD_LEFT);

        return match ($fila->origen_tipo) {
            'compra' => ['concepto' => __('Compra'), 'referencia' => $codigo('COM', $fila->compra_numero), 'detalle' => $fila->proveedor ?? __('Sin proveedor')],
            'egreso' => ['concepto' => __('Egreso'), 'referencia' => $codigo('EGR', $fila->egreso_numero), 'detalle' => $fila->categoria.' · '.$fila->egreso_descripcion],
            'devolucion' => ['concepto' => __('Reembolso'), 'referencia' => $codigo('DEV', $fila->devolucion_numero), 'detalle' => __('Venta :codigo', ['codigo' => $codigo('V', $fila->venta_numero)]).' · '.$fila->devolucion_motivo],
            default => ['concepto' => __('Salida'), 'referencia' => '', 'detalle' => ''],
        };
    }
}
