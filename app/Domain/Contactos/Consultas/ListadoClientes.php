<?php

namespace App\Domain\Contactos\Consultas;

use App\Domain\Reportes\Periodo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Directorio de clientes con su cantidad de ventas. Para un vendedor, el historial solo cuenta
 * ventas de su sucursal. La pantalla Clientes y su CSV usan esta consulta.
 */
final class ListadoClientes
{
    public static function consulta(int $empresaId, string $buscar = '', ?int $sucursalId = null): Builder
    {
        $termino = trim($buscar);
        $patron = '%'.addcslashes($termino, '\\%_').'%';

        return DB::table('clientes as c')
            ->where('c.empresa_id', $empresaId)
            ->when($termino !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('c.nombre', 'like', $patron)
                ->orWhere('c.documento', 'like', $patron)
                ->orWhere('c.telefono', 'like', $patron)
                ->orWhere('c.correo', 'like', $patron)))
            ->orderByDesc('c.activo')
            ->orderBy('c.nombre')
            ->orderBy('c.id')
            ->select(['c.id', 'c.nombre', 'c.documento', 'c.telefono', 'c.correo', 'c.activo'])
            ->selectSub(fn (Builder $q) => $q->from('ventas')->whereColumn('ventas.cliente_id', 'c.id')
                ->where('ventas.empresa_id', $empresaId)
                ->when($sucursalId !== null, fn (Builder $q) => $q->where('ventas.sucursal_id', $sucursalId))
                ->selectRaw('COUNT(*)'), 'ventas');
    }

    /**
     * @return array{registrados: int, activos: int, con_ventas: int}
     */
    public static function resumen(int $empresaId, Periodo $periodo, ?int $sucursalId = null): array
    {
        [$inicio, $fin] = $periodo->limitesUtc();
        $fila = DB::table('clientes')->where('empresa_id', $empresaId)
            ->selectRaw('COUNT(*) AS registrados, COALESCE(SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END), 0) AS activos')->first();

        return [
            'registrados' => (int) ($fila->registrados ?? 0),
            'activos' => (int) ($fila->activos ?? 0),
            'con_ventas' => DB::table('ventas')->where('empresa_id', $empresaId)->whereNotNull('cliente_id')
                ->where('fecha', '>=', $inicio)->where('fecha', '<', $fin)
                ->when($sucursalId !== null, fn (Builder $q) => $q->where('sucursal_id', $sucursalId))
                ->distinct()->count('cliente_id'),
        ];
    }
}
