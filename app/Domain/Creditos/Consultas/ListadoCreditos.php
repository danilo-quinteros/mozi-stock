<?php

namespace App\Domain\Creditos\Consultas;

use App\Models\Venta;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Ventas que nacieron a crédito (cobro inicial menor que el total), con lo cobrado y el saldo
 * actuales. «Cobrado» = cuota inicial más abonos registrados (Venta::sqlCobrado), que no
 * cambia si una devolución reduce el saldo.
 * La pantalla Créditos, su CSV y el reporte de créditos usan esta consulta.
 */
final class ListadoCreditos
{
    public const ESTADOS = ['pendientes', 'pagados', 'todos'];

    public static function consulta(int $empresaId, string $estado = 'pendientes', ?int $sucursalId = null, string $buscar = ''): Builder
    {
        $termino = trim($buscar);
        $patron = '%'.addcslashes($termino, '\\%_').'%';
        $numero = preg_replace('/\D/', '', $termino);

        return DB::table('ventas as v')
            ->join('sucursales as s', 's.id', '=', 'v.sucursal_id')
            ->join('users as vend', 'vend.id', '=', 'v.vendedor_id')
            ->leftJoin('clientes as c', 'c.id', '=', 'v.cliente_id')
            ->join('pedidos as p', 'p.id', '=', 'v.pedido_id')
            ->where('v.empresa_id', $empresaId)
            ->whereColumn('v.cobrado_inicial', '<', 'v.total')
            ->when($estado === 'pendientes', fn (Builder $q) => $q->where('v.saldo_pendiente', '>', 0))
            ->when($estado === 'pagados', fn (Builder $q) => $q->where('v.saldo_pendiente', '=', 0))
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('v.sucursal_id', $sucursalId))
            ->when($termino !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('c.nombre', 'like', $patron)
                ->orWhere('c.documento', 'like', $patron)
                ->when($numero !== '' && $numero !== null, fn (Builder $q) => $q->orWhere('v.numero', (int) $numero)->orWhere('p.numero', (int) $numero))))
            ->orderByDesc('v.saldo_pendiente')
            ->orderByDesc('v.id')
            ->select(['v.id', 'v.numero', 'v.fecha', 'v.total', 'v.saldo_pendiente', 'v.devuelto', 'p.numero as pedido_numero',
                's.nombre as sucursal', 'vend.name as vendedor', 'c.nombre as cliente', 'c.documento',
                DB::raw(Venta::sqlCobrado('v').' AS cobrado')]);
    }

    /**
     * @return array{saldo: string, cobrado: string, abiertos: int}
     */
    public static function resumen(int $empresaId, ?int $sucursalId = null): array
    {
        $fila = self::consulta($empresaId, 'todos', $sucursalId)->reorder()->select([
            DB::raw('COALESCE(SUM(v.saldo_pendiente), 0) AS saldo'),
            DB::raw('COALESCE(SUM('.Venta::sqlCobrado('v').'), 0) AS cobrado'),
            DB::raw('COALESCE(SUM(CASE WHEN v.saldo_pendiente > 0 THEN 1 ELSE 0 END), 0) AS abiertos'),
        ])->first();

        return [
            'saldo' => Decimal::redondear((string) ($fila->saldo ?? '0')),
            'cobrado' => Decimal::redondear((string) ($fila->cobrado ?? '0')),
            'abiertos' => (int) ($fila->abiertos ?? 0),
        ];
    }
}
