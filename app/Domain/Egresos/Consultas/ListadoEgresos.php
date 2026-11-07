<?php

namespace App\Domain\Egresos\Consultas;

use App\Domain\Reportes\Periodo;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Egresos operativos con categoría, sucursal, medio, condición y saldo de su cuenta por pagar.
 * La pantalla Egresos y su CSV usan esta consulta. Las compras no figuran aquí: tienen su
 * propio historial. El total es el gasto registrado; lo pagado está en caja y reportes.
 */
final class ListadoEgresos
{
    public static function consulta(int $empresaId, string $buscar = '', ?int $sucursalId = null, ?int $categoriaId = null): Builder
    {
        $termino = trim($buscar);
        $patron = '%'.addcslashes($termino, '\\%_').'%';
        $numero = preg_replace('/\D/', '', $termino);

        return DB::table('egresos as e')
            ->join('categorias_egreso as ce', 'ce.id', '=', 'e.categoria_egreso_id')
            ->join('sucursales as s', 's.id', '=', 'e.sucursal_id')
            ->join('users as u', 'u.id', '=', 'e.usuario_id')
            ->leftJoin('proveedores as p', 'p.id', '=', 'e.proveedor_id')
            ->leftJoin('cuentas_pagar as cp', 'cp.egreso_id', '=', 'e.id')
            ->where('e.empresa_id', $empresaId)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('e.sucursal_id', $sucursalId))
            ->when($categoriaId !== null, fn (Builder $q) => $q->where('e.categoria_egreso_id', $categoriaId))
            ->when($termino !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('e.descripcion', 'like', $patron)
                ->orWhere('ce.nombre', 'like', $patron)
                ->orWhere('u.name', 'like', $patron)
                ->orWhere('p.nombre', 'like', $patron)
                ->when($numero !== '' && $numero !== null, fn (Builder $q) => $q->orWhere('e.numero', (int) $numero))))
            ->orderByDesc('e.fecha')
            ->orderByDesc('e.id')
            ->select(['e.id', 'e.numero', 'e.fecha', 'e.descripcion', 'e.metodo', 'e.monto', 'e.condicion', 'e.pagado',
                'cp.saldo_pendiente as saldo', 'p.nombre as beneficiario',
                'ce.nombre as categoria', 's.nombre as sucursal', 'u.name as usuario']);
    }

    public static function total(int $empresaId, Periodo $periodo): string
    {
        [$inicio, $fin] = $periodo->limitesUtc();

        return Decimal::redondear((string) DB::table('egresos')->where('empresa_id', $empresaId)
            ->where('fecha', '>=', $inicio)->where('fecha', '<', $fin)->sum('monto'));
    }
}
