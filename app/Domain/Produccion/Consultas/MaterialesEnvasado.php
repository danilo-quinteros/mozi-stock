<?php

namespace App\Domain\Produccion\Consultas;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Materiales de cada envasado (ESPECIFICACION_PRODUCCION.md § 7.1): una fila por material, el granel (que se guarda en
 * la cabecera del envasado) y cada envase (envasado_consumos), con lo necesario, lo usado y el costo unitario guardado.
 * La diferencia entre usado y necesario es la merma. La usan la consulta genérica de Agente Mozi (materiales_envasado)
 * y el análisis de rendimiento (UC-23).
 *
 * Columnas del resultado (alias mx): envasado_id, producto_id, tipo ('granel' o 'envase'), orden, necesario, usado,
 * costo_unitario. Las dos ramas filtran por empresa y, si se indican, por los límites UTC de la fecha del envasado.
 */
final class MaterialesEnvasado
{
    /**
     * @param  array{0: string, 1: string}|null  $limitesUtc  [desde, hasta) en UTC
     */
    public static function consulta(int $empresaId, ?array $limitesUtc = null): Builder
    {
        $granel = DB::table('envasados as eg')
            ->where('eg.empresa_id', $empresaId)
            ->when($limitesUtc !== null, fn (Builder $q) => $q->where('eg.created_at', '>=', $limitesUtc[0])->where('eg.created_at', '<', $limitesUtc[1]))
            ->select(['eg.id as envasado_id', 'eg.granel_id as producto_id', DB::raw("'granel' AS tipo"), DB::raw('0 AS orden'),
                'eg.granel_teorico as necesario', 'eg.granel_real as usado', 'eg.granel_costo_unitario as costo_unitario']);

        $envases = DB::table('envasado_consumos as ec')
            ->join('envasados as ee', fn ($j) => $j->on('ee.id', '=', 'ec.envasado_id')->on('ee.empresa_id', '=', 'ec.empresa_id'))
            ->where('ec.empresa_id', $empresaId)
            ->when($limitesUtc !== null, fn (Builder $q) => $q->where('ee.created_at', '>=', $limitesUtc[0])->where('ee.created_at', '<', $limitesUtc[1]))
            ->select(['ec.envasado_id', 'ec.producto_id', DB::raw("'envase' AS tipo"), 'ec.orden',
                'ec.cantidad_teorica as necesario', 'ec.cantidad_real as usado', 'ec.costo_unitario']);

        return DB::query()->fromSub($granel->unionAll($envases), 'mx');
    }
}
