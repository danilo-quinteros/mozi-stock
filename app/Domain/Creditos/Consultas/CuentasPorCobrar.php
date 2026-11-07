<?php

namespace App\Domain\Creditos\Consultas;

use App\Domain\Reportes\Periodo;
use App\Models\Venta;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Vista financiera de Cuentas por cobrar (§ 6): ventas a crédito y cuentas manuales en una
 * sola lista, sin duplicar saldos (el de una venta sigue en ventas.saldo_pendiente). Con plan
 * de cuotas, el vencimiento mostrado es el de la cuota pendiente más antigua y lo vencido es la
 * suma de las cuotas vencidas. Estados (§ 9): pagada, vencida, parcial o pendiente; el filtro
 * «abiertas» (todas con saldo) lo usan Reportes y su CSV; porCliente() agrupa esos saldos por
 * cliente (§ 10). Una cuenta manual anulada solo aparece
 * en el filtro «anuladas» y no cuenta en saldos, vencidos ni clientes con saldo.
 */
final class CuentasPorCobrar
{
    public const ESTADOS = ['todas', 'pendientes', 'parciales', 'vencidas', 'pagadas', 'anuladas'];

    public static function consulta(int $empresaId, string $estado = 'todas', ?int $sucursalId = null, string $buscar = '',
        ?string $vencimientoDesde = null, ?string $vencimientoHasta = null, ?int $clienteId = null): Builder
    {
        $termino = trim($buscar);
        $patron = '%'.addcslashes($termino, '\\%_').'%';
        $numero = preg_replace('/\D/', '', $termino);
        $hoy = self::hoy();

        return DB::query()->fromSub(self::base($empresaId, $hoy), 'cxc')
            ->where('anulada', $estado === 'anuladas' ? 1 : 0)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('sucursal_id', $sucursalId))
            ->when($clienteId !== null, fn (Builder $q) => $q->where('cliente_id', $clienteId))
            ->when($vencimientoDesde !== null, fn (Builder $q) => $q->where('vencimiento', '>=', $vencimientoDesde))
            ->when($vencimientoHasta !== null, fn (Builder $q) => $q->where('vencimiento', '<=', $vencimientoHasta))
            ->when($termino !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('cliente', 'like', $patron)
                ->orWhere('documento_cliente', 'like', $patron)
                ->orWhere('concepto', 'like', $patron)
                ->when($numero !== '' && $numero !== null, fn (Builder $q) => $q->orWhere('numero', (int) $numero))))
            ->when($estado === 'pagadas', fn (Builder $q) => $q->where('saldo', '=', 0))
            ->when($estado === 'abiertas', fn (Builder $q) => $q->where('saldo', '>', 0))
            ->when($estado === 'vencidas', fn (Builder $q) => $q->where('saldo', '>', 0)->where('vencimiento', '<', $hoy))
            ->when(in_array($estado, ['pendientes', 'parciales'], true), fn (Builder $q) => $q->where('saldo', '>', 0)
                ->where(fn (Builder $q) => $q->whereNull('vencimiento')->orWhere('vencimiento', '>=', $hoy))
                ->where('cobrado', $estado === 'pendientes' ? '=' : '>', 0))
            ->orderByRaw('saldo = 0')
            ->orderByRaw('vencimiento IS NULL')
            ->orderBy('vencimiento')
            ->orderByDesc('fecha')
            ->orderByDesc('id');
    }

    /**
     * @return array{pendiente: string, vencido: string, por_vencer: string, clientes: int}
     */
    public static function resumen(int $empresaId, ?int $sucursalId = null): array
    {
        $fila = DB::query()->fromSub(self::base($empresaId, self::hoy()), 'cxc')
            ->where('anulada', 0)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('sucursal_id', $sucursalId))
            ->selectRaw('COALESCE(SUM(saldo), 0) AS pendiente')
            ->selectRaw('COALESCE(SUM(vencido), 0) AS vencido')
            ->selectRaw('COUNT(DISTINCT CASE WHEN saldo > 0 THEN cliente_id END) AS clientes')
            ->first();
        $pendiente = Decimal::redondear((string) ($fila->pendiente ?? '0'));
        $vencido = Decimal::redondear((string) ($fila->vencido ?? '0'));

        return [
            'pendiente' => $pendiente,
            'vencido' => $vencido,
            'por_vencer' => Decimal::restar($pendiente, $vencido, Decimal::ESCALA_DINERO),
            'clientes' => (int) ($fila->clientes ?? 0),
        ];
    }

    /**
     * Clientes con saldo pendiente, para elegir a quién se cobra.
     *
     * @return list<\stdClass>
     */
    public static function clientesConSaldo(int $empresaId): array
    {
        return array_values(DB::query()->fromSub(self::base($empresaId, self::hoy()), 'cxc')
            ->where('anulada', 0)
            ->where('saldo', '>', 0)
            ->whereNotNull('cliente_id')
            ->groupBy('cliente_id', 'cliente', 'documento_cliente')
            ->orderBy('cliente')
            ->get(['cliente_id as id', 'cliente as nombre', 'documento_cliente as documento', DB::raw('SUM(saldo) AS saldo')])
            ->all());
    }

    /**
     * Saldos pendientes por cliente (§ 10): una fila por cliente con sus cuentas abiertas.
     */
    public static function porCliente(int $empresaId, ?int $sucursalId = null, string $buscar = ''): Builder
    {
        $termino = trim($buscar);
        $patron = '%'.addcslashes($termino, '\\%_').'%';

        return DB::query()->fromSub(self::base($empresaId, self::hoy()), 'cxc')
            ->where('anulada', 0)
            ->where('saldo', '>', 0)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('sucursal_id', $sucursalId))
            ->when($termino !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('cliente', 'like', $patron)->orWhere('documento_cliente', 'like', $patron)))
            ->groupBy('cliente_id', 'cliente', 'documento_cliente')
            ->select(['cliente_id', 'cliente', 'documento_cliente', DB::raw('COUNT(*) AS cuentas'), DB::raw('SUM(importe) AS importe'),
                DB::raw('SUM(cobrado) AS cobrado'), DB::raw('SUM(vencido) AS vencido'), DB::raw('SUM(saldo) AS saldo')])
            ->orderByDesc('saldo')
            ->orderBy('cliente');
    }

    /**
     * @return 'anulada'|'pagada'|'vencida'|'parcial'|'pendiente'
     */
    public static function estado(\stdClass $fila): string
    {
        return match (true) {
            (bool) $fila->anulada => 'anulada',
            Decimal::comparar((string) $fila->saldo, '0', 2) <= 0 => 'pagada',
            $fila->vencimiento !== null && $fila->vencimiento < self::hoy() => 'vencida',
            Decimal::comparar((string) $fila->cobrado, '0', 2) > 0 => 'parcial',
            default => 'pendiente',
        };
    }

    public static function hoy(): string
    {
        return Carbon::now(Periodo::zona())->format('Y-m-d');
    }

    /**
     * Unión de ventas que nacieron a crédito y cuentas manuales, con columnas comunes.
     */
    private static function base(int $empresaId, string $hoy): Builder
    {
        $cuotas = DB::table('venta_cuotas')
            ->where('empresa_id', $empresaId)
            ->where('saldo', '>', 0)
            ->groupBy('venta_id')
            ->select(['venta_id', DB::raw('MIN(vencimiento) AS proximo'), DB::raw('COUNT(*) AS pendientes'),
                DB::raw('SUM(CASE WHEN vencimiento < ? THEN saldo ELSE 0 END) AS vencido')])
            ->addBinding($hoy, 'select');

        $ventas = DB::table('ventas as v')
            ->join('sucursales as s', 's.id', '=', 'v.sucursal_id')
            ->leftJoin('clientes as c', 'c.id', '=', 'v.cliente_id')
            ->leftJoinSub($cuotas, 'q', 'q.venta_id', '=', 'v.id')
            ->where('v.empresa_id', $empresaId)
            ->whereColumn('v.cobrado_inicial', '<', 'v.total')
            ->select([
                DB::raw("'venta' AS tipo"), 'v.id', 'v.numero', 'v.cliente_id', 'c.nombre as cliente', 'c.documento as documento_cliente',
                DB::raw('NULL AS concepto'), 'v.sucursal_id', 's.nombre as sucursal',
                DB::raw("DATE(CONVERT_TZ(v.fecha, '+00:00', ?)) AS fecha"),
                DB::raw('COALESCE(q.proximo, v.vencimiento) AS vencimiento'),
                DB::raw('v.total AS importe'), DB::raw(Venta::sqlCobrado('v').' AS cobrado'), DB::raw('v.saldo_pendiente AS saldo'),
                DB::raw('CASE WHEN q.venta_id IS NOT NULL THEN q.vencido WHEN v.saldo_pendiente > 0 AND v.vencimiento < ? THEN v.saldo_pendiente ELSE 0 END AS vencido'),
                DB::raw('COALESCE(q.pendientes, 0) AS cuotas_pendientes'), DB::raw('0 AS anulada'), DB::raw('NULL AS motivo_anulacion'),
            ])
            ->addBinding([Carbon::now(Periodo::zona())->format('P'), $hoy], 'select');

        $manuales = DB::table('cuentas_cobrar as cc')
            ->join('sucursales as s', 's.id', '=', 'cc.sucursal_id')
            ->join('clientes as c', 'c.id', '=', 'cc.cliente_id')
            ->where('cc.empresa_id', $empresaId)
            ->select([
                DB::raw("'cuenta' AS tipo"), 'cc.id', 'cc.numero', 'cc.cliente_id', 'c.nombre as cliente', 'c.documento as documento_cliente',
                'cc.concepto', 'cc.sucursal_id', 's.nombre as sucursal', 'cc.fecha', 'cc.vencimiento',
                DB::raw('cc.importe AS importe'), DB::raw('cc.importe - cc.saldo_pendiente AS cobrado'), DB::raw('cc.saldo_pendiente AS saldo'),
                DB::raw('CASE WHEN cc.saldo_pendiente > 0 AND cc.vencimiento < ? THEN cc.saldo_pendiente ELSE 0 END AS vencido'),
                DB::raw('0 AS cuotas_pendientes'), DB::raw('cc.anulado_at IS NOT NULL AS anulada'), 'cc.motivo_anulacion',
            ])
            ->addBinding($hoy, 'select');

        return $ventas->unionAll($manuales);
    }
}
