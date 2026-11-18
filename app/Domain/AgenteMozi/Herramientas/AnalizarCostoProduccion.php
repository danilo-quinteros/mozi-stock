<?php

namespace App\Domain\AgenteMozi\Herramientas;

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\Produccion\Consultas\ReporteProduccion;
use App\Domain\Reportes\Periodo;
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
 * analyze_production_cost (UC-24, AGENTE_MOZI_IA.md § 2.1): costo de materiales de un producto fabricado y su evolución.
 * Usa las consultas de Reportes de producción (órdenes finalizadas por fecha de finalización), así el costo unitario de
 * cada orden es el de su detalle (6 decimales, guardado al finalizar). Por materia prima: peso en el costo y cambio por
 * unidad producida entre la primera y la segunda mitad del período, separado en precio (costo guardado al aprobar) y
 * cantidad usada por unidad:
 *
 *     cambio = q2·p2 − q1·p1 = (p2 − p1)·q2 [precio] + (q2 − q1)·p1 [cantidad]
 *
 * Costo de la receta hoy = Σ cantidad de la línea × costo promedio actual ÷ cantidad base (sin merma). simular
 * recalcula ese costo con variaciones porcentuales de hasta 5 materias primas de la receta: es una simulación, no guarda
 * nada ni cambia costo_promedio. Siempre «costo de materiales»: no incluye mano de obra, energía ni gastos indirectos.
 */
final class AnalizarCostoProduccion extends HerramientaProduccion
{
    private const DIAS = 90;

    private const MAX_ORDENES = 200;

    private const MAX_COMPRAS = 100;

    private const MAX_SIMULACIONES = 5;

    public function nombre(): string
    {
        return 'analyze_production_cost';
    }

    public function descripcion(): string
    {
        return 'Costo de materiales de un producto fabricado (materias primas a costo promedio; no incluye mano de obra, energía ni gastos indirectos), por defecto en los últimos '.self::DIAS.' días: '
            .'costo unitario de cada orden finalizada (el de su detalle), peso de cada materia prima, cambio por unidad entre la primera y la segunda mitad del período separado en precio y cantidad usada, '
            .'compras de esas materias primas en el período y costo de la receta hoy (sin merma) frente al último costo real. '
            .'simular: hasta '.self::MAX_SIMULACIONES.' materias primas de la receta con su variación en % (p. ej. glicerina +10): recalcula el costo de la receta como simulación, sin guardar nada.';
    }

    public function parametros(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'producto' => ['type' => 'string', 'description' => 'Producto fabricado (nombre o código). Obligatorio.'],
                ...$this->propiedadesPeriodo('hace '.self::DIAS.' días'),
                'simular' => [
                    'type' => 'array',
                    'maxItems' => self::MAX_SIMULACIONES,
                    'description' => 'Variaciones de costo que pidió el usuario, solo para simular.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'materia_prima' => ['type' => 'string', 'description' => 'Materia prima de la receta (nombre).'],
                            'variacion_pct' => ['type' => 'string', 'description' => 'Variación del costo en %, como texto: "10", "-5" o "12.5".'],
                        ],
                        'required' => ['materia_prima', 'variacion_pct'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['producto'],
            'additionalProperties' => false,
        ];
    }

    public function reglas(): array
    {
        return $this->reglasPeriodo() + [
            'producto' => ['required', 'string', 'max:120'],
            'simular' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_SIMULACIONES],
            'simular.*' => ['array:materia_prima,variacion_pct'],
            'simular.*.materia_prima' => ['required', 'string', 'max:120'],
            'simular.*.variacion_pct' => ['required', 'regex:/^-?\d{1,3}(\.\d{1,2})?$/'],
        ];
    }

    public function avance(): string
    {
        return __('Analizando el costo de producción…');
    }

    public function ejecutar(ContextoAgente $contexto, array $argumentos): array
    {
        $empresaId = $contexto->empresaId;
        $conReceta = fn (Builder $q) => $q->whereExists(fn (Builder $r) => $r->selectRaw('1')->from('recetas as rr')
            ->whereColumn('rr.empresa_id', 'p.empresa_id')->whereColumn('rr.producto_id', 'p.id'));
        $producto = $this->producto($contexto, $argumentos['producto'] ?? null, Producto::CLASE_VENTA, 'un producto con receta', $conReceta)
            ?? throw new ArgumentoInvalido('producto es obligatorio: pregunta qué producto fabricado quiere analizar.');
        $periodo = $this->periodo($contexto, $argumentos, self::DIAS);
        $delProducto = fn (Builder $q) => $q->where('o.producto_id', $producto->id);

        $ordenes = $delProducto(ReporteProduccion::porOrden($empresaId, $periodo))->limit(self::MAX_ORDENES + 1)->get();
        $total = $delProducto(ReporteProduccion::porProducto($empresaId, $periodo))->first();
        $materias = $delProducto(ReporteProduccion::materias($empresaId, $periodo))->get();
        [$mitad1, $mitad2] = $this->mitades($periodo);
        $partes = [$this->parte($empresaId, $mitad1, $delProducto), $this->parte($empresaId, $mitad2, $delProducto)];
        $receta = $this->receta($contexto, (int) $producto->id, $argumentos['simular'] ?? []);

        $costoMaterias = $materias->reduce(fn (string $s, stdClass $m) => Decimal::sumar($s, (string) $m->costo, 6), '0');
        $filasMaterias = $materias->map(fn (stdClass $m) => $this->materia($m, $costoMaterias, $partes))->values()->all();
        $filasOrdenes = $ordenes->take(self::MAX_ORDENES)->reverse()->map(fn (stdClass $o) => [
            'orden' => OrdenProduccion::formatearCodigo((int) $o->numero),
            'fecha' => CarbonImmutable::parse((string) $o->finalizada_en, 'UTC')->setTimezone($contexto->zonaHoraria)->format('Y-m-d'),
            'sucursal' => $o->sucursal, 'obtenido' => (string) $o->obtenida, 'unidad' => $o->unidad, 'costo_total' => (string) $o->costo_total,
            'costo_unitario' => $o->costo_unitario === null ? null : (string) $o->costo_unitario,
            'costo_unitario_exacto' => $o->costo_unitario === null ? null : Decimal::redondear((string) $o->costo_unitario, 6),
        ])->values()->all();
        $ultima = $ordenes->first();
        $ultimoCosto = $ultima?->costo_unitario === null ? null : Decimal::redondear((string) $ultima->costo_unitario, 6);
        $obtenido = (string) ($total->obtenida ?? '0');
        $costoTotal = (string) ($total->costo_total ?? '0');
        $costoPeriodo = ReporteProduccion::costoUnitario($costoTotal, $obtenido);

        $resumen = [
            'producto' => $producto->nombre, 'ordenes' => (int) ($total->ordenes ?? 0), 'producido' => $obtenido, 'costo_total' => $costoTotal,
            'costo_unitario_periodo' => $costoPeriodo, 'ultimo_costo_unitario' => $ultimoCosto, 'ultimo_costo_unitario_exacto' => $ultimoCosto,
            'costo_receta_hoy' => $receta['costo'], 'costo_receta_hoy_exacto' => $receta['costo'],
            'diferencia_receta_vs_ultimo' => $ultimoCosto === null || $receta['costo'] === null ? null : Decimal::restar($receta['costo'], $ultimoCosto, 6),
            'materias_sin_costo' => $receta['sin_costo'], 'costo_receta_simulado' => $receta['simulado'],
            'variacion_simulada_pct' => $receta['simulado'] === null || $receta['costo'] === null || Decimal::comparar($receta['costo'], '0', 6) <= 0 ? null
                : Decimal::redondear(Decimal::dividir(Decimal::multiplicar(Decimal::restar($receta['simulado'], $receta['costo'], 6), '100', 6), $receta['costo'], 6), 1),
        ];

        $metodo = 'Costo de materiales: órdenes finalizadas por fecha de finalización (Reportes de producción); materias primas a costo guardado al aprobar. Receta hoy: costo promedio actual, sin merma.';
        $meta = $this->meta($contexto, 'produccion.costo', $periodo, metodo: $metodo, extra: [
            'note' => 'Costo de materiales: no incluye mano de obra, energía ni gastos indirectos. No es costo total ni utilidad.',
        ]);
        $metaSimulacion = $receta['simulado'] === null ? $meta : $this->meta($contexto, 'produccion.costo', $periodo, 'estimated', $metodo, [
            'note' => 'Simulación con las variaciones indicadas: no cambia el costo promedio ni se guarda. Costo de materiales.',
        ]);
        $t = fn (string $c, string $l) => $this->col($c, $l, 'string');
        $n = fn (string $c, string $l) => $this->col($c, $l, 'number');
        $d = fn (string $c, string $l) => $this->col($c, $l, 'money');
        $p = fn (string $c, string $l) => $this->col($c, $l, 'percent');

        return [
            $this->dataset('resumen', [
                $t('producto', __('Producto')), $this->col('ordenes', __('Órdenes finalizadas'), 'integer'), $n('producido', __('Producido')),
                $d('costo_total', __('Costo de materiales total')), $d('costo_unitario_periodo', __('Costo de materiales por unidad en el período')),
                $d('ultimo_costo_unitario', __('Último costo de materiales por unidad')), $t('ultimo_costo_unitario_exacto', __('Último costo por unidad (6 decimales)')),
                $d('costo_receta_hoy', __('Costo de la receta hoy por unidad')), $t('costo_receta_hoy_exacto', __('Costo de la receta hoy (6 decimales)')),
                $d('diferencia_receta_vs_ultimo', __('Receta hoy − último costo real')), $this->col('materias_sin_costo', __('Materias primas sin costo'), 'integer'),
                $d('costo_receta_simulado', __('Costo de la receta simulado')), $p('variacion_simulada_pct', __('Variación simulada %')),
            ], [$resumen], $metaSimulacion, 'Resumen (una fila), todo en costo de materiales. costo_receta_hoy = receta vigente × costo promedio actual ÷ cantidad base (sin merma). '
                .'materias_sin_costo > 0: la receta tiene materias primas con costo 0 y su costo de hoy está incompleto. costo_receta_simulado solo si se pidió simular.'),
            $this->dataset('ordenes', [
                $t('orden', __('Orden')), $this->col('fecha', __('Finalizada'), 'date'), $t('sucursal', __('Sucursal')), $n('obtenido', __('Obtenido')), $t('unidad', __('Unidad')),
                $d('costo_total', __('Costo de materiales')), $d('costo_unitario', __('Costo de materiales por unidad')), $t('costo_unitario_exacto', __('Costo por unidad (6 decimales)')),
            ], $filasOrdenes, $meta, 'Serie del costo de materiales por unidad de cada orden finalizada, de la más antigua a la más reciente (el costo unitario es el de su detalle).'
                .($ordenes->count() > self::MAX_ORDENES ? ' Recorte: las '.self::MAX_ORDENES.' más recientes.' : '')),
            $this->dataset('materias', [
                $t('materia_prima', __('Materia prima')), $t('unidad', __('Unidad')), $n('usada', __('Usada')), $d('costo', __('Costo de materiales')), $p('peso_pct', __('Peso en el costo %')),
                $n('cantidad_por_unidad_1', __('Cantidad por unidad, 1.ª mitad')), $n('cantidad_por_unidad_2', __('Cantidad por unidad, 2.ª mitad')),
                $d('costo_unitario_1', __('Costo de la materia prima, 1.ª mitad')), $d('costo_unitario_2', __('Costo de la materia prima, 2.ª mitad')),
                $d('costo_por_unidad_1', __('Costo por unidad producida, 1.ª mitad')), $d('costo_por_unidad_2', __('Costo por unidad producida, 2.ª mitad')),
                $d('cambio_por_unidad', __('Cambio por unidad producida')), $d('efecto_precio', __('Efecto del precio')), $d('efecto_cantidad', __('Efecto de la cantidad usada')),
            ], $filasMaterias, $meta, 'Materias primas consumidas en el período. Mitades: '.$mitad1->desde->format('Y-m-d').' a '.$mitad1->hasta->format('Y-m-d').' y '
                .$mitad2->desde->format('Y-m-d').' a '.$mitad2->hasta->format('Y-m-d').'. cambio = efecto_precio + efecto_cantidad; vacío si una mitad no tiene órdenes.'),
            $this->dataset('receta', [
                $t('materia_prima', __('Materia prima')), $n('cantidad', __('Cantidad en la receta')), $t('unidad', __('Unidad')), $n('cantidad_base', __('Cantidad base')),
                $t('costo_promedio', __('Costo promedio actual')), $d('costo_por_unidad', __('Costo por unidad producida')), $p('peso_pct', __('Peso %')),
                $p('variacion_pct', __('Variación simulada %')), $d('costo_por_unidad_simulado', __('Costo por unidad simulado')), $t('estado', __('Estado')),
            ], $receta['lineas'], $metaSimulacion, 'Receta vigente con el costo promedio actual (6 decimales). Las columnas simuladas solo si se pidió simular: no son reales.'),
            $this->dataset('compras', [
                $this->col('fecha', __('Fecha'), 'date'), $t('materia_prima', __('Materia prima')), $t('proveedor', __('Proveedor')), $n('cantidad', __('Cantidad')),
                $d('costo_unitario', __('Costo unitario de la compra')), $t('costo_promedio_actual', __('Costo promedio actual')),
            ], $this->compras($contexto, $periodo, array_values(array_unique(array_merge($receta['materias'], $materias->pluck('id')->map(fn ($id): int => (int) $id)->all())))),
                $meta, 'Compras del período de las materias primas de la receta: cada una mueve el costo promedio. De la más reciente a la más antigua (hasta '.self::MAX_COMPRAS.').'),
        ];
    }

    /**
     * Las dos mitades del período (la primera se lleva el día sobrante); un período de un día no se parte.
     *
     * @return array{0: Periodo, 1: Periodo}
     */
    private function mitades(Periodo $periodo): array
    {
        $dias = (int) $periodo->desde->diffInDays($periodo->hasta) + 1;

        if ($dias < 2) {
            return [$periodo, $periodo];
        }

        $corte = $periodo->desde->addDays(intdiv($dias + 1, 2));

        return [Periodo::rango($periodo->desde->format('Y-m-d'), $corte->subDay()->format('Y-m-d')), Periodo::rango($corte->format('Y-m-d'), $periodo->hasta->format('Y-m-d'))];
    }

    /**
     * Lo obtenido y el consumo de cada materia prima en una mitad.
     *
     * @param  callable(Builder): Builder  $delProducto
     * @return array{obtenido: string, materias: Collection<int, stdClass>}
     */
    private function parte(int $empresaId, Periodo $periodo, callable $delProducto): array
    {
        $total = $delProducto(ReporteProduccion::porProducto($empresaId, $periodo))->first();

        return [
            'obtenido' => (string) ($total->obtenida ?? '0'),
            'materias' => $delProducto(ReporteProduccion::materias($empresaId, $periodo))->get()->keyBy(fn (stdClass $m): int => (int) $m->id),
        ];
    }

    /**
     * @param  array{0: array{obtenido: string, materias: Collection<int, stdClass>}, 1: array{obtenido: string, materias: Collection<int, stdClass>}}  $partes
     * @return array<string, mixed>
     */
    private function materia(stdClass $m, string $costoTotal, array $partes): array
    {
        $calcular = function (array $parte) use ($m): ?array {
            $fila = $parte['materias']->get((int) $m->id);

            if ($fila === null || Decimal::comparar($parte['obtenido'], '0') <= 0 || Decimal::comparar((string) $fila->usada, '0') <= 0) {
                return null;
            }

            return [
                'cantidad' => Decimal::dividir((string) $fila->usada, $parte['obtenido'], 6),
                'precio' => Decimal::dividir((string) $fila->costo, (string) $fila->usada, 6),
                'por_unidad' => Decimal::dividir((string) $fila->costo, $parte['obtenido'], 6),
            ];
        };
        [$a, $b] = [$calcular($partes[0]), $calcular($partes[1])];
        $comparable = $a !== null && $b !== null;

        return [
            'materia_prima' => $m->nombre, 'unidad' => $m->unidad, 'usada' => (string) $m->usada, 'costo' => (string) $m->costo,
            'peso_pct' => $this->peso((string) $m->costo, $costoTotal),
            'cantidad_por_unidad_1' => $a['cantidad'] ?? null, 'cantidad_por_unidad_2' => $b['cantidad'] ?? null,
            'costo_unitario_1' => $a['precio'] ?? null, 'costo_unitario_2' => $b['precio'] ?? null,
            'costo_por_unidad_1' => $a['por_unidad'] ?? null, 'costo_por_unidad_2' => $b['por_unidad'] ?? null,
            'cambio_por_unidad' => $comparable ? Decimal::restar($b['por_unidad'], $a['por_unidad'], 6) : null,
            'efecto_precio' => $comparable ? Decimal::multiplicar(Decimal::restar($b['precio'], $a['precio'], 6), $b['cantidad'], 6) : null,
            'efecto_cantidad' => $comparable ? Decimal::multiplicar(Decimal::restar($b['cantidad'], $a['cantidad'], 6), $a['precio'], 6) : null,
        ];
    }

    /**
     * Receta vigente con el costo promedio actual y, si se pidió, la simulación. Valida que cada materia simulada esté
     * en la receta (por nombre completo o parte única del nombre).
     *
     * @return array{costo: string|null, simulado: string|null, sin_costo: int, materias: list<int>, lineas: list<array<string, mixed>>}
     */
    private function receta(ContextoAgente $contexto, int $productoId, mixed $simular): array
    {
        $receta = DB::table('recetas')->where('empresa_id', $contexto->empresaId)->where('producto_vigente', $productoId)->first(['id', 'cantidad_base', 'activa']);

        if ($receta === null) {
            return ['costo' => null, 'simulado' => null, 'sin_costo' => 0, 'materias' => [], 'lineas' => []];
        }

        $lineas = DB::table('receta_items as i')
            ->join('productos as m', fn (JoinClause $j) => $j->on('m.id', '=', 'i.producto_id')->on('m.empresa_id', '=', 'i.empresa_id'))
            ->join('unidades_medida as mu', 'mu.id', '=', 'm.unidad_medida_id')
            ->where('i.empresa_id', $contexto->empresaId)
            ->where('i.receta_id', $receta->id)
            ->orderBy('i.orden')
            ->get(['i.producto_id', 'i.cantidad', 'm.costo_promedio', 'm.nombre as base', 'mu.nombre as unidad', DB::raw(Producto::sqlNombreCompleto('m', 'mu').' AS nombre')]);
        $variaciones = $this->variaciones($lineas, is_array($simular) ? $simular : []);
        $base = (string) $receta->cantidad_base;
        $costo = '0';
        $simulado = '0';
        $sinCosto = 0;
        $filas = [];

        foreach ($lineas as $linea) {
            $id = (int) $linea->producto_id;
            $promedio = (string) $linea->costo_promedio;
            $porUnidad = Decimal::dividir(Decimal::multiplicar((string) $linea->cantidad, $promedio, 9), $base, 6);
            $variacion = $variaciones[$id] ?? null;
            $porUnidadSimulado = $variacion === null ? $porUnidad
                : Decimal::dividir(Decimal::multiplicar($porUnidad, Decimal::sumar('100', $variacion, 2), 8), '100', 6);
            $costo = Decimal::sumar($costo, $porUnidad, 6);
            $simulado = Decimal::sumar($simulado, $porUnidadSimulado, 6);
            $sinCosto += Decimal::comparar($promedio, '0', 6) <= 0 ? 1 : 0;
            $filas[] = [
                'materia_prima' => $linea->nombre, 'cantidad' => (string) $linea->cantidad, 'unidad' => $linea->unidad, 'cantidad_base' => $base,
                'costo_promedio' => Decimal::redondear($promedio, 6), 'costo_por_unidad' => $porUnidad, 'peso_pct' => null,
                'variacion_pct' => $variacion, 'costo_por_unidad_simulado' => $variacion === null ? null : $porUnidadSimulado,
                'estado' => Decimal::comparar($promedio, '0', 6) <= 0 ? 'sin costo (nunca comprada)' : ((bool) $receta->activa ? 'vigente' : 'receta desactivada'),
            ];
        }

        foreach ($filas as &$fila) {
            $fila['peso_pct'] = $this->peso($fila['costo_por_unidad'], $costo);
        }

        unset($fila);

        return [
            'costo' => $costo, 'simulado' => $variaciones === [] ? null : $simulado, 'sin_costo' => $sinCosto,
            'materias' => array_values($lineas->pluck('producto_id')->map(fn ($id): int => (int) $id)->all()), 'lineas' => $filas,
        ];
    }

    /**
     * @param  Collection<int, stdClass>  $lineas
     * @param  array<mixed>  $simular
     * @return array<int, string> producto_id => variación %
     */
    private function variaciones(Collection $lineas, array $simular): array
    {
        $variaciones = [];

        foreach ($simular as $pedido) {
            $texto = is_array($pedido) && is_string($pedido['materia_prima'] ?? null) ? mb_strtolower(trim($pedido['materia_prima'])) : '';
            $porcentaje = is_array($pedido) && is_scalar($pedido['variacion_pct'] ?? null) ? Decimal::normalizar((string) $pedido['variacion_pct'], 2) : null;

            if ($porcentaje === null || Decimal::comparar($porcentaje, '-100', 2) <= 0) {
                throw new ArgumentoInvalido('variacion_pct debe ser un porcentaje mayor que -100, por ejemplo "10" o "-5".');
            }

            $exactas = $lineas->filter(fn (stdClass $l) => mb_strtolower((string) $l->nombre) === $texto || mb_strtolower((string) $l->base) === $texto);
            $parecidas = $exactas->count() === 1 ? $exactas : $lineas->filter(fn (stdClass $l) => $texto !== '' && str_contains(mb_strtolower((string) $l->nombre), $texto));

            if ($parecidas->count() !== 1) {
                throw new ArgumentoInvalido(($parecidas->isEmpty() ? "«{$texto}» no está en la receta vigente." : "«{$texto}» coincide con varias materias primas de la receta.")
                    .' Materias primas de la receta: '.$lineas->pluck('nombre')->implode(', ').'.');
            }

            $variaciones[(int) $parecidas->first()->producto_id] = $porcentaje;
        }

        return $variaciones;
    }

    /** Parte de un total en porcentaje con 1 decimal, con 6 decimales de precisión (costos por unidad pequeños). */
    private function peso(string $parte, string $total): ?string
    {
        return Decimal::comparar($total, '0', 6) <= 0 ? null
            : Decimal::redondear(Decimal::dividir(Decimal::multiplicar($parte, '100', 6), $total, 6), 1);
    }

    /**
     * Compras del período de las materias primas indicadas, de la más reciente a la más antigua.
     *
     * @param  list<int>  $materias
     * @return list<array<string, mixed>>
     */
    private function compras(ContextoAgente $contexto, Periodo $periodo, array $materias): array
    {
        if ($materias === []) {
            return [];
        }

        [$inicio, $fin] = $periodo->limitesUtc();

        return array_values(DB::table('compra_items as ci')
            ->join('compras as c', fn (JoinClause $j) => $j->on('c.id', '=', 'ci.compra_id')->on('c.empresa_id', '=', 'ci.empresa_id'))
            ->join('productos as m', fn (JoinClause $j) => $j->on('m.id', '=', 'ci.producto_id')->on('m.empresa_id', '=', 'ci.empresa_id'))
            ->join('unidades_medida as mu', 'mu.id', '=', 'm.unidad_medida_id')
            ->leftJoin('proveedores as pr', fn (JoinClause $j) => $j->on('pr.id', '=', 'c.proveedor_id')->on('pr.empresa_id', '=', 'c.empresa_id'))
            ->where('ci.empresa_id', $contexto->empresaId)
            ->whereIn('ci.producto_id', $materias)
            ->where('c.fecha', '>=', $inicio)
            ->where('c.fecha', '<', $fin)
            ->orderByDesc('c.fecha')->orderByDesc('ci.id')
            ->limit(self::MAX_COMPRAS)
            ->select(['ci.cantidad', 'ci.costo_unitario', 'm.costo_promedio', 'pr.nombre as proveedor', DB::raw(Producto::sqlNombreCompleto('m', 'mu').' AS materia_prima')])
            ->selectRaw('DATE('.$this->local('c.fecha').') AS fecha', [$this->desfase($contexto)])
            ->get()
            ->map(fn (stdClass $c): array => [
                'fecha' => $c->fecha, 'materia_prima' => $c->materia_prima, 'proveedor' => $c->proveedor, 'cantidad' => (string) $c->cantidad,
                'costo_unitario' => (string) $c->costo_unitario, 'costo_promedio_actual' => Decimal::redondear((string) $c->costo_promedio, 6),
            ])->all());
    }
}
