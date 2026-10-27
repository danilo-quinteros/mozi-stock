<?php

namespace App\Domain\Inventario\Consultas;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Historial único de ajustes y traspasos. Un traspaso se muestra en una sola fila
 * (origen → destino), usando la línea de salida del origen. Pantalla y CSV comparten
 * esta consulta y sus filtros.
 */
final class HistorialMovimientos
{
    /**
     * @param  array{buscar?: string, tipo?: string, sucursal_id?: int|null}  $filtros
     */
    public static function consulta(int $empresaId, array $filtros = []): Builder
    {
        $termino = trim($filtros['buscar'] ?? '');
        $patron = '%'.addcslashes($termino, '\\%_').'%';
        $tipo = $filtros['tipo'] ?? '';
        $sucursalId = $filtros['sucursal_id'] ?? null;

        return DB::table('movimientos_stock as m')
            ->join('operaciones_inventario as o', 'o.id', '=', 'm.operacion_id')
            ->join('productos as p', 'p.id', '=', 'm.producto_id')
            ->join('sucursales as s', 's.id', '=', 'o.sucursal_id')
            ->leftJoin('sucursales as d', 'd.id', '=', 'o.sucursal_destino_id')
            ->join('users as u', 'u.id', '=', 'o.usuario_id')
            ->where('m.empresa_id', $empresaId)
            ->where('o.empresa_id', $empresaId)
            // De cada traspaso se lista la línea de origen; la de destino es su par.
            ->where(fn (Builder $q) => $q->where('o.tipo', '<>', 'traspaso')->orWhere('m.cantidad', '<', 0))
            ->when($tipo !== '', fn (Builder $q) => $q->where('o.tipo', $tipo))
            ->when($sucursalId !== null, fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('m.sucursal_id', $sucursalId)
                ->orWhere('o.sucursal_destino_id', $sucursalId)))
            ->when($termino !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('p.nombre', 'like', $patron)
                ->orWhere('p.sku', 'like', $patron)
                ->orWhere('u.name', 'like', $patron)
                ->orWhere('o.nota', 'like', $patron)
                ->orWhereRaw("CONCAT(o.serie, '-', LPAD(o.numero, 5, '0')) like ?", [$patron])))
            ->orderByDesc('m.id')
            ->select([
                'm.id', 'm.created_at', 'm.cantidad', 'm.saldo_anterior', 'm.saldo_posterior',
                'o.serie', 'o.numero', 'o.tipo', 'o.motivo', 'o.nota',
                'p.nombre as producto', 'p.sku',
                's.nombre as sucursal', 'd.nombre as destino', 'u.name as usuario',
            ]);
    }
}
