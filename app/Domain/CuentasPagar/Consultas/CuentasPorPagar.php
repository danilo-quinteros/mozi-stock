<?php

namespace App\Domain\CuentasPagar\Consultas;

use App\Domain\Creditos\Consultas\CuentasPorCobrar;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Listado de Cuentas por pagar (§ 15): obligaciones de compras, egresos y manuales con lo
 * pagado (incluido lo pagado en el documento de origen) y el saldo. Estados (§ 19): pagada,
 * vencida, parcial o pendiente; una cuenta pagada nunca figura como vencida. Una obligación
 * anulada solo aparece en el filtro «anuladas» y no cuenta en saldos ni vencidos.
 */
final class CuentasPorPagar
{
    public const ESTADOS = ['todas', 'pendientes', 'parciales', 'vencidas', 'pagadas', 'anuladas'];

    public static function consulta(int $empresaId, string $estado = 'todas', ?int $sucursalId = null, ?int $proveedorId = null,
        string $origen = '', string $buscar = '', ?string $vencimientoDesde = null, ?string $vencimientoHasta = null): Builder
    {
        $termino = trim($buscar);
        $patron = '%'.addcslashes($termino, '\\%_').'%';
        $numero = preg_replace('/\D/', '', $termino);
        $hoy = CuentasPorCobrar::hoy();

        return DB::table('cuentas_pagar as cp')
            ->join('proveedores as p', 'p.id', '=', 'cp.proveedor_id')
            ->join('sucursales as s', 's.id', '=', 'cp.sucursal_id')
            ->leftJoin('compras as co', 'co.id', '=', 'cp.compra_id')
            ->leftJoin('egresos as e', 'e.id', '=', 'cp.egreso_id')
            ->where('cp.empresa_id', $empresaId)
            ->when($estado === 'anuladas', fn (Builder $q) => $q->whereNotNull('cp.anulado_at'), fn (Builder $q) => $q->whereNull('cp.anulado_at'))
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('cp.sucursal_id', $sucursalId))
            ->when($proveedorId !== null, fn (Builder $q) => $q->where('cp.proveedor_id', $proveedorId))
            ->when(in_array($origen, ['compra', 'egreso', 'manual'], true), fn (Builder $q) => $q->where('cp.origen', $origen))
            ->when($vencimientoDesde !== null, fn (Builder $q) => $q->where('cp.vencimiento', '>=', $vencimientoDesde))
            ->when($vencimientoHasta !== null, fn (Builder $q) => $q->where('cp.vencimiento', '<=', $vencimientoHasta))
            ->when($termino !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('p.nombre', 'like', $patron)
                ->orWhere('cp.concepto', 'like', $patron)
                ->when($numero !== '' && $numero !== null, fn (Builder $q) => $q->orWhere('cp.numero', (int) $numero)
                    ->orWhere('co.numero', (int) $numero)->orWhere('e.numero', (int) $numero))))
            ->when($estado === 'pagadas', fn (Builder $q) => $q->where('cp.saldo_pendiente', '=', 0))
            ->when($estado === 'vencidas', fn (Builder $q) => $q->where('cp.saldo_pendiente', '>', 0)->where('cp.vencimiento', '<', $hoy))
            ->when(in_array($estado, ['pendientes', 'parciales'], true), fn (Builder $q) => $q->where('cp.saldo_pendiente', '>', 0)
                ->where('cp.vencimiento', '>=', $hoy)
                ->whereRaw('cp.total_documento - cp.saldo_pendiente '.($estado === 'pendientes' ? '=' : '>').' 0'))
            ->orderByRaw('cp.saldo_pendiente = 0')
            ->orderBy('cp.vencimiento')
            ->orderByDesc('cp.id')
            ->select(['cp.id', 'cp.numero', 'cp.origen', 'cp.concepto', 'cp.fecha', 'cp.vencimiento', 'cp.total_documento',
                'cp.pagado_origen', 'cp.saldo_pendiente', 'cp.compra_id', 'cp.egreso_id', 'cp.anulado_at', 'cp.motivo_anulacion', 'co.numero as compra_numero', 'e.numero as egreso_numero',
                'p.nombre as proveedor', 's.nombre as sucursal', DB::raw('cp.total_documento - cp.saldo_pendiente AS pagado')]);
    }

    /**
     * @return array{pendiente: string, vencido: string, por_vencer: string, abiertas: int}
     */
    public static function resumen(int $empresaId, ?int $sucursalId = null): array
    {
        $fila = DB::table('cuentas_pagar')
            ->where('empresa_id', $empresaId)
            ->whereNull('anulado_at')
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('sucursal_id', $sucursalId))
            ->selectRaw('COALESCE(SUM(saldo_pendiente), 0) AS pendiente')
            ->selectRaw('COALESCE(SUM(CASE WHEN saldo_pendiente > 0 AND vencimiento < ? THEN saldo_pendiente ELSE 0 END), 0) AS vencido', [CuentasPorCobrar::hoy()])
            ->selectRaw('COALESCE(SUM(CASE WHEN saldo_pendiente > 0 THEN 1 ELSE 0 END), 0) AS abiertas')
            ->first();
        $pendiente = Decimal::redondear((string) ($fila->pendiente ?? '0'));
        $vencido = Decimal::redondear((string) ($fila->vencido ?? '0'));

        return [
            'pendiente' => $pendiente,
            'vencido' => $vencido,
            'por_vencer' => Decimal::restar($pendiente, $vencido, Decimal::ESCALA_DINERO),
            'abiertas' => (int) ($fila->abiertas ?? 0),
        ];
    }

    /**
     * Documento de origen legible: COM-00001, EGR-00001 o «Manual».
     */
    public static function documento(\stdClass $fila): string
    {
        $codigo = fn (string $serie, int|string|null $numero) => $serie.'-'.str_pad((string) $numero, 5, '0', STR_PAD_LEFT);

        return match ($fila->origen) {
            'compra' => $codigo('COM', $fila->compra_numero),
            'egreso' => $codigo('EGR', $fila->egreso_numero),
            default => __('Manual'),
        };
    }
}
