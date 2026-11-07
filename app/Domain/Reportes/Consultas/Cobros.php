<?php

namespace App\Domain\Reportes\Consultas;

use App\Domain\Caja\Consultas\ReporteCaja;
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
    public static function resumen(int $empresaId, Periodo $periodo, ?int $sucursalId = null, string $metodo = '', ?int $cajaId = null): array
    {
        $fila = self::base($empresaId, $periodo, $sucursalId, $metodo, cajaId: $cajaId)
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
     * @return list<\stdClass>
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
     * Una fila por cobro con sus importes tomados del libro de caja: la cuota inicial y los abonos
     * antiguos (origen pago_venta), los cobros posteriores (origen cobro, aplicados a una o varias
     * ventas o cuentas manuales; un cobro anulado sigue en su día, marcado) y el dinero que vuelve
     * al anular un pago de cuenta por pagar (origen anulacion_pago_cxp, en la fecha de la anulación).
     * Cada entrada de dinero aparece una sola vez. La pantalla y el CSV usan esta consulta;
     * referencia() y concepto() describen cada fila.
     */
    public static function detalle(int $empresaId, Periodo $periodo, ?int $sucursalId = null, string $metodo = '', string $buscar = '', ?int $cajaId = null): Builder
    {
        $termino = trim($buscar);
        $patron = '%'.addcslashes($termino, '\\%_').'%';
        $numero = preg_replace('/\D/', '', $termino);
        $sumas = [
            DB::raw("SUM(CASE WHEN m.metodo = 'efectivo' THEN m.monto ELSE 0 END) AS efectivo"),
            DB::raw("SUM(CASE WHEN m.metodo = 'electronico' THEN m.monto ELSE 0 END) AS electronico"),
            DB::raw('SUM(m.monto) AS total'),
        ];

        $iniciales = self::base($empresaId, $periodo, $sucursalId, $metodo, 'm', $cajaId)
            ->where('m.sentido', 'ingreso')
            ->where('m.origen_tipo', 'pago_venta')
            ->join('pagos_venta as p', 'p.id', '=', 'm.origen_id')
            ->join('ventas as v', 'v.id', '=', 'p.venta_id')
            ->leftJoin('clientes as c', 'c.id', '=', 'v.cliente_id')
            ->join('sucursales as s', 's.id', '=', 'm.sucursal_id')
            ->leftJoin('cajas as cj', 'cj.id', '=', 'm.caja_id')
            ->join('users as u', 'u.id', '=', 'p.recibido_por')
            ->join('users as vend', 'vend.id', '=', 'v.vendedor_id')
            ->when($termino !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('c.nombre', 'like', $patron)
                ->orWhere('u.name', 'like', $patron)
                ->orWhere('vend.name', 'like', $patron)
                ->when($numero !== '' && $numero !== null, fn (Builder $q) => $q->orWhere('v.numero', (int) $numero))))
            ->groupBy('p.id', 'p.fecha_cobro', 'p.tipo', 'p.metodo', 'p.nota', 'v.id', 'v.numero', 'c.nombre', 's.nombre', 'u.name', 'vend.name')
            ->select([
                DB::raw("'pago_venta' AS origen"), 'p.id', 'p.fecha_cobro', 'p.tipo', 'p.metodo', 'p.nota', 'v.id as venta_id', 'v.numero as venta_numero',
                DB::raw('NULL AS cobro_numero'), DB::raw('1 AS ventas'), DB::raw('NULL AS cuenta_id'), DB::raw('NULL AS cuenta_numero'),
                'c.nombre as cliente', 's.nombre as sucursal', 'u.name as recibido_por', 'vend.name as vendedor',
                DB::raw('0 AS anulado'), DB::raw('NULL AS cxp_numero'), DB::raw('MAX(cj.nombre) AS caja'), ...$sumas,
            ]);

        // Venta o cuenta cubierta por cada cobro: si cubre varias se informa la primera y cuántas son.
        $destinos = DB::table('cobro_aplicaciones')
            ->where('empresa_id', $empresaId)
            ->groupBy('cobro_id')
            ->select(['cobro_id', DB::raw('MIN(venta_id) AS venta_id'), DB::raw('MIN(cuenta_cobrar_id) AS cuenta_id'),
                DB::raw('COUNT(DISTINCT venta_id) + COUNT(DISTINCT cuenta_cobrar_id) AS documentos')]);

        $posteriores = self::base($empresaId, $periodo, $sucursalId, $metodo, 'm', $cajaId)
            ->where('m.sentido', 'ingreso')
            ->where('m.origen_tipo', 'cobro')
            ->join('cobros as co', 'co.id', '=', 'm.origen_id')
            ->joinSub($destinos, 'd', 'd.cobro_id', '=', 'co.id')
            ->leftJoin('ventas as v', 'v.id', '=', 'd.venta_id')
            ->leftJoin('cuentas_cobrar as cc', 'cc.id', '=', 'd.cuenta_id')
            ->leftJoin('clientes as c', 'c.id', '=', 'co.cliente_id')
            ->join('sucursales as s', 's.id', '=', 'm.sucursal_id')
            ->leftJoin('cajas as cj', 'cj.id', '=', 'm.caja_id')
            ->join('users as u', 'u.id', '=', 'co.recibido_por')
            ->leftJoin('users as vend', 'vend.id', '=', 'v.vendedor_id')
            ->when($termino !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('c.nombre', 'like', $patron)
                ->orWhere('u.name', 'like', $patron)
                ->orWhere('vend.name', 'like', $patron)
                ->when($numero !== '' && $numero !== null, fn (Builder $q) => $q->orWhere('v.numero', (int) $numero)->orWhere('co.numero', (int) $numero))))
            ->groupBy('co.id', 'co.fecha_cobro', 'co.metodo', 'co.nota', 'co.numero', 'co.anulado_at', 'v.id', 'v.numero', 'd.documentos', 'd.cuenta_id', 'cc.numero',
                'c.nombre', 's.nombre', 'u.name', 'vend.name')
            ->select([
                DB::raw("'cobro' AS origen"), 'co.id', 'co.fecha_cobro', DB::raw("'abono' AS tipo"), 'co.metodo', 'co.nota', 'v.id as venta_id', 'v.numero as venta_numero',
                'co.numero as cobro_numero', 'd.documentos as ventas', 'd.cuenta_id', 'cc.numero as cuenta_numero',
                'c.nombre as cliente', 's.nombre as sucursal', 'u.name as recibido_por', 'vend.name as vendedor',
                DB::raw('co.anulado_at IS NOT NULL AS anulado'), DB::raw('NULL AS cxp_numero'), DB::raw('MAX(cj.nombre) AS caja'), ...$sumas,
            ]);

        $devueltos = self::base($empresaId, $periodo, $sucursalId, $metodo, 'm', $cajaId)
            ->where('m.sentido', 'ingreso')
            ->where('m.origen_tipo', 'anulacion_pago_cxp')
            ->join('pagos_cuenta_pagar as pc', 'pc.id', '=', 'm.origen_id')
            ->join('cuentas_pagar as cp', 'cp.id', '=', 'pc.cuenta_pagar_id')
            ->join('proveedores as pr', 'pr.id', '=', 'cp.proveedor_id')
            ->join('sucursales as s', 's.id', '=', 'm.sucursal_id')
            ->leftJoin('cajas as cj', 'cj.id', '=', 'm.caja_id')
            ->join('users as u', 'u.id', '=', 'm.usuario_id')
            ->when($termino !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('pr.nombre', 'like', $patron)
                ->orWhere('u.name', 'like', $patron)
                ->when($numero !== '' && $numero !== null, fn (Builder $q) => $q->orWhere('cp.numero', (int) $numero))))
            ->groupBy('pc.id', 'm.fecha_operacion', 'pc.metodo', 'pc.motivo_anulacion', 'cp.numero', 'pr.nombre', 's.nombre', 'u.name')
            ->select([
                DB::raw("'anulacion_pago_cxp' AS origen"), 'pc.id', 'm.fecha_operacion as fecha_cobro', DB::raw("'anulacion' AS tipo"), 'pc.metodo',
                'pc.motivo_anulacion as nota', DB::raw('NULL AS venta_id'), DB::raw('NULL AS venta_numero'), DB::raw('NULL AS cobro_numero'), DB::raw('0 AS ventas'),
                DB::raw('NULL AS cuenta_id'), DB::raw('NULL AS cuenta_numero'), 'pr.nombre as cliente', 's.nombre as sucursal', 'u.name as recibido_por',
                DB::raw('NULL AS vendedor'), DB::raw('0 AS anulado'), 'cp.numero as cxp_numero', DB::raw('MAX(cj.nombre) AS caja'), ...$sumas,
            ]);

        return DB::query()->fromSub($iniciales->unionAll($posteriores)->unionAll($devueltos), 'cobros_detalle')
            ->orderByDesc('fecha_cobro')
            ->orderByDesc('id');
    }

    /**
     * Documento cobrado en una fila de detalle(): la venta (V-00012), la cuenta manual
     * (CXC-00003) o, si el cobro cubrió varios documentos, el cobro (COB-00007 · 3 documentos).
     */
    public static function referencia(\stdClass $fila): string
    {
        $codigo = fn (string $serie, int|string|null $numero) => $serie.'-'.str_pad((string) $numero, 5, '0', STR_PAD_LEFT);

        return match (true) {
            $fila->origen === 'anulacion_pago_cxp' => $codigo('CXP', $fila->cxp_numero),
            (int) $fila->ventas > 1 => $codigo('COB', $fila->cobro_numero).' · '.__(':n documentos', ['n' => (int) $fila->ventas]),
            $fila->venta_numero !== null => $codigo('V', $fila->venta_numero),
            $fila->cuenta_numero !== null => $codigo('CXC', $fila->cuenta_numero),
            default => $codigo('COB', $fila->cobro_numero),
        };
    }

    public static function concepto(\stdClass $fila): string
    {
        return match (true) {
            $fila->origen === 'anulacion_pago_cxp' => __('Devolución de pago anulado'),
            $fila->tipo === 'inicial' => __('Cuota inicial'),
            (bool) $fila->anulado => __('Abono (anulado)'),
            default => __('Abono'),
        };
    }

    private static function base(int $empresaId, Periodo $periodo, ?int $sucursalId, string $metodo = '', ?string $alias = null, ?int $cajaId = null): Builder
    {
        [$inicio, $fin] = $periodo->limitesUtc();
        $col = fn (string $c) => $alias === null ? $c : "{$alias}.{$c}";

        return DB::table($alias === null ? 'movimientos_caja' : "movimientos_caja as {$alias}")
            ->where($col('empresa_id'), $empresaId)
            ->where($col('fecha_operacion'), '>=', $inicio)
            ->where($col('fecha_operacion'), '<', $fin)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where($col('sucursal_id'), $sucursalId))
            ->when($cajaId !== null, fn (Builder $q) => ReporteCaja::filtrarCaja($q, $col('caja_id'), $cajaId))
            ->when(in_array($metodo, ['efectivo', 'electronico'], true), fn (Builder $q) => $q->where($col('metodo'), $metodo));
    }
}
