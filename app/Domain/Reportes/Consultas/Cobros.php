<?php

namespace App\Domain\Reportes\Consultas;

use App\Domain\Reportes\Periodo;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Dinero real, siempre desde movimientos_caja (única fuente monetaria): cuotas iniciales y
 * abonos en la fecha en que se recibieron; las salidas (compras, egresos y reembolsos) en la
 * fecha en que se pagaron. Nunca suma totales de ventas ni pedidos.
 */
final class Cobros
{
    /**
     * Totales del período: ingresos y egresos por medio, y neto.
     *
     * @return array{efectivo: string, electronico: string, total: string, egresos_efectivo: string, egresos_electronico: string, egresos: string, neto_efectivo: string, neto_electronico: string, neto: string}
     */
    public static function resumen(int $empresaId, Periodo $periodo, ?int $sucursalId = null, string $metodo = ''): array
    {
        $fila = self::base($empresaId, $periodo, $sucursalId, $metodo)
            ->selectRaw("COALESCE(SUM(CASE WHEN sentido = 'ingreso' AND metodo = 'efectivo' THEN monto END), 0) AS efectivo")
            ->selectRaw("COALESCE(SUM(CASE WHEN sentido = 'ingreso' AND metodo = 'electronico' THEN monto END), 0) AS electronico")
            ->selectRaw("COALESCE(SUM(CASE WHEN sentido = 'egreso' AND metodo = 'efectivo' THEN monto END), 0) AS egresos_efectivo")
            ->selectRaw("COALESCE(SUM(CASE WHEN sentido = 'egreso' AND metodo = 'electronico' THEN monto END), 0) AS egresos_electronico")
            ->first();

        $efectivo = Decimal::redondear((string) ($fila->efectivo ?? '0'));
        $electronico = Decimal::redondear((string) ($fila->electronico ?? '0'));
        $egresosEfectivo = Decimal::redondear((string) ($fila->egresos_efectivo ?? '0'));
        $egresosElectronico = Decimal::redondear((string) ($fila->egresos_electronico ?? '0'));
        $total = Decimal::sumar($efectivo, $electronico, Decimal::ESCALA_DINERO);
        $egresos = Decimal::sumar($egresosEfectivo, $egresosElectronico, Decimal::ESCALA_DINERO);

        return [
            'efectivo' => $efectivo,
            'electronico' => $electronico,
            'total' => $total,
            'egresos_efectivo' => $egresosEfectivo,
            'egresos_electronico' => $egresosElectronico,
            'egresos' => $egresos,
            'neto_efectivo' => Decimal::restar($efectivo, $egresosEfectivo, Decimal::ESCALA_DINERO),
            'neto_electronico' => Decimal::restar($electronico, $egresosElectronico, Decimal::ESCALA_DINERO),
            'neto' => Decimal::restar($total, $egresos, Decimal::ESCALA_DINERO),
        ];
    }

    /**
     * Ingresos por día de negocio: ['2026-10-20' => '50.00', ...] (solo días con cobros).
     *
     * @return array<string, string>
     */
    public static function porDia(int $empresaId, Periodo $periodo, ?int $sucursalId = null): array
    {
        return self::base($empresaId, $periodo, $sucursalId)
            ->where('sentido', 'ingreso')
            ->selectRaw("DATE(CONVERT_TZ(fecha_operacion, '+00:00', ?)) AS dia", [$periodo->desfase()])
            ->selectRaw('SUM(monto) AS total')
            ->groupBy('dia')
            ->pluck('total', 'dia')
            ->map(fn ($total) => Decimal::redondear((string) $total))
            ->all();
    }

    /**
     * Arqueo por sucursal: ingresos por medio, salidas (compras, egresos y reembolsos) y neto
     * de cada sucursal activa en el período.
     *
     * @return list<object>
     */
    public static function porSucursal(int $empresaId, Periodo $periodo): array
    {
        [$inicio, $fin] = $periodo->limitesUtc();

        return array_values(DB::table('sucursales as s')
            ->leftJoin('movimientos_caja as m', function ($join) use ($inicio, $fin): void {
                $join->on('m.sucursal_id', '=', 's.id')
                    ->on('m.empresa_id', '=', 's.empresa_id')
                    ->where('m.fecha_operacion', '>=', $inicio)
                    ->where('m.fecha_operacion', '<', $fin);
            })
            ->where('s.empresa_id', $empresaId)
            ->groupBy('s.id', 's.nombre', 's.activa')
            ->orderBy('s.nombre')
            ->get([
                's.id', 's.nombre', 's.activa',
                DB::raw("COALESCE(SUM(CASE WHEN m.sentido = 'ingreso' AND m.metodo = 'efectivo' THEN m.monto END), 0) AS efectivo"),
                DB::raw("COALESCE(SUM(CASE WHEN m.sentido = 'ingreso' AND m.metodo = 'electronico' THEN m.monto END), 0) AS electronico"),
                DB::raw("COALESCE(SUM(CASE WHEN m.sentido = 'ingreso' THEN m.monto END), 0) AS total"),
                DB::raw("COALESCE(SUM(CASE WHEN m.sentido = 'egreso' THEN m.monto END), 0) AS salidas"),
                DB::raw("COALESCE(SUM(CASE WHEN m.sentido = 'ingreso' THEN m.monto ELSE -m.monto END), 0) AS neto"),
            ])
            ->filter(fn (object $fila) => $fila->activa || Decimal::comparar((string) $fila->total, '0', 2) !== 0
                || Decimal::comparar((string) $fila->salidas, '0', 2) !== 0)
            ->all());
    }

    /**
     * Una fila por cobro (cuota inicial o abono) con sus importes tomados del libro de caja.
     * La pantalla y el CSV usan esta consulta.
     */
    public static function detalle(int $empresaId, Periodo $periodo, ?int $sucursalId = null, string $metodo = '', string $buscar = ''): Builder
    {
        $termino = trim($buscar);
        $patron = '%'.addcslashes($termino, '\\%_').'%';
        $numero = preg_replace('/\D/', '', $termino);

        return self::base($empresaId, $periodo, $sucursalId, $metodo, 'm')
            ->where('m.sentido', 'ingreso')
            ->where('m.origen_tipo', 'pago_venta')
            ->join('pagos_venta as p', 'p.id', '=', 'm.origen_id')
            ->join('ventas as v', 'v.id', '=', 'p.venta_id')
            ->leftJoin('clientes as c', 'c.id', '=', 'v.cliente_id')
            ->join('sucursales as s', 's.id', '=', 'm.sucursal_id')
            ->join('users as u', 'u.id', '=', 'p.recibido_por')
            ->join('users as vend', 'vend.id', '=', 'v.vendedor_id')
            ->when($termino !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('c.nombre', 'like', $patron)
                ->orWhere('u.name', 'like', $patron)
                ->orWhere('vend.name', 'like', $patron)
                ->when($numero !== '' && $numero !== null, fn (Builder $q) => $q->orWhere('v.numero', (int) $numero))))
            ->groupBy('p.id', 'p.fecha_cobro', 'p.tipo', 'p.metodo', 'p.nota', 'v.id', 'v.numero', 'c.nombre', 's.nombre', 'u.name', 'vend.name')
            ->orderByDesc('p.fecha_cobro')
            ->orderByDesc('p.id')
            ->select([
                'p.id', 'p.fecha_cobro', 'p.tipo', 'p.metodo', 'p.nota', 'v.id as venta_id', 'v.numero as venta_numero',
                'c.nombre as cliente', 's.nombre as sucursal', 'u.name as recibido_por', 'vend.name as vendedor',
                DB::raw("SUM(CASE WHEN m.metodo = 'efectivo' THEN m.monto ELSE 0 END) AS efectivo"),
                DB::raw("SUM(CASE WHEN m.metodo = 'electronico' THEN m.monto ELSE 0 END) AS electronico"),
                DB::raw('SUM(m.monto) AS total'),
            ]);
    }

    private static function base(int $empresaId, Periodo $periodo, ?int $sucursalId, string $metodo = '', ?string $alias = null): Builder
    {
        [$inicio, $fin] = $periodo->limitesUtc();
        $col = fn (string $c) => $alias === null ? $c : "{$alias}.{$c}";

        return DB::table($alias === null ? 'movimientos_caja' : "movimientos_caja as {$alias}")
            ->where($col('empresa_id'), $empresaId)
            ->where($col('fecha_operacion'), '>=', $inicio)
            ->where($col('fecha_operacion'), '<', $fin)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where($col('sucursal_id'), $sucursalId))
            ->when(in_array($metodo, ['efectivo', 'electronico'], true), fn (Builder $q) => $q->where($col('metodo'), $metodo));
    }
}
