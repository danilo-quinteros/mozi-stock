<?php

namespace App\Domain\Reportes\Consultas;

use App\Domain\Reportes\Periodo;
use App\Models\Venta;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Ventas confirmadas del período: el valor vendido es actividad comercial, nunca ingreso.
 * Junto a él se muestra lo devuelto, lo cobrado hasta hoy y el saldo, en columnas separadas.
 */
final class VentasPeriodo
{
    public static function consulta(int $empresaId, Periodo $periodo, ?int $sucursalId = null, string $buscar = ''): Builder
    {
        [$inicio, $fin] = $periodo->limitesUtc();
        $termino = trim($buscar);
        $patron = '%'.addcslashes($termino, '\\%_').'%';
        $numero = preg_replace('/\D/', '', $termino);

        return DB::table('ventas as v')
            ->join('users as vend', 'vend.id', '=', 'v.vendedor_id')
            ->join('sucursales as s', 's.id', '=', 'v.sucursal_id')
            ->leftJoin('clientes as c', 'c.id', '=', 'v.cliente_id')
            ->where('v.empresa_id', $empresaId)
            ->where('v.fecha', '>=', $inicio)
            ->where('v.fecha', '<', $fin)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('v.sucursal_id', $sucursalId))
            ->when($termino !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('c.nombre', 'like', $patron)
                ->orWhere('vend.name', 'like', $patron)
                ->when($numero !== '' && $numero !== null, fn (Builder $q) => $q->orWhere('v.numero', (int) $numero))))
            ->orderByDesc('v.fecha')
            ->orderByDesc('v.id')
            ->select(['v.id', 'v.numero', 'v.fecha', 'v.total', 'v.saldo_pendiente', 'v.cobrado_inicial', 'v.devuelto',
                'vend.name as vendedor', 'c.nombre as cliente', 's.nombre as sucursal', DB::raw(Venta::sqlCobrado('v').' AS cobrado')]);
    }

    /**
     * Valor vendido y cantidad de ventas del período.
     *
     * @return array{valor_vendido: string, cantidad: int}
     */
    public static function totales(int $empresaId, Periodo $periodo, ?int $sucursalId = null): array
    {
        $fila = self::consulta($empresaId, $periodo, $sucursalId)->reorder()
            ->select([DB::raw('COALESCE(SUM(v.total), 0) AS valor'), DB::raw('COUNT(*) AS cantidad')])->first();

        return ['valor_vendido' => Decimal::redondear((string) ($fila->valor ?? '0')), 'cantidad' => (int) ($fila->cantidad ?? 0)];
    }

    /**
     * Saldo total por cobrar a hoy (no depende del período) y cotizaciones pendientes.
     *
     * @return array{pendiente: string, creditos_abiertos: int, cotizaciones: int}
     */
    public static function pendientes(int $empresaId, ?int $sucursalId = null): array
    {
        $saldo = DB::table('ventas')->where('empresa_id', $empresaId)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('sucursal_id', $sucursalId))
            ->where('saldo_pendiente', '>', 0)
            ->selectRaw('COALESCE(SUM(saldo_pendiente), 0) AS saldo, COUNT(*) AS abiertos')->first();

        return [
            'pendiente' => Decimal::redondear((string) ($saldo->saldo ?? '0')),
            'creditos_abiertos' => (int) ($saldo->abiertos ?? 0),
            'cotizaciones' => DB::table('pedidos')->where('empresa_id', $empresaId)->where('estado', 'pendiente')
                ->when($sucursalId !== null, fn (Builder $q) => $q->where('sucursal_id', $sucursalId))->count(),
        ];
    }
}
