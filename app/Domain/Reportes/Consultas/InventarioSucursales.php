<?php

namespace App\Domain\Reportes\Consultas;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Stock actual de cada producto activo en cada sucursal activa, con su mínimo efectivo.
 * Un producto sin movimientos en una sucursal figura con 0.
 */
final class InventarioSucursales
{
    public static function consulta(int $empresaId, ?int $sucursalId = null, string $buscar = '', bool $soloAlertas = false): Builder
    {
        $termino = trim($buscar);
        $patron = '%'.addcslashes($termino, '\\%_').'%';

        return DB::table('productos as p')
            ->join('sucursales as s', fn (JoinClause $j) => $j->on('s.empresa_id', '=', 'p.empresa_id')->where('s.activa', true))
            ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
            // Con empresa_id el join usa el índice único (empresa, sucursal, producto) en vez de recorrer la tabla.
            ->leftJoin('stock_niveles as sn', fn (JoinClause $j) => $j->on('sn.empresa_id', '=', 'p.empresa_id')
                ->on('sn.sucursal_id', '=', 's.id')->on('sn.producto_id', '=', 'p.id'))
            ->where('p.empresa_id', $empresaId)
            ->where('p.activo', true)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('s.id', $sucursalId))
            ->when($termino !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('p.nombre', 'like', $patron)
                ->orWhere('p.sku', 'like', $patron)
                ->orWhere('p.contenido', 'like', $patron)
                ->orWhereRaw("CONCAT_WS(' ', p.nombre, p.contenido, u.abreviatura) LIKE ?", [$patron])))
            ->when($soloAlertas, fn (Builder $q) => $q
                ->whereRaw('COALESCE(sn.stock_minimo, p.stock_minimo) > 0')
                ->whereRaw('COALESCE(sn.cantidad, 0) <= COALESCE(sn.stock_minimo, p.stock_minimo)'))
            ->orderBy('p.nombre')
            ->orderBy('s.nombre')
            ->select(['p.id',
                DB::raw("CASE WHEN p.contenido IS NOT NULL THEN CONCAT(p.nombre, ' ', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM p.contenido)), ' ', u.abreviatura) ELSE p.nombre END AS nombre"),
                'p.sku', 'u.abreviatura', 's.nombre as sucursal',
                DB::raw('COALESCE(sn.cantidad, 0) AS stock'),
                DB::raw('COALESCE(sn.stock_minimo, p.stock_minimo) AS minimo')]);
    }
}
