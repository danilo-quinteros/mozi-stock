<?php

namespace App\Domain\Compras\Consultas;

use App\Domain\Reportes\Periodo;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Historial de compras pagadas con proveedor, sucursal, cantidad de artículos y total. La
 * pantalla Compras, su CSV y la ficha del proveedor usan esta consulta.
 */
final class ListadoCompras
{
    public static function consulta(int $empresaId, string $buscar = '', ?int $sucursalId = null, ?int $proveedorId = null): Builder
    {
        $termino = trim($buscar);
        $patron = '%'.addcslashes($termino, '\\%_').'%';
        $numero = preg_replace('/\D/', '', $termino);

        return DB::table('compras as c')
            ->join('sucursales as s', 's.id', '=', 'c.sucursal_id')
            ->join('users as u', 'u.id', '=', 'c.usuario_id')
            ->leftJoin('proveedores as pr', 'pr.id', '=', 'c.proveedor_id')
            ->where('c.empresa_id', $empresaId)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('c.sucursal_id', $sucursalId))
            ->when($proveedorId !== null, fn (Builder $q) => $q->where('c.proveedor_id', $proveedorId))
            ->when($termino !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('pr.nombre', 'like', $patron)
                ->orWhere('c.referencia', 'like', $patron)
                ->orWhereExists(fn (Builder $q) => $q->from('compra_items as ci')
                    ->whereColumn('ci.compra_id', 'c.id')
                    ->where('ci.empresa_id', $empresaId)
                    ->where(fn (Builder $q) => $q->where('ci.descripcion', 'like', $patron)->orWhere('ci.sku', 'like', $patron)))
                ->when($numero !== '' && $numero !== null, fn (Builder $q) => $q->orWhere('c.numero', (int) $numero))))
            ->orderByDesc('c.fecha')
            ->orderByDesc('c.id')
            ->select(['c.id', 'c.numero', 'c.fecha', 'c.total', 'c.metodo', 'c.monto_efectivo', 'c.monto_electronico', 'c.referencia',
                'pr.nombre as proveedor', 's.nombre as sucursal', 'u.name as usuario'])
            ->selectSub(fn (Builder $q) => $q->from('compra_items')->whereColumn('compra_items.compra_id', 'c.id')->selectRaw('COUNT(*)'), 'articulos');
    }

    /**
     * Total pagado en compras del período y cantidad de compras registradas.
     *
     * @return array{total: string, cantidad: int}
     */
    public static function totales(int $empresaId, Periodo $periodo): array
    {
        [$inicio, $fin] = $periodo->limitesUtc();
        $fila = DB::table('compras')->where('empresa_id', $empresaId)
            ->where('fecha', '>=', $inicio)->where('fecha', '<', $fin)
            ->selectRaw('COALESCE(SUM(total), 0) AS total, COUNT(*) AS cantidad')->first();

        return ['total' => Decimal::redondear((string) ($fila->total ?? '0')), 'cantidad' => (int) ($fila->cantidad ?? 0)];
    }
}
