<?php

namespace App\Domain\AgenteMozi\Herramientas;

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\Datos\CatalogoDatos;
use App\Domain\AgenteMozi\DTOs\DatasetPropuesto;
use App\Domain\Reportes\Consultas\VentasPeriodo;
use App\Domain\Reportes\Periodo;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * analyze_operating_expenses (UC-15): en qué se gasta y qué gasto subió. Egresos del período por
 * su fecha frente al período anterior de igual duración, peso sobre la venta neta (VentasPeriodo) y
 * gasto registrado separado de lo pagado. «Venta neta − gastos» no es utilidad neta: falta el costo
 * de ventas (D1). Las compras de mercadería no son gastos operativos.
 */
final class GastosOperativos extends HerramientaNegocio
{
    public function nombre(): string
    {
        return 'analyze_operating_expenses';
    }

    public function descripcion(): string
    {
        return 'Gastos operativos (egresos; no incluye compras de mercadería) del período (por defecto el mes en curso) frente al período anterior de igual duración: '
            .'resumen (gastado, pagado, saldo, variación, venta neta del período, peso de los gastos sobre la venta neta y «venta neta − gastos», que NO es utilidad neta porque falta el costo de ventas), '
            .'por categoría con variación, serie diaria (o mensual si el período supera 62 días) y los 50 egresos más grandes.';
    }

    public function parametros(): array
    {
        return ['type' => 'object', 'properties' => $this->propiedadesPeriodo('el primer día del mes en curso'), 'additionalProperties' => false];
    }

    public function reglas(): array
    {
        return $this->reglasPeriodo();
    }

    public function avance(): string
    {
        return __('Analizando los gastos operativos…');
    }

    public function ejecutar(ContextoAgente $contexto, array $argumentos): array
    {
        $empresaId = $contexto->empresaId;
        $periodo = $this->periodo($contexto, $argumentos, 0);
        $anterior = $this->anterior($periodo);
        [$inicio, $fin] = $periodo->limitesUtc();
        [$inicioAnterior] = $anterior->limitesUtc();
        $pagado = 'CASE WHEN cp.id IS NULL THEN eg.pagado ELSE eg.monto - cp.saldo_pendiente END';
        $egresos = fn () => DB::table('egresos as eg')
            ->leftJoin('categorias_egreso as ce', 'ce.id', '=', 'eg.categoria_egreso_id')
            ->leftJoin('cuentas_pagar as cp', fn ($j) => $j->on('cp.egreso_id', '=', 'eg.id')->on('cp.empresa_id', '=', 'eg.empresa_id')->whereNull('cp.anulado_at'))
            ->where('eg.empresa_id', $empresaId);
        $actual = fn (Builder $q) => $q->where('eg.fecha', '>=', $inicio)->where('eg.fecha', '<', $fin);

        $totales = $actual($egresos())->first([
            DB::raw('COALESCE(SUM(eg.monto), 0) AS gastado'), DB::raw("COALESCE(SUM({$pagado}), 0) AS pagado"),
            DB::raw('COALESCE(SUM(COALESCE(cp.saldo_pendiente, 0)), 0) AS saldo'), DB::raw('COUNT(*) AS egresos'),
        ]);
        $gastado = Decimal::redondear((string) $totales->gastado);
        $gastadoAntes = Decimal::redondear((string) $egresos()->where('eg.fecha', '>=', $inicioAnterior)->where('eg.fecha', '<', $inicio)->sum('eg.monto'));
        $ventaNeta = VentasPeriodo::totales($empresaId, $periodo)['venta_neta'];

        $categorias = $egresos()->where('eg.fecha', '>=', $inicioAnterior)->where('eg.fecha', '<', $fin)
            ->groupByRaw("COALESCE(ce.nombre, 'Sin categoría')")
            ->selectRaw("COALESCE(ce.nombre, 'Sin categoría') AS categoria")
            ->selectRaw('SUM(CASE WHEN eg.fecha >= ? THEN eg.monto ELSE 0 END) AS actual', [$inicio])
            ->selectRaw('SUM(CASE WHEN eg.fecha >= ? THEN 0 ELSE eg.monto END) AS anterior', [$inicio])
            ->selectRaw('SUM(CASE WHEN eg.fecha >= ? THEN 1 ELSE 0 END) AS egresos', [$inicio])
            ->get()
            ->map(function (object $c) use ($gastado): array {
                $actual = Decimal::redondear((string) $c->actual);
                $antes = Decimal::redondear((string) $c->anterior);

                return ['categoria' => $c->categoria, 'actual' => $actual, 'anterior' => $antes, 'variacion' => Decimal::restar($actual, $antes, 2),
                    'variacion_pct' => $this->variacion($actual, $antes), 'peso_pct' => $this->porcentaje($actual, $gastado), 'egresos' => (int) $c->egresos];
            })
            ->all();
        usort($categorias, fn (array $a, array $b) => Decimal::comparar($b['actual'], $a['actual'], 2) ?: Decimal::comparar($b['anterior'], $a['anterior'], 2));

        $codigo = CatalogoDatos::sqlCodigo('EGR', 'eg.numero');
        $detalle = $actual($egresos())
            ->leftJoin('proveedores as pr', 'pr.id', '=', 'eg.proveedor_id')
            ->orderByDesc('eg.monto')->orderByDesc('eg.fecha')
            ->limit(50)
            ->select([DB::raw("{$codigo} AS egreso"), DB::raw("COALESCE(ce.nombre, 'Sin categoría') AS categoria"),
                'eg.descripcion', 'pr.nombre as beneficiario', 'eg.monto', DB::raw("{$pagado} AS pagado"), DB::raw('COALESCE(cp.saldo_pendiente, 0) AS saldo')])
            ->selectRaw('DATE('.$this->local('eg.fecha').') AS fecha', [$this->desfase($contexto)])
            ->get();

        $meta = $this->meta($contexto, 'egresos', $periodo, metodo: 'Egresos por su fecha; período anterior: '.$anterior->desde->format('Y-m-d').' a '.$anterior->hasta->format('Y-m-d').'.');
        $dinero = fn (string $c, string $l) => $this->col($c, $l, 'money');

        return [
            $this->dataset('resumen', [
                $dinero('gastado', __('Gastado')), $dinero('pagado', __('Pagado')), $dinero('saldo', __('Por pagar')), $this->col('egresos', __('Egresos'), 'integer'),
                $dinero('gastado_anterior', __('Gastado en el período anterior')), $this->col('variacion_pct', __('Variación'), 'percent'),
                $dinero('venta_neta', __('Venta neta')), $this->col('peso_sobre_venta_pct', __('Peso sobre la venta neta'), 'percent'),
                $dinero('venta_neta_menos_gastos', __('Venta neta − gastos')),
            ], [[
                'gastado' => $gastado, 'pagado' => $totales->pagado, 'saldo' => $totales->saldo, 'egresos' => $totales->egresos,
                'gastado_anterior' => $gastadoAntes, 'variacion_pct' => $this->variacion($gastado, $gastadoAntes),
                'venta_neta' => $ventaNeta, 'peso_sobre_venta_pct' => $this->porcentaje($gastado, $ventaNeta),
                'venta_neta_menos_gastos' => Decimal::restar($ventaNeta, $gastado, 2),
            ]], $meta, 'Resumen (una fila). venta_neta = ventas confirmadas − devoluciones del período (Reportes › Ventas). venta_neta_menos_gastos no es utilidad: falta el costo de lo vendido.'),
            $this->dataset('por_categoria', [
                $this->col('categoria', __('Categoría'), 'string'), $dinero('actual', __('Período')), $dinero('anterior', __('Período anterior')),
                $dinero('variacion', __('Variación')), $this->col('variacion_pct', __('Variación %'), 'percent'), $this->col('peso_pct', __('Peso'), 'percent'),
                $this->col('egresos', __('Egresos'), 'integer'),
            ], $categorias, $meta, 'Gasto por categoría en el período y en el anterior; peso_pct = parte del gasto del período.'),
            $this->serie($contexto, $periodo, $actual($egresos()), $meta),
            $this->dataset('detalle', [
                $this->col('egreso', __('Egreso'), 'string'), $this->col('fecha', __('Fecha'), 'date'), $this->col('categoria', __('Categoría'), 'string'),
                $this->col('descripcion', __('Descripción'), 'string'), $this->col('beneficiario', __('Beneficiario'), 'string'),
                $dinero('monto', __('Monto')), $dinero('pagado', __('Pagado')), $dinero('saldo', __('Saldo')),
            ], $detalle, $meta, 'Los 50 egresos más grandes del período.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{dataset: DatasetPropuesto, descripcion: string}
     */
    private function serie(ContextoAgente $contexto, Periodo $periodo, Builder $egresos, array $meta): array
    {
        $diaria = $periodo->desde->diffInDays($periodo->hasta) < 62;
        $local = $this->local('eg.fecha');
        $clave = $diaria ? "DATE({$local})" : "DATE_FORMAT({$local}, '%Y-%m')";
        // Se agrupa por el alias: dos «?» no son la misma expresión para ONLY_FULL_GROUP_BY.
        $montos = $egresos->selectRaw("{$clave} AS serie_clave", [$this->desfase($contexto)])->selectRaw('SUM(eg.monto) AS gastado')
            ->groupBy('serie_clave')->get()
            ->keyBy(fn (object $f) => (string) $f->serie_clave);
        $filas = [];

        for ($d = $periodo->desde; $d->lessThanOrEqualTo($periodo->hasta); $d = $diaria ? $d->addDay() : $d->addMonthNoOverflow()->startOfMonth()) {
            $etiqueta = $d->format($diaria ? 'Y-m-d' : 'Y-m');
            $filas[] = [$diaria ? 'fecha' : 'mes' => $etiqueta, 'gastado' => $montos->get($etiqueta)->gastado ?? '0'];
        }

        return $this->dataset('serie', [
            $diaria ? $this->col('fecha', __('Fecha'), 'date') : $this->col('mes', __('Mes'), 'string'),
            $this->col('gastado', __('Gastado'), 'money'),
        ], $filas, $meta, $diaria ? 'Gasto por día del período (0 = sin egresos).' : 'Gasto por mes del período.');
    }
}
