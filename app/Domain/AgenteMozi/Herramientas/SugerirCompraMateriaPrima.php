<?php

namespace App\Domain\AgenteMozi\Herramientas;

use App\Domain\AgenteMozi\Acciones\CatalogoAcciones;
use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\Produccion\Consultas\ListadoMateriasPrimas;
use App\Domain\Produccion\Escalado;
use App\Enums\EstadoOrdenProduccion;
use App\Models\Producto;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * suggest_raw_material_purchase (UC-22, AGENTE_MOZI_IA.md § 2.1): qué materia prima comprar para las órdenes emitidas
 * con fecha programada hasta el horizonte (las atrasadas incluidas) y cuánto dinero hace falta. Por materia prima y
 * sucursal: a comprar = necesidad de las emitidas + mínimo − stock utilizable (sin lotes vencidos), nunca negativo; solo
 * hay compra para órdenes donde hay necesidad. Aparte, la sugerencia de siempre por mínimo y máximo con el mismo cálculo
 * de Producción › Sugerencia de compra (ListadoMateriasPrimas::sugerido sobre el saldo de la sucursal): sin órdenes, la
 * herramienta devuelve exactamente esa sugerencia. Una aprobada ya descontó su materia prima y no cuenta.
 *
 * Presupuesto = cantidad × costo de la última compra de la materia prima (o su costo promedio si nunca se compró):
 * siempre estimado. Si la compra deja el saldo de la sucursal sobre el máximo, se avisa sin bloquear (PR17).
 *
 * incluir_plan suma a la necesidad lo sugerido por el plan de producción (UC-21, SugerirPlanProduccion::calcular) para
 * el mismo horizonte: cada producto con cifra (no las orientativas) y receta activa se escala como una orden (Escalado)
 * en su sucursal de producción. Nunca crea la compra ni la orden.
 */
final class SugerirCompraMateriaPrima extends HerramientaProduccion
{
    private const HORIZONTE = 7;

    private const MAX_FILAS = 500;

    public function nombre(): string
    {
        return 'suggest_raw_material_purchase';
    }

    public function descripcion(): string
    {
        return 'Qué materia prima comprar para las órdenes de producción emitidas con fecha programada hasta hoy + horizon_days (por defecto '.self::HORIZONTE.'; incluye las atrasadas) y cuánto dinero hace falta. '
            .'Por materia prima y sucursal: necesidad de las emitidas, stock utilizable (sin lotes vencidos), mínimo, a_comprar = necesidad + mínimo − utilizable (nunca negativo) y aviso si la compra deja el stock sobre el máximo. '
            .'Aparte, sugerido_minimo_maximo: la sugerencia de siempre por mínimo y máximo (Producción › Sugerencia de compra), que no depende de las órdenes. '
            .'Presupuesto = cantidad × costo de la última compra (o costo promedio si nunca se compró): siempre estimado. Las aprobadas ya descontaron su materia prima y no cuentan. '
            .'Con incluir_plan, la necesidad suma (necesidad_plan) la materia prima de la producción sugerida por suggest_production_plan para el mismo horizonte, en la sucursal de producción de cada producto: también estimado. Nunca crea la compra.';
    }

    public function parametros(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'horizon_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 60, 'description' => 'Días hacia adelante de la fecha programada de las órdenes emitidas. Por defecto '.self::HORIZONTE.'.'],
                'incluir_plan' => ['type' => 'boolean', 'description' => 'true para sumar la materia prima del plan de producción sugerido (suggest_production_plan) para el mismo horizonte. Por defecto false.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function reglas(): array
    {
        return [
            'horizon_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:60'],
            'incluir_plan' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    public function avance(): string
    {
        return __('Calculando la compra de materia prima…');
    }

    public function ejecutar(ContextoAgente $contexto, array $argumentos): array
    {
        $empresaId = $contexto->empresaId;
        $hoy = $contexto->fechaReferencia->format('Y-m-d');
        $dias = (int) ($argumentos['horizon_days'] ?? self::HORIZONTE);
        $hasta = $contexto->fechaReferencia->addDays($dias)->format('Y-m-d');
        $emitidas = fn () => DB::table('ordenes_produccion as o')
            ->where('o.empresa_id', $empresaId)
            ->where('o.estado', EstadoOrdenProduccion::Emitida->value)
            ->where('o.fecha_programada', '<=', $hasta);

        // Necesidad: la copia escalada de la receta de cada emitida (orden_produccion_items), por sucursal y materia.
        $necesidad = [];

        foreach ($emitidas()
            ->join('orden_produccion_items as i', fn (JoinClause $j) => $j->on('i.orden_id', '=', 'o.id')->on('i.empresa_id', '=', 'o.empresa_id'))
            ->groupBy('o.sucursal_id', 'i.producto_id')
            ->get(['o.sucursal_id', 'i.producto_id', DB::raw('SUM(i.cantidad_teorica) AS necesidad'), DB::raw('COUNT(DISTINCT o.id) AS ordenes')]) as $fila) {
            $necesidad[(int) $fila->sucursal_id][(int) $fila->producto_id] = ['cantidad' => (string) $fila->necesidad, 'ordenes' => (int) $fila->ordenes];
        }

        $ordenes = $emitidas()->count();
        $incluirPlan = (bool) ($argumentos['incluir_plan'] ?? false);
        $plan = $incluirPlan ? $this->necesidadPlan($contexto, $dias) : ['necesidad' => [], 'productos' => 0];
        $materiasPedidas = array_values(array_unique(array_merge([], ...array_map('array_keys', [...array_values($necesidad), ...array_values($plan['necesidad'])]))));
        $sucursalesPedidas = array_values(array_unique(array_merge(array_keys($necesidad), array_keys($plan['necesidad']))));
        $base = DB::table('productos as p')
            ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
            ->join('sucursales as s', 's.empresa_id', '=', 'p.empresa_id')
            ->leftJoin('stock_niveles as sn', fn (JoinClause $j) => $j->on('sn.producto_id', '=', 'p.id')->on('sn.sucursal_id', '=', 's.id')->on('sn.empresa_id', '=', 'p.empresa_id'))
            ->where('p.empresa_id', $empresaId)
            ->where('p.clase', Producto::CLASE_MATERIA_PRIMA)
            ->where(fn (Builder $q) => $q->where('p.activo', true)->orWhereIn('p.id', $materiasPedidas))
            ->where(fn (Builder $q) => $q->where('s.activa', true)->orWhereIn('s.id', $sucursalesPedidas))
            ->orderBy('s.nombre')->orderBy('p.nombre')->orderBy('p.id')
            ->get(['p.id', 'p.activo', 'p.stock_maximo', 'p.costo_promedio', 's.id as sucursal_id', 's.nombre as sucursal', 'u.nombre as unidad',
                DB::raw('COALESCE(sn.cantidad, 0) AS existencia'), DB::raw('COALESCE(sn.stock_minimo, p.stock_minimo, 0) AS minimo'),
                DB::raw(Producto::sqlNombreCompleto('p', 'u').' AS nombre')]);
        $stock = $this->stockUtilizable($contexto,
            array_values(array_unique($base->pluck('sucursal_id')->map(fn ($id): int => (int) $id)->all())),
            array_values(array_unique($base->pluck('id')->map(fn ($id): int => (int) $id)->all())));

        $filas = [];

        foreach ($base as $materia) {
            $fila = $this->fila($materia, $necesidad, $plan['necesidad'], $stock);

            if ($fila !== null) {
                $filas[] = $fila;
            }
        }

        $recortado = count($filas) > self::MAX_FILAS;
        $filas = array_slice($filas, 0, self::MAX_FILAS);
        $ultimas = $this->ultimasCompras($contexto, array_values(array_unique(array_column($filas, '_producto_id'))));
        $resumen = ['ordenes_emitidas' => $ordenes, 'productos_en_plan' => $incluirPlan ? $plan['productos'] : null, 'materias_a_comprar' => 0,
            'presupuesto_estimado' => '0.00', 'materias_sin_costo' => 0, 'compras_sobre_maximo' => 0, 'materias_bajo_minimo' => 0, 'presupuesto_sugerencia' => '0.00'];
        $proveedores = [];

        foreach ($filas as &$fila) {
            $ultima = $ultimas[$fila['_producto_id']] ?? null;
            $promedio = $fila['_costo_promedio'];
            $costo = $ultima?->costo_unitario !== null ? (string) $ultima->costo_unitario : (Decimal::comparar($promedio, '0', 6) > 0 ? $promedio : null);
            $fila['ultimo_proveedor'] = $ultima?->proveedor;
            $fila['fecha_ultima_compra'] = $ultima?->fecha;
            $fila['costo_estimado'] = $costo;
            $fila['origen_costo'] = $ultima !== null ? 'última compra' : ($costo !== null ? 'costo promedio' : 'sin costo');
            $fila['presupuesto'] = $costo === null ? null : Decimal::redondear(Decimal::multiplicar($fila['a_comprar'], $costo, 6), 2);
            $fila['presupuesto_sugerido'] = $costo === null ? null : Decimal::redondear(Decimal::multiplicar($fila['sugerido_minimo_maximo'], $costo, 6), 2);

            if (Decimal::comparar($fila['a_comprar'], '0') > 0) {
                $resumen['materias_a_comprar']++;
                $resumen['materias_sin_costo'] += $costo === null ? 1 : 0;
                $resumen['compras_sobre_maximo'] += $fila['supera_maximo'] === 'sí' ? 1 : 0;
                $resumen['presupuesto_estimado'] = Decimal::sumar($resumen['presupuesto_estimado'], $fila['presupuesto'] ?? '0', 2);
                $proveedor = $fila['ultimo_proveedor'] ?? 'Sin compras previas';
                $proveedores[$proveedor] ??= ['proveedor' => $proveedor, 'materias' => 0, 'presupuesto' => '0.00'];
                $proveedores[$proveedor]['materias']++;
                $proveedores[$proveedor]['presupuesto'] = Decimal::sumar($proveedores[$proveedor]['presupuesto'], $fila['presupuesto'] ?? '0', 2);
            }

            if (Decimal::comparar($fila['sugerido_minimo_maximo'], '0') > 0) {
                $resumen['materias_bajo_minimo']++;
                $resumen['presupuesto_sugerencia'] = Decimal::sumar($resumen['presupuesto_sugerencia'], $fila['presupuesto_sugerido'] ?? '0', 2);
            }
        }

        unset($fila);
        usort($proveedores, fn (array $a, array $b) => Decimal::comparar($b['presupuesto'], $a['presupuesto'], 2) ?: strcmp($a['proveedor'], $b['proveedor']));

        $metodo = "Emitidas con fecha programada hasta el {$hasta} (atrasadas incluidas)".($incluirPlan ? ' + plan de producción sugerido' : '')
            .'. A comprar = necesidad + mínimo − (saldo − lotes vencidos). Costo de la última compra o costo promedio.';
        $meta = $this->meta($contexto, 'produccion.compra_materia_prima', null, 'estimated', $metodo, [
            'period' => ['from' => $hoy, 'to' => $hasta],
            'note' => 'Presupuesto estimado: el precio real lo fija el proveedor. Las compras no se crean desde aquí.'
                .($incluirPlan ? ' El plan de producción también es una estimación (venta neta de los últimos 28 días).' : ''),
        ]);
        $t = fn (string $c, string $l) => $this->col($c, $l, 'string');
        $n = fn (string $c, string $l) => $this->col($c, $l, 'number');
        $d = fn (string $c, string $l) => $this->col($c, $l, 'money');
        $i = fn (string $c, string $l) => $this->col($c, $l, 'integer');

        return [
            $this->dataset('resumen', [
                $i('ordenes_emitidas', __('Órdenes emitidas')), $i('productos_en_plan', __('Productos del plan')), $i('materias_a_comprar', __('Materias primas a comprar')),
                $d('presupuesto_estimado', __('Presupuesto estimado')), $i('materias_sin_costo', __('Sin costo conocido')), $i('compras_sobre_maximo', __('Compras sobre el máximo')),
                $i('materias_bajo_minimo', __('Bajo el mínimo')), $d('presupuesto_sugerencia', __('Presupuesto de la sugerencia por mínimo y máximo')),
            ], [$resumen], $meta, 'Resumen (una fila). presupuesto_estimado es para las órdenes emitidas'.($incluirPlan ? ' y el plan de producción (productos_en_plan con cifra)' : '')
                .'; presupuesto_sugerencia, para reponer por mínimo y máximo (Producción › Sugerencia de compra). No se suman.'),
            // Acción (§ UC-22): «Ingreso de materia prima» (Compras con ?materia_prima=1). Nunca crea la compra.
            $this->conAcciones($this->dataset('compra', [
                $t('sucursal', __('Sucursal')), $t('materia_prima', __('Materia prima')), $t('unidad', __('Unidad')), $n('necesidad', __('Necesidad de las emitidas')),
                $n('necesidad_plan', __('Necesidad del plan')), $i('ordenes', __('Órdenes')), $n('existencia', __('Stock')), $n('vencido', __('Vencido')),
                $n('utilizable', __('Utilizable')), $n('minimo', __('Mínimo')), $n('maximo', __('Máximo')),
                $n('a_comprar', $incluirPlan ? __('A comprar para las órdenes y el plan') : __('A comprar para las órdenes')), $t('supera_maximo', __('Supera el máximo')),
                $n('sugerido_minimo_maximo', __('Sugerencia por mínimo y máximo')), $t('ultimo_proveedor', __('Último proveedor')),
                $this->col('fecha_ultima_compra', __('Última compra'), 'date'), $d('costo_estimado', __('Costo unitario estimado')), $t('origen_costo', __('Origen del costo')),
                $d('presupuesto', __('Presupuesto estimado')), $d('presupuesto_sugerido', __('Presupuesto de la sugerencia')),
            ], $filas, $meta, 'Por sucursal y materia prima: lo que piden las emitidas'.($incluirPlan ? ' y el plan (necesidad_plan, en la sucursal de producción)' : '')
                .' (a_comprar) y, aparte, la sugerencia por mínimo y máximo. Solo filas con algo que comprar.'
                .($recortado ? ' Recorte: primeras '.self::MAX_FILAS.' filas.' : '')),
                $filas === [] ? [] : [CatalogoAcciones::crear('ingreso_materia_prima', null, __('Ingreso de materia prima'))]),
            $this->dataset('por_proveedor', [
                $t('proveedor', __('Proveedor')), $i('materias', __('Materias primas')), $d('presupuesto', __('Presupuesto estimado')),
            ], $proveedores, $meta, 'Presupuesto para las órdenes por último proveedor de cada materia prima (sin compras previas: nunca se compró).'),
        ];
    }

    /**
     * Fila de una materia prima en una sucursal; null si no hay nada que comprar.
     *
     * @param  array<int, array<int, array{cantidad: string, ordenes: int}>>  $necesidad
     * @param  array<int, array<int, array{cantidad: string, productos: int}>>  $plan
     * @param  array<int, array<int, array{existencia: string, vencido: string, utilizable: string}>>  $stock
     * @return array<string, mixed>|null
     */
    private function fila(stdClass $materia, array $necesidad, array $plan, array $stock): ?array
    {
        $sucursalId = (int) $materia->sucursal_id;
        $productoId = (int) $materia->id;
        $existencia = (string) $materia->existencia;
        $minimo = (string) $materia->minimo;
        $maximo = $materia->stock_maximo === null ? null : (string) $materia->stock_maximo;
        $pedido = $necesidad[$sucursalId][$productoId] ?? ['cantidad' => '0', 'ordenes' => 0];
        $delPlan = $plan[$sucursalId][$productoId]['cantidad'] ?? '0.000';
        $total = Decimal::sumar($pedido['cantidad'], $delPlan);
        $utilizable = $stock[$sucursalId][$productoId]['utilizable'] ?? $existencia;
        $aComprar = '0.000';

        if (Decimal::comparar($total, '0') > 0) {
            $falta = Decimal::restar(Decimal::sumar($total, $minimo), $utilizable);
            $aComprar = Decimal::comparar($falta, '0') > 0 ? $falta : '0.000';
        }

        // La sugerencia de la pantalla: solo materias activas, con el saldo de la sucursal (§ 4.3).
        $sugerido = (bool) $materia->activo ? (ListadoMateriasPrimas::sugerido($existencia, $minimo, $maximo) ?? '0.000') : '0.000';

        if (Decimal::comparar($aComprar, '0') <= 0 && Decimal::comparar($sugerido, '0') <= 0) {
            return null;
        }

        $sobreMaximo = $maximo !== null && Decimal::comparar($aComprar, '0') > 0 && Decimal::comparar(Decimal::sumar($existencia, $aComprar), $maximo) > 0;

        return [
            'sucursal' => $materia->sucursal, 'materia_prima' => $materia->nombre, 'unidad' => $materia->unidad,
            'necesidad' => $pedido['cantidad'], 'necesidad_plan' => $delPlan, 'ordenes' => $pedido['ordenes'], 'existencia' => $existencia,
            'vencido' => $stock[$sucursalId][$productoId]['vencido'] ?? '0', 'utilizable' => $utilizable, 'minimo' => $minimo, 'maximo' => $maximo,
            'a_comprar' => $aComprar, 'supera_maximo' => $sobreMaximo ? 'sí' : 'no', 'sugerido_minimo_maximo' => $sugerido,
            '_producto_id' => $productoId, '_costo_promedio' => (string) $materia->costo_promedio,
        ];
    }

    /**
     * Materia prima del plan de producción (UC-21) para el mismo horizonte: lo sugerido de cada producto con receta
     * activa se escala como una orden (Escalado: enteras hacia arriba) en su sucursal de producción. Las cifras
     * orientativas (historial insuficiente) no entran. Una consulta más que el plan.
     *
     * @return array{necesidad: array<int, array<int, array{cantidad: string, productos: int}>>, productos: int}
     */
    private function necesidadPlan(ContextoAgente $contexto, int $dias): array
    {
        $sugeridos = array_values(array_filter(app(SugerirPlanProduccion::class)->calcular($contexto, $dias)['plan'],
            fn (array $f): bool => $f['sugerido'] !== null && Decimal::comparar((string) $f['sugerido'], '0') > 0 && $f['_receta_activa'] && $f['_sucursal_id'] > 0));

        if ($sugeridos === []) {
            return ['necesidad' => [], 'productos' => 0];
        }

        $lineas = DB::table('receta_items as i')
            ->join('productos as m', fn (JoinClause $j) => $j->on('m.id', '=', 'i.producto_id')->on('m.empresa_id', '=', 'i.empresa_id'))
            ->join('unidades_medida as mu', 'mu.id', '=', 'm.unidad_medida_id')
            ->where('i.empresa_id', $contexto->empresaId)
            ->whereIn('i.receta_id', array_column($sugeridos, '_receta_id'))
            ->orderBy('i.receta_id')->orderBy('i.orden')
            ->get(['i.receta_id', 'i.producto_id', 'i.cantidad', 'm.tipo_venta', 'mu.admite_decimales'])
            ->groupBy(fn (stdClass $l): int => (int) $l->receta_id);
        $necesidad = [];

        foreach ($sugeridos as $fila) {
            $sucursalId = (int) $fila['_sucursal_id'];

            foreach ($lineas->get((int) $fila['_receta_id'], collect()) as $linea) {
                $entera = Escalado::esEntera($linea->tipo_venta === null ? null : (string) $linea->tipo_venta, (bool) $linea->admite_decimales);
                $teorica = Escalado::teorica((string) $fila['sugerido'], (string) $fila['_cantidad_base'], (string) $linea->cantidad, $entera);
                $actual = $necesidad[$sucursalId][(int) $linea->producto_id] ?? ['cantidad' => '0.000', 'productos' => 0];
                $necesidad[$sucursalId][(int) $linea->producto_id] = ['cantidad' => Decimal::sumar($actual['cantidad'], $teorica), 'productos' => $actual['productos'] + 1];
            }
        }

        return ['necesidad' => $necesidad, 'productos' => count($sugeridos)];
    }

    /**
     * Última compra de cada materia prima (proveedor, costo unitario y día local): una consulta. Las compras no se
     * anulan, así que la de mayor id es la más reciente.
     *
     * @param  list<int>  $productos
     * @return array<int, stdClass>
     */
    private function ultimasCompras(ContextoAgente $contexto, array $productos): array
    {
        if ($productos === []) {
            return [];
        }

        $empresaId = $contexto->empresaId;

        return DB::table('compra_items as ci')
            ->join('compras as c', fn (JoinClause $j) => $j->on('c.id', '=', 'ci.compra_id')->on('c.empresa_id', '=', 'ci.empresa_id'))
            ->leftJoin('proveedores as pr', fn (JoinClause $j) => $j->on('pr.id', '=', 'c.proveedor_id')->on('pr.empresa_id', '=', 'c.empresa_id'))
            ->where('ci.empresa_id', $empresaId)
            ->whereIn('ci.id', DB::table('compra_items')->where('empresa_id', $empresaId)->whereIn('producto_id', $productos)
                ->groupBy('producto_id')->selectRaw('MAX(id)'))
            ->select(['ci.producto_id', 'ci.costo_unitario', 'pr.nombre as proveedor'])
            ->selectRaw('DATE('.$this->local('c.fecha').') AS fecha', [$this->desfase($contexto)])
            ->get()
            ->keyBy(fn (stdClass $f): int => (int) $f->producto_id)
            ->all();
    }
}
