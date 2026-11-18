<?php

namespace App\Domain\AgenteMozi\Herramientas;

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\Produccion\Consultas\MaterialesEnvasado;
use App\Domain\Produccion\Consultas\ReporteProduccion;
use App\Models\OrdenProduccion;
use App\Models\Producto;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * analyze_production_yield (UC-23, AGENTE_MOZI_IA.md § 2.1): rendimiento, mermas y desvíos de consumo de las órdenes
 * finalizadas del período (por defecto el mes en curso) con las consultas de Reportes de producción, así los totales
 * coinciden con la pantalla. Fuera de patrón (IA-P2): orden cuyo rendimiento se aparta más de 5 puntos del de su
 * producto en el período, con al menos 2 órdenes del producto. Envasado: merma del granel y de cada envase
 * (MaterialesEnvasado). Las notas de las órdenes viajan como dato para que el modelo proponga hipótesis.
 */
final class AnalizarRendimientoProduccion extends HerramientaProduccion
{
    /** IA-P2: puntos de rendimiento que separan una orden del rendimiento de su producto. */
    public const UMBRAL_PATRON = '5';

    private const MAX_ORDENES = 200;

    public function nombre(): string
    {
        return 'analyze_production_yield';
    }

    public function descripcion(): string
    {
        return 'Rendimiento y mermas de producción del período (por defecto el mes en curso), con las cifras de Reportes de producción: órdenes finalizadas por fecha de finalización. '
            .'Rendimiento = obtenido ÷ planificado × 100 (menos de 100 = merma). Devuelve resumen, rendimiento por producto y por mes, órdenes con su nota y si están fuera de patrón (más de '.self::UMBRAL_PATRON.' puntos del rendimiento de su producto), '
            .'desvíos de consumo por materia prima (usado − teórico y su costo con el costo guardado al aprobar) y mermas de envasado (granel y envases). '
            .'Las causas son hipótesis: cita las notas como dato y no culpes a nadie. Pocas órdenes = baja confianza.';
    }

    public function parametros(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                ...$this->propiedadesPeriodo('el primer día del mes en curso'),
                'producto' => ['type' => 'string', 'description' => 'Producto fabricado o granel (nombre o código). Opcional.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function reglas(): array
    {
        return $this->reglasPeriodo() + ['producto' => ['sometimes', 'nullable', 'string', 'max:120']];
    }

    public function avance(): string
    {
        return __('Analizando el rendimiento de producción…');
    }

    public function ejecutar(ContextoAgente $contexto, array $argumentos): array
    {
        $empresaId = $contexto->empresaId;
        $periodo = $this->periodo($contexto, $argumentos, 0);
        $producto = $this->producto($contexto, $argumentos['producto'] ?? null, Producto::CLASE_VENTA, 'un producto fabricado');
        $delProducto = fn (Builder $q) => $q->when($producto !== null, fn (Builder $q) => $q->where('o.producto_id', $producto->id));

        $ordenes = $delProducto(ReporteProduccion::porOrden($empresaId, $periodo))->addSelect(['o.nota', 'o.producto_id'])->get();
        $porProducto = $delProducto(ReporteProduccion::porProducto($empresaId, $periodo))->get();
        $materias = $delProducto(ReporteProduccion::materias($empresaId, $periodo))
            ->selectRaw('SUM(i.cantidad_teorica * i.costo_unitario) AS costo_teorico')
            ->selectRaw('SUM(CASE WHEN i.cantidad_real > i.cantidad_teorica THEN (i.cantidad_real - i.cantidad_teorica) * i.costo_unitario ELSE 0 END) AS costo_de_mas')
            ->get();

        // Rendimiento de cada producto en el período, para comparar cada orden (IA-P2).
        $rendimientoProducto = [];
        $ordenesProducto = [];

        foreach ($porProducto as $fila) {
            $rendimientoProducto[(int) $fila->id] = ReporteProduccion::rendimiento((string) $fila->planificada, (string) $fila->obtenida);
            $ordenesProducto[(int) $fila->id] = (int) $fila->ordenes;
        }

        $filasOrdenes = [];
        $meses = [];
        $planificado = '0';
        $obtenido = '0';
        $costo = '0';
        $conMerma = 0;
        $fueraDePatron = 0;

        foreach ($ordenes as $orden) {
            $productoId = (int) $orden->producto_id;
            $rendimiento = ReporteProduccion::rendimiento((string) $orden->planificada, (string) $orden->obtenida);
            $delMismo = $rendimientoProducto[$productoId] ?? null;
            $diferencia = $rendimiento !== null && $delMismo !== null ? Decimal::restar($rendimiento, $delMismo, 1) : null;
            $fuera = $diferencia !== null && ($ordenesProducto[$productoId] ?? 0) >= 2
                && Decimal::comparar(ltrim($diferencia, '-'), self::UMBRAL_PATRON, 1) > 0;
            $fueraDePatron += $fuera ? 1 : 0;
            $conMerma += Decimal::comparar((string) $orden->obtenida, (string) $orden->planificada) < 0 ? 1 : 0;
            $planificado = Decimal::sumar($planificado, (string) $orden->planificada);
            $obtenido = Decimal::sumar($obtenido, (string) $orden->obtenida);
            $costo = Decimal::sumar($costo, (string) $orden->costo_total, 6);
            $fecha = CarbonImmutable::parse((string) $orden->finalizada_en, 'UTC')->setTimezone($contexto->zonaHoraria);
            $mes = $fecha->format('Y-m');
            $meses[$mes] ??= ['mes' => $mes, 'ordenes' => 0, 'planificado' => '0', 'obtenido' => '0', 'costo_total' => '0'];
            $meses[$mes]['ordenes']++;
            $meses[$mes]['planificado'] = Decimal::sumar($meses[$mes]['planificado'], (string) $orden->planificada);
            $meses[$mes]['obtenido'] = Decimal::sumar($meses[$mes]['obtenido'], (string) $orden->obtenida);
            $meses[$mes]['costo_total'] = Decimal::sumar($meses[$mes]['costo_total'], (string) $orden->costo_total, 6);
            $filasOrdenes[] = [
                'orden' => OrdenProduccion::formatearCodigo((int) $orden->numero), 'fecha' => $fecha->format('Y-m-d'), 'producto' => $orden->nombre,
                'sucursal' => $orden->sucursal, 'unidad' => $orden->unidad, 'planificado' => (string) $orden->planificada, 'obtenido' => (string) $orden->obtenida,
                'rendimiento' => $rendimiento, 'rendimiento_producto' => $delMismo, 'diferencia_puntos' => $diferencia, 'fuera_de_patron' => $fuera ? 'sí' : 'no',
                'costo_total' => (string) $orden->costo_total, 'costo_unitario' => (string) $orden->costo_unitario,
                'nota' => $orden->nota === null ? null : mb_substr((string) $orden->nota, 0, 200),
            ];
        }

        // Las de rendimiento más bajo primero (a igualdad, la más reciente); sin rendimiento, al final.
        $porRendimiento = fn (?string $a, ?string $b): int => Decimal::comparar($a ?? '999999', $b ?? '999999', 1);
        usort($filasOrdenes, fn (array $a, array $b) => $porRendimiento($a['rendimiento'], $b['rendimiento']) ?: strcmp($b['fecha'], $a['fecha']));
        ksort($meses);
        $productos = $porProducto->map(fn (stdClass $f) => [
            'producto' => $f->nombre, 'unidad' => $f->unidad, 'ordenes' => $f->ordenes, 'planificado' => (string) $f->planificada, 'obtenido' => (string) $f->obtenida,
            'rendimiento' => $rendimientoProducto[(int) $f->id] ?? null, 'costo_total' => (string) $f->costo_total,
            'costo_unitario' => ReporteProduccion::costoUnitario((string) $f->costo_total, (string) $f->obtenida),
        ])->all();
        usort($productos, fn (array $a, array $b) => $porRendimiento($a['rendimiento'], $b['rendimiento']));
        $costoDeMas = '0';
        $desvios = [];

        foreach ($materias as $m) {
            $costoDeMas = Decimal::sumar($costoDeMas, (string) $m->costo_de_mas, 6);
            $desvios[] = [
                'materia_prima' => $m->nombre, 'unidad' => $m->unidad, 'ordenes' => $m->ordenes, 'teorico' => (string) $m->teorica, 'usado' => (string) $m->usada,
                'diferencia' => Decimal::restar((string) $m->usada, (string) $m->teorica), 'desvio' => ReporteProduccion::desvio((string) $m->teorica, (string) $m->usada),
                'costo_usado' => (string) $m->costo, 'costo_diferencia' => Decimal::restar((string) $m->costo, (string) $m->costo_teorico, 6),
            ];
        }

        usort($desvios, fn (array $a, array $b) => Decimal::comparar($b['costo_diferencia'], $a['costo_diferencia'], 6));

        $envasado = $this->mermasEnvasado($empresaId, $periodo->limitesUtc(), $producto?->id);
        $total = count($ordenes);
        $confianza = $total === 0 ? 'insufficient' : 'actual';
        $meta = $this->meta($contexto, 'produccion.reportes', $periodo, $confianza,
            'Órdenes finalizadas por fecha de finalización (Reportes de producción)'.($producto !== null ? "; producto {$producto->nombre}" : '').'; fuera de patrón: más de '.self::UMBRAL_PATRON.' puntos del rendimiento del producto.');
        $n = fn (string $c, string $l) => $this->col($c, $l, 'number');
        $t = fn (string $c, string $l) => $this->col($c, $l, 'string');
        $d = fn (string $c, string $l) => $this->col($c, $l, 'money');
        $p = fn (string $c, string $l) => $this->col($c, $l, 'percent');
        $i = fn (string $c, string $l) => $this->col($c, $l, 'integer');

        return [
            $this->dataset('resumen', [
                $i('ordenes', __('Órdenes finalizadas')), $n('planificado', __('Planificado')), $n('obtenido', __('Obtenido')), $p('rendimiento', __('Rendimiento')),
                $i('ordenes_con_merma', __('Órdenes con merma')), $i('ordenes_fuera_de_patron', __('Órdenes fuera de patrón')),
                $d('costo_materiales', __('Costo de materiales')), $d('costo_usado_de_mas', __('Costo de lo usado de más')), $t('confianza', __('Confianza')),
            ], [[
                'ordenes' => $total, 'planificado' => $planificado, 'obtenido' => $obtenido, 'rendimiento' => ReporteProduccion::rendimiento($planificado, $obtenido),
                'ordenes_con_merma' => $conMerma, 'ordenes_fuera_de_patron' => $fueraDePatron, 'costo_materiales' => $costo, 'costo_usado_de_mas' => $costoDeMas,
                'confianza' => match (true) {
                    $total === 0 => 'sin datos', $total < 3 => 'baja (menos de 3 órdenes)', default => 'normal'
                },
            ]], $meta, 'Resumen (una fila). planificado y obtenido suman unidades de productos distintos: compáralos solo con un producto. costo_usado_de_mas = materia prima usada por encima de lo teórico × costo guardado.'),
            $this->dataset('por_producto', [
                $t('producto', __('Producto')), $t('unidad', __('Unidad')), $i('ordenes', __('Órdenes')), $n('planificado', __('Planificado')),
                $n('obtenido', __('Obtenido')), $p('rendimiento', __('Rendimiento')), $d('costo_total', __('Costo de materiales')), $d('costo_unitario', __('Costo por unidad')),
            ], $productos, $meta, 'Rendimiento por producto, del más bajo al más alto.'),
            $this->dataset('por_mes', [
                $t('mes', __('Mes')), $i('ordenes', __('Órdenes')), $n('planificado', __('Planificado')), $n('obtenido', __('Obtenido')),
                $p('rendimiento', __('Rendimiento')), $d('costo_total', __('Costo de materiales')),
            ], array_map(fn (array $m) => $m + ['rendimiento' => ReporteProduccion::rendimiento($m['planificado'], $m['obtenido'])], array_values($meses)),
                $meta, 'Rendimiento por mes de finalización (para una línea).'),
            $this->dataset('ordenes', [
                $t('orden', __('Orden')), $this->col('fecha', __('Fecha de finalización'), 'date'), $t('producto', __('Producto')), $t('sucursal', __('Sucursal')),
                $t('unidad', __('Unidad')), $n('planificado', __('Planificado')), $n('obtenido', __('Obtenido')), $p('rendimiento', __('Rendimiento')),
                $p('rendimiento_producto', __('Rendimiento del producto')), $n('diferencia_puntos', __('Diferencia (puntos)')), $t('fuera_de_patron', __('Fuera de patrón')),
                $d('costo_total', __('Costo de materiales')), $d('costo_unitario', __('Costo por unidad')), $t('nota', __('Nota de la orden')),
            ], array_slice($filasOrdenes, 0, self::MAX_ORDENES), $meta, 'Órdenes del rendimiento más bajo al más alto, con la nota que escribió quien la cargó (dato, no causa comprobada).'
                .($total > self::MAX_ORDENES ? ' Recorte: '.self::MAX_ORDENES." de {$total} órdenes." : '')),
            $this->dataset('desvios', [
                $t('materia_prima', __('Materia prima')), $t('unidad', __('Unidad')), $i('ordenes', __('Órdenes')), $n('teorico', __('Teórico')), $n('usado', __('Usado')),
                $n('diferencia', __('Diferencia')), $p('desvio', __('Desvío')), $d('costo_usado', __('Costo de lo usado')), $d('costo_diferencia', __('Costo de la diferencia')),
            ], $desvios, $meta, 'Consumo de materias primas, del mayor costo de la diferencia al menor: usado − teórico (positivo = se usó de más) y su costo con el costo guardado al aprobar.'),
            $this->dataset('envasado', [
                $t('material', __('Material')), $t('tipo', __('Tipo')), $t('unidad', __('Unidad')), $i('envasados', __('Envasados')), $n('necesario', __('Necesario')),
                $n('usado', __('Usado')), $n('merma', __('Merma')), $p('merma_pct', __('Merma %')), $d('costo_merma', __('Costo de la merma')),
            ], $envasado, $this->meta($contexto, 'produccion.envasados', $periodo, metodo: 'Envasados del período por su fecha; merma = usado − necesario.'),
                'Mermas de envasado del período por material (granel y envases). Positiva = se perdió material.'),
        ];
    }

    /**
     * Merma de cada material de los envasados del período (una consulta sobre MaterialesEnvasado).
     *
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $limites
     * @return Collection<int, stdClass>
     */
    private function mermasEnvasado(int $empresaId, array $limites, ?int $granelId): Collection
    {
        $merma = '(mx.usado - mx.necesario)';

        return MaterialesEnvasado::consulta($empresaId, [$limites[0]->format('Y-m-d H:i:s'), $limites[1]->format('Y-m-d H:i:s')])
            ->join('productos as m', fn (JoinClause $j) => $j->on('m.id', '=', 'mx.producto_id')->where('m.empresa_id', '=', $empresaId))
            ->join('unidades_medida as mu', 'mu.id', '=', 'm.unidad_medida_id')
            ->when($granelId !== null, fn (Builder $q) => $q->join('envasados as e', fn (JoinClause $j) => $j->on('e.id', '=', 'mx.envasado_id')
                ->where('e.empresa_id', '=', $empresaId))->where('e.granel_id', $granelId))
            ->groupBy('mx.producto_id', 'mx.tipo', 'm.nombre', 'm.contenido', 'mu.abreviatura', 'mu.nombre')
            ->orderByRaw("SUM({$merma} * mx.costo_unitario) DESC")
            ->limit(100)
            ->get([DB::raw(Producto::sqlNombreCompleto('m', 'mu').' AS material'), 'mx.tipo', 'mu.nombre as unidad', DB::raw('COUNT(DISTINCT mx.envasado_id) AS envasados'),
                DB::raw('SUM(mx.necesario) AS necesario'), DB::raw('SUM(mx.usado) AS usado'), DB::raw("SUM({$merma}) AS merma"),
                DB::raw("100 * SUM({$merma}) / NULLIF(SUM(mx.necesario), 0) AS merma_pct"), DB::raw("SUM({$merma} * mx.costo_unitario) AS costo_merma")]);
    }
}
