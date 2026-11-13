<?php

namespace App\Domain\Inventario\Consultas;

use App\Domain\Inventario\DatosLote;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Reporte «Vencimientos» (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 4 y § 9.3): lotes con stock por sucursal. Pantalla,
 * CSV y aviso del Inicio comparten esta consulta. Siempre filtra por empresa en cada tabla unida; usa los índices
 * stock_lotes (empresa_id, sucursal_id, producto_id) y lotes (empresa_id, vencimiento).
 *
 * Estados: vencidos (vencimiento < hoy, fecha del negocio), vence en 30, 60 o 90 días (desde hoy, incluido), sin
 * fecha (lote de regularización, pendiente de regularizar) y todos.
 */
final class ReporteVencimientos
{
    public const ESTADOS = ['todos', 'vencidos', '30', '60', '90', 'sin_fecha'];

    /**
     * @param  array{estado?: string, sucursal_id?: int|null, buscar?: string}  $filtros
     */
    public static function consulta(int $empresaId, array $filtros = []): Builder
    {
        $hoy = DatosLote::hoy();
        $estado = in_array($filtros['estado'] ?? 'todos', self::ESTADOS, true) ? ($filtros['estado'] ?? 'todos') : 'todos';
        $sucursalId = $filtros['sucursal_id'] ?? null;
        $termino = trim($filtros['buscar'] ?? '');
        $patron = '%'.addcslashes($termino, '\\%_').'%';

        return DB::table('stock_lotes as sl')
            ->join('lotes as l', 'l.id', '=', 'sl.lote_id')
            ->join('productos as p', 'p.id', '=', 'sl.producto_id')
            ->join('unidades_medida as um', 'um.id', '=', 'p.unidad_medida_id')
            ->join('sucursales as s', 's.id', '=', 'sl.sucursal_id')
            ->where('sl.empresa_id', $empresaId)
            ->where('l.empresa_id', $empresaId)
            ->where('p.empresa_id', $empresaId)
            ->where('s.empresa_id', $empresaId)
            ->where('p.controla_vencimiento', true)
            ->where('sl.cantidad', '>', 0)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('sl.sucursal_id', $sucursalId))
            ->when($estado === 'vencidos', fn (Builder $q) => $q->where('l.vencimiento', '<', $hoy))
            ->when(in_array($estado, ['30', '60', '90'], true), fn (Builder $q) => $q
                ->whereBetween('l.vencimiento', [$hoy, Carbon::parse($hoy)->addDays((int) $estado)->toDateString()]))
            ->when($estado === 'sin_fecha', fn (Builder $q) => $q->where('l.es_regularizacion', true))
            ->when($termino !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('p.nombre', 'like', $patron)
                ->orWhere('p.sku', 'like', $patron)
                ->orWhere('l.numero', 'like', $patron)))
            // Lo que vence antes, arriba; el stock sin fecha, al final.
            ->orderBy('l.es_regularizacion')
            ->orderBy('l.vencimiento')
            ->orderBy('p.nombre')
            ->orderBy('s.nombre')
            ->orderBy('sl.id')
            ->select([
                'sl.id', 'sl.cantidad', 'sl.sucursal_id', 'sl.producto_id',
                'l.id as lote_id', 'l.numero', 'l.vencimiento', 'l.es_regularizacion',
                'p.nombre as producto', 'p.contenido', 'p.sku', 'um.abreviatura',
                's.nombre as sucursal',
            ]);
    }

    /**
     * Totales para las tarjetas del reporte y el aviso del Inicio, en una consulta.
     *
     * @return array{vencidos: int, unidades_vencidas: string, proximos: int, unidades_proximas: string, sin_fecha: int, unidades_sin_fecha: string}
     */
    public static function resumen(int $empresaId, ?int $sucursalId = null): array
    {
        $hoy = DatosLote::hoy();
        $limite = Carbon::parse($hoy)->addDays(30)->toDateString();
        $fila = DB::table('stock_lotes as sl')
            ->join('lotes as l', 'l.id', '=', 'sl.lote_id')
            ->join('productos as p', 'p.id', '=', 'sl.producto_id')
            ->where('sl.empresa_id', $empresaId)
            ->where('l.empresa_id', $empresaId)
            ->where('p.empresa_id', $empresaId)
            ->where('p.controla_vencimiento', true)
            ->where('sl.cantidad', '>', 0)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('sl.sucursal_id', $sucursalId))
            ->selectRaw('SUM(CASE WHEN l.vencimiento < ? THEN 1 ELSE 0 END) AS vencidos', [$hoy])
            ->selectRaw('COALESCE(SUM(CASE WHEN l.vencimiento < ? THEN sl.cantidad ELSE 0 END), 0) AS unidades_vencidas', [$hoy])
            ->selectRaw('SUM(CASE WHEN l.vencimiento BETWEEN ? AND ? THEN 1 ELSE 0 END) AS proximos', [$hoy, $limite])
            ->selectRaw('COALESCE(SUM(CASE WHEN l.vencimiento BETWEEN ? AND ? THEN sl.cantidad ELSE 0 END), 0) AS unidades_proximas', [$hoy, $limite])
            ->selectRaw('SUM(CASE WHEN l.es_regularizacion = 1 THEN 1 ELSE 0 END) AS sin_fecha')
            ->selectRaw('COALESCE(SUM(CASE WHEN l.es_regularizacion = 1 THEN sl.cantidad ELSE 0 END), 0) AS unidades_sin_fecha')
            ->first();

        return [
            'vencidos' => (int) ($fila->vencidos ?? 0),
            'unidades_vencidas' => (string) ($fila->unidades_vencidas ?? '0'),
            'proximos' => (int) ($fila->proximos ?? 0),
            'unidades_proximas' => (string) ($fila->unidades_proximas ?? '0'),
            'sin_fecha' => (int) ($fila->sin_fecha ?? 0),
            'unidades_sin_fecha' => (string) ($fila->unidades_sin_fecha ?? '0'),
        ];
    }

    /**
     * Fila del reporte con el formato de StockLotes (para la etiqueta y el estado del lote).
     *
     * @return array{id: int, numero: string|null, vencimiento: string|null, regularizacion: bool, cantidad: string, vencido: bool}
     */
    public static function lote(stdClass $fila): array
    {
        $vencimiento = $fila->vencimiento === null ? null : substr((string) $fila->vencimiento, 0, 10);

        return [
            'id' => (int) $fila->lote_id,
            'numero' => $fila->numero === null ? null : (string) $fila->numero,
            'vencimiento' => $vencimiento,
            'regularizacion' => (bool) $fila->es_regularizacion,
            'cantidad' => (string) $fila->cantidad,
            'vencido' => DatosLote::vencido($vencimiento),
        ];
    }
}
