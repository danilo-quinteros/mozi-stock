<?php

namespace App\Domain\Contactos\Consultas;

use App\Domain\Reportes\Periodo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Directorio de proveedores con su cantidad de compras. La pantalla Proveedores y su CSV usan
 * esta consulta.
 */
final class ListadoProveedores
{
    public static function consulta(int $empresaId, string $buscar = ''): Builder
    {
        $termino = trim($buscar);
        $patron = '%'.addcslashes($termino, '\\%_').'%';

        return DB::table('proveedores as p')
            ->where('p.empresa_id', $empresaId)
            ->when($termino !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('p.nombre', 'like', $patron)
                ->orWhere('p.documento', 'like', $patron)
                ->orWhere('p.telefono', 'like', $patron)
                ->orWhere('p.ciudad', 'like', $patron)
                ->orWhere('p.correo', 'like', $patron)))
            ->orderByDesc('p.activo')
            ->orderBy('p.nombre')
            ->orderBy('p.id')
            ->select(['p.id', 'p.nombre', 'p.documento', 'p.telefono', 'p.correo', 'p.ciudad', 'p.activo'])
            ->selectSub(fn (Builder $q) => $q->from('compras')->whereColumn('compras.proveedor_id', 'p.id')
                ->where('compras.empresa_id', $empresaId)->selectRaw('COUNT(*)'), 'compras');
    }

    /**
     * @return array{registrados: int, activos: int, con_compras: int}
     */
    public static function resumen(int $empresaId, Periodo $periodo): array
    {
        [$inicio, $fin] = $periodo->limitesUtc();
        $fila = DB::table('proveedores')->where('empresa_id', $empresaId)
            ->selectRaw('COUNT(*) AS registrados, COALESCE(SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END), 0) AS activos')->first();

        return [
            'registrados' => (int) ($fila->registrados ?? 0),
            'activos' => (int) ($fila->activos ?? 0),
            'con_compras' => DB::table('compras')->where('empresa_id', $empresaId)->whereNotNull('proveedor_id')
                ->where('fecha', '>=', $inicio)->where('fecha', '<', $fin)->distinct()->count('proveedor_id'),
        ];
    }
}
