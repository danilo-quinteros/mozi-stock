<?php

namespace App\Domain\AgenteMozi\Herramientas;

use App\Domain\AgenteMozi\Acciones\CatalogoAcciones;
use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\DTOs\DatasetPropuesto;
use App\Domain\Produccion\Escalado;
use App\Domain\Reportes\Periodo;
use App\Enums\EstadoOrdenProduccion;
use App\Models\Producto;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * suggest_production_plan (UC-21, AGENTE_MOZI_IA.md § 2.1 y § 8): cuánto producir de cada producto con receta vigente
 * para los próximos horizon_days. Familia = el granel (el producto de la receta) y sus presentaciones activas con stock.
 * Ventana de demanda: los últimos 28 días (IA-P1), en sucursales activas.
 * - Demanda diaria en unidades del granel = Σ por sucursal y artículo de la familia de venta neta (ventas − devoluciones,
 *   nunca negativa) × granel por unidad ÷ días con stock. Un día sin stock en toda la jornada no cuenta: no es falta de
 *   demanda (§ 8). Los días con stock se reconstruyen del libro movimientos_stock con una consulta agrupada por día: el
 *   saldo al abrir la ventana es la existencia actual − Σ movimientos de la ventana, y un día cuenta si el saldo antes o
 *   después de algún movimiento, o el que venía, fue positivo.
 * - Posición = granel + presentaciones × granel por unidad (utilizables: sin lotes vencidos) + lo planificado en órdenes
 *   emitidas o aprobadas. Sugerido = max(0, demanda × horizonte − posición), al entero hacia arriba si el granel va en
 *   unidades enteras. La IA no ajusta la cifra.
 * - Historial = días de la ventana desde que existe el artículo más antiguo de la familia. Con menos de 14 la cifra va
 *   solo en sugerido_orientativo (baja confianza); sin ventas netas no hay cifra. Días sin stock bajan la confianza.
 * Sucursal de producción: la de la última orden no anulada del producto; si no, la de la última de la empresa; si nunca
 * se produjo, la primera sucursal activa. calcular() también la usa UC-22 (incluir_plan). Solo lectura.
 * Contraste histórico (contrastar=true, IA-P1): en el corte hoy − horizonte − 1 estima con 28 y con 14 días y compara
 * con la venta neta real de los días completos siguientes (hasta ayer); error por producto y ponderado. No cambia el plan.
 */
final class SugerirPlanProduccion extends HerramientaProduccion
{
    private const HORIZONTE = 14;

    /** § 8: con menos días de historial la cifra solo es orientativa. */
    private const HISTORIAL_MINIMO = 14;

    private const MAX_PRODUCTOS = 100;

    private const MAX_DETALLE = 300;

    public function nombre(): string
    {
        return 'suggest_production_plan';
    }

    public function descripcion(): string
    {
        return 'Plan de producción sugerido (estimación): cuánto producir de cada producto con receta vigente para los próximos horizon_days (por defecto '.self::HORIZONTE.'). '
            .'Demanda diaria en unidades del granel = venta neta de los últimos '.self::DIAS_DEMANDA.' días del granel y de sus presentaciones (× granel por unidad) ÷ días con stock de cada sucursal y artículo: los días sin stock no cuentan como falta de demanda. '
            .'Posición = granel y presentaciones utilizables + lo planificado en órdenes emitidas o aprobadas. sugerido = max(0, demanda × horizonte − posición): no ajustes la cifra. '
            .'Con menos de '.self::HISTORIAL_MINIMO.' días de historial sugerido queda vacío y sugerido_orientativo da una cifra de baja confianza; sin ventas no hay cifra. confianza y motivo explican cada caso. '
            .'Con contrastar=true añade el contraste histórico: lo que el método habría estimado en un corte pasado (con 28 y con 14 días) frente a la venta real. Nunca crea órdenes.';
    }

    public function parametros(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'horizon_days' => ['type' => 'integer', 'minimum' => 7, 'maximum' => 60, 'description' => 'Días a cubrir. Por defecto '.self::HORIZONTE.'.'],
                'producto' => ['type' => 'string', 'description' => 'Producto con receta (nombre o código). Por defecto todos los activos con receta vigente.'],
                'contrastar' => ['type' => 'boolean', 'description' => 'true para añadir el contraste histórico: qué habría estimado este método en un corte pasado (hace horizon_days + 1 días) con 28 y con 14 días de ventas, frente a la venta neta real de los días siguientes. Úsalo si preguntan qué tan confiable o preciso es el plan, o si convendría otra ventana.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function reglas(): array
    {
        return [
            'horizon_days' => ['sometimes', 'nullable', 'integer', 'min:7', 'max:60'],
            'producto' => ['sometimes', 'nullable', 'string', 'max:120'],
            'contrastar' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    public function avance(): string
    {
        return __('Calculando el plan de producción…');
    }

    public function ejecutar(ContextoAgente $contexto, array $argumentos): array
    {
        $dias = (int) ($argumentos['horizon_days'] ?? self::HORIZONTE);
        $conReceta = fn (Builder $q) => $q->where('p.controla_stock', true)->whereExists(fn (Builder $r) => $r->selectRaw('1')->from('recetas as rv')
            ->whereColumn('rv.empresa_id', 'p.empresa_id')->whereColumn('rv.producto_vigente', 'p.id'));
        $producto = $this->producto($contexto, $argumentos['producto'] ?? null, Producto::CLASE_VENTA, 'un producto con receta que controle stock', $conReceta);
        $plan = $this->calcular($contexto, $dias, $producto === null ? null : (int) $producto->id);
        $conCifra = array_filter($plan['plan'], fn (array $f): bool => $f['sugerido'] !== null);

        $metodo = "Demanda = venta neta del {$plan['desde']} al {$plan['hasta']} (granel + presentaciones × granel por unidad) ÷ días con stock. "
            ."Posición = utilizable + órdenes emitidas y aprobadas. Sugerido = max(0, demanda × {$dias} − posición).";
        $meta = $this->meta($contexto, 'produccion.plan', null, $conCifra === [] ? 'insufficient' : 'estimated', $metodo, [
            'period' => ['from' => $plan['desde'], 'to' => $plan['hasta']],
            'note' => 'Estimación con '.self::DIAS_DEMANDA." días de ventas (ventana observada del {$plan['desde']} al {$plan['hasta']}); horizonte de {$dias} días. La cifra no se ajusta y las órdenes no se crean desde aquí.",
        ]);
        $t = fn (string $c, string $l) => $this->col($c, $l, 'string');
        $n = fn (string $c, string $l) => $this->col($c, $l, 'number');
        $i = fn (string $c, string $l) => $this->col($c, $l, 'integer');

        return [
            // Acción (§ UC-21): «Nueva orden» solo si algún producto tiene producción sugerida.
            $this->conAcciones($this->dataset('plan', [
                $t('producto', __('Producto')), $t('unidad', __('Unidad')), $i('historial_dias', __('Días de historial')), $n('vendido_granel', __('Venta neta en granel')),
                $n('demanda_diaria', __('Demanda diaria estimada')), $n('stock_granel', __('Granel utilizable')), $n('stock_presentaciones', __('Presentaciones en granel')),
                $n('en_ordenes', __('En órdenes emitidas y aprobadas')), $i('ordenes', __('Órdenes')), $n('posicion', __('Posición')),
                $n('cobertura_dias', __('Cobertura en días')), $n('necesidad', __('Necesidad del horizonte')), $n('sugerido', __('Producción sugerida')),
                $n('sugerido_orientativo', __('Cifra orientativa')), $t('confianza', __('Confianza')), $t('motivo', __('Motivo')),
                $t('sucursal_produccion', __('Sucursal de producción')), $t('origen_sucursal', __('Origen de la sucursal')),
            ], $plan['plan'], $meta, "Por producto con receta, en unidades del granel, para {$dias} días: sugerido = max(0, necesidad − posición). "
                .'Sin historial suficiente, sugerido queda vacío y sugerido_orientativo es de baja confianza: nunca es la recomendación.'
                .($plan['recortado'] ? ' Recorte: primeros '.self::MAX_PRODUCTOS.' productos.' : '')),
                array_filter($conCifra, fn (array $f): bool => Decimal::comparar((string) $f['sugerido'], '0') > 0) === [] ? []
                    : [CatalogoAcciones::crear('nueva_orden', null, __('Nueva orden de producción'))]),
            $this->dataset('detalle', [
                $t('producto', __('Producto')), $t('sucursal', __('Sucursal')), $t('articulo', __('Artículo')), $n('granel_por_unidad', __('Granel por unidad')),
                $n('vendido_neto', __('Venta neta')), $n('vendido_granel', __('Venta neta en granel')), $i('dias_con_stock', __('Días con stock')),
                $i('dias_sin_stock', __('Días sin stock')), $n('demanda_diaria', __('Demanda diaria en granel')), $n('stock', __('Stock utilizable')),
                $n('stock_granel', __('Stock en granel')), $t('nota', __('Nota')),
            ], array_slice($plan['detalle'], 0, self::MAX_DETALLE), $meta, 'Origen de la demanda por sucursal y artículo (el granel o una presentación): solo los que tuvieron stock o ventas en la ventana. '
                .'La demanda de cada uno usa solo sus días con stock.'.(count($plan['detalle']) > self::MAX_DETALLE ? ' Recorte: '.self::MAX_DETALLE.' filas.' : '')),
            ...(($argumentos['contrastar'] ?? false) ? $this->contraste($contexto, $dias, $producto === null ? null : (int) $producto->id) : []),
        ];
    }

    /**
     * Datasets del contraste histórico: por producto y el resumen con el error ponderado de cada ventana.
     *
     * @return list<array{dataset: DatasetPropuesto, descripcion: string}>
     */
    private function contraste(ContextoAgente $contexto, int $dias, ?int $productoId): array
    {
        $contraste = $this->contrastar($contexto, $dias, $productoId);
        $metodo = "Corte {$contraste['corte']}: estimado = venta neta de los 28 (o 14) días hasta el corte ÷ días con stock × {$dias}. "
            ."Real = venta neta del {$contraste['real_desde']} al {$contraste['real_hasta']}. Error = (estimado − real) ÷ real.";
        $meta = $this->meta($contexto, 'produccion.contraste', null, $contraste['resumen']['productos_comparados'] === 0 ? 'insufficient' : 'actual', $metodo, [
            'period' => ['from' => $contraste['real_desde'], 'to' => $contraste['real_hasta']],
            'note' => 'Contraste histórico con datos reales de la empresa: mide qué tan bien habría estimado el método; no cambia el plan ni las reglas.',
        ]);
        $t = fn (string $c, string $l) => $this->col($c, $l, 'string');
        $n = fn (string $c, string $l) => $this->col($c, $l, 'number');
        $i = fn (string $c, string $l) => $this->col($c, $l, 'integer');

        return [
            $this->dataset('contraste', [
                $t('producto', __('Producto')), $t('unidad', __('Unidad')), $i('historial_corte', __('Días de historial al corte')),
                $n('estimado_28', __('Estimado con 28 días')), $n('estimado_14', __('Estimado con 14 días')), $n('real', __('Venta neta real')),
                $n('error_28_pct', __('Error con 28 días (%)')), $n('error_14_pct', __('Error con 14 días (%)')),
                $i('dias_sin_stock_despues', __('Días sin stock después del corte')), $t('nota', __('Nota')),
            ], $contraste['filas'], $meta, "Contraste histórico en unidades del granel: qué habría estimado el método el {$contraste['corte']} para los {$dias} días siguientes y cuánto se vendió de verdad. "
                .'Error positivo: se estimó de más; negativo: de menos. Un solo corte: es una señal, no una garantía.'.($contraste['recortado'] ? ' Recorte: primeros '.self::MAX_PRODUCTOS.' productos.' : '')),
            $this->dataset('contraste_resumen', [
                $t('corte', __('Fecha de corte')), $t('real_desde', __('Real desde')), $t('real_hasta', __('Real hasta')), $i('productos_comparados', __('Productos comparados')),
                $n('real_total', __('Venta neta real total')), $n('error_ponderado_28_pct', __('Error ponderado con 28 días (%)')), $n('error_ponderado_14_pct', __('Error ponderado con 14 días (%)')),
            ], [$contraste['resumen']], $meta, 'Error ponderado = Σ |estimado − real| ÷ Σ real de los productos comparados: el menor indica qué ventana habría acertado más en esta empresa. '
                .'Con pocos productos o días sin stock después del corte, tómalo con cautela; las reglas (28 días, mínimo 14 de historial) no cambian solas.'),
        ];
    }

    /**
     * Plan por producto con receta vigente (todos los activos o solo el pedido) para el horizonte en días. Las claves
     * que empiezan con _ son internas (UC-22 escala la receta con ellas).
     *
     * @return array{plan: list<array<string, mixed>>, detalle: list<array<string, mixed>>, desde: string, hasta: string, recortado: bool}
     */
    public function calcular(ContextoAgente $contexto, int $dias, ?int $productoId = null): array
    {
        $empresaId = $contexto->empresaId;
        $hasta = $contexto->fechaReferencia->format('Y-m-d');
        $desde = $contexto->fechaReferencia->subDays(self::DIAS_DEMANDA - 1)->format('Y-m-d');
        ['graneles' => $graneles, 'porGranel' => $porGranel, 'granelIds' => $granelIds, 'productos' => $productos,
            'sucursales' => $sucursales, 'sucursalIds' => $sucursalIds, 'recortado' => $recortado] = $this->familias($contexto, $productoId);
        $stock = $this->stockUtilizable($contexto, $sucursalIds, $productos);
        $ventas = $this->ventaNeta($contexto, $sucursalIds, $productos);
        $movimientos = $this->movimientosPorDia($contexto, $sucursalIds, $productos, $desde, $hasta);
        $ordenes = $this->enOrdenes($empresaId, $granelIds);
        $produccion = $this->sucursalesProduccion($empresaId, $granelIds, $sucursales);
        $calendario = $this->calendario($desde, $hasta);

        $plan = [];
        $detalle = [];

        foreach ($graneles as $granel) {
            $granelId = (int) $granel->id;
            // Familia: el granel y sus presentaciones; el historial empieza cuando se creó el más antiguo.
            [$miembros, $creado] = $this->miembros($granel, $porGranel[$granelId] ?? []);
            $inicio = max($desde, $creado);
            $historial = count(array_filter($calendario, fn (string $dia): bool => $dia >= $inicio));
            $suma = ['vendido' => '0', 'demanda' => '0', 'necesidad' => '0', 'granel' => '0', 'presentaciones' => '0', 'dias_con_stock' => 0];
            $quiebres = [];

            foreach ($sucursales as $sucursal) {
                $sucursalId = (int) $sucursal->id;

                foreach ($miembros as $miembro) {
                    $utilizable = $stock[$sucursalId][$miembro['id']]['utilizable'] ?? '0';
                    $enGranel = Decimal::multiplicar($utilizable, $miembro['factor'], 6);
                    $clave = $miembro['id'] === $granelId ? 'granel' : 'presentaciones';
                    $suma[$clave] = Decimal::sumar($suma[$clave], $enGranel, 6);
                    $neto = $ventas[$sucursalId][$miembro['id']] ?? '0';
                    $uso = $this->dias($calendario, max($desde, $miembro['creado']), $stock[$sucursalId][$miembro['id']]['existencia'] ?? '0', $movimientos[$sucursalId][$miembro['id']] ?? []);

                    // Sin stock ni ventas en toda la ventana: el artículo no se ofrece ahí y no aporta demanda.
                    if ($uso['con'] === 0 && Decimal::comparar($neto, '0') === 0) {
                        continue;
                    }

                    // Vender exige stock (LibroStock no deja saldos negativos): con ventas siempre hay al menos un día con stock.
                    $vendido = Decimal::multiplicar(Decimal::comparar($neto, '0') > 0 ? $neto : '0', $miembro['factor'], 6);
                    $conStock = max($uso['con'], 1);
                    $diaria = Decimal::dividir($vendido, (string) $conStock, 6);
                    $sinStock = $uso['historia'] - $uso['con'];
                    $suma['vendido'] = Decimal::sumar($suma['vendido'], $vendido, 6);
                    $suma['demanda'] = Decimal::sumar($suma['demanda'], $diaria, 6);
                    $suma['necesidad'] = Decimal::sumar($suma['necesidad'], Decimal::dividir(Decimal::multiplicar($vendido, (string) $dias, 6), (string) $conStock, 6), 6);
                    $suma['dias_con_stock'] += $uso['con'];

                    if ($sinStock > 0) {
                        $quiebres[] = "{$sucursal->nombre} · {$miembro['nombre']}: {$sinStock} d";
                    }

                    $detalle[] = [
                        'producto' => $granel->nombre, 'sucursal' => $sucursal->nombre, 'articulo' => $miembro['nombre'], 'granel_por_unidad' => $miembro['factor'],
                        'vendido_neto' => $neto, 'vendido_granel' => $vendido, 'dias_con_stock' => $uso['con'], 'dias_sin_stock' => $sinStock,
                        'demanda_diaria' => $diaria, 'stock' => $utilizable, 'stock_granel' => $enGranel,
                        'nota' => implode('; ', array_filter([
                            Decimal::comparar($neto, '0') < 0 ? 'devoluciones mayores que ventas: cuenta 0' : null,
                            $sinStock > 0 ? "sin stock {$sinStock} días: la demanda usa los {$uso['con']} días con stock" : null,
                        ])) ?: null,
                    ];
                }
            }

            $plan[] = $this->fila($granel, $dias, $historial, $suma, $quiebres, $ordenes[$granelId] ?? ['cantidad' => '0', 'ordenes' => 0], $produccion[$granelId]);
        }

        return ['plan' => $plan, 'detalle' => $detalle, 'desde' => $desde, 'hasta' => $hasta, 'recortado' => $recortado];
    }

    /**
     * Contraste histórico (backtest) del método con datos reales: en una fecha de corte pasada se estima la demanda
     * de los días siguientes con la ventana actual (28 días) y con una alternativa (14 días), y se compara con la
     * venta neta real de esos días, todos completos (hasta ayer). Mide qué tan bien predijo el método en esta
     * empresa; no cambia el plan. Usa las mismas reglas: solo días con stock, presentaciones en granel, devoluciones
     * mayores que ventas cuentan 0.
     *
     * @return array{filas: list<array<string, mixed>>, resumen: array<string, mixed>, corte: string, real_desde: string, real_hasta: string, recortado: bool}
     */
    public function contrastar(ContextoAgente $contexto, int $dias, ?int $productoId = null): array
    {
        $hoy = $contexto->fechaReferencia;
        $realHasta = $hoy->subDay()->format('Y-m-d');
        $realDesde = $hoy->subDays($dias)->format('Y-m-d');
        $corte = $hoy->subDays($dias + 1)->format('Y-m-d');
        $ventanas = [28 => $hoy->subDays($dias + self::DIAS_DEMANDA)->format('Y-m-d'), 14 => $hoy->subDays($dias + 14)->format('Y-m-d')];
        ['graneles' => $graneles, 'porGranel' => $porGranel, 'productos' => $productos, 'sucursales' => $sucursales,
            'sucursalIds' => $sucursalIds, 'recortado' => $recortado] = $this->familias($contexto, $productoId);
        $stock = $this->stockUtilizable($contexto, $sucursalIds, $productos);
        // Saldos diarios reconstruidos hacia atrás desde la existencia actual: los movimientos llegan hasta hoy.
        $movimientos = $this->movimientosPorDia($contexto, $sucursalIds, $productos, $ventanas[28], $hoy->format('Y-m-d'));
        $calendario = $this->calendario($ventanas[28], $hoy->format('Y-m-d'));
        $ventas = [
            28 => $this->ventaNeta($contexto, $sucursalIds, $productos, $ventanas[28], $corte),
            14 => $this->ventaNeta($contexto, $sucursalIds, $productos, $ventanas[14], $corte),
            'real' => $this->ventaNeta($contexto, $sucursalIds, $productos, $realDesde, $realHasta),
        ];
        $filas = [];
        $total = ['real' => '0', 28 => '0', 14 => '0'];

        foreach ($graneles as $granel) {
            [$miembros, $creado] = $this->miembros($granel, $porGranel[(int) $granel->id] ?? []);
            $historial = count(array_filter($calendario, fn (string $dia): bool => $dia >= max($ventanas[28], $creado) && $dia <= $corte));
            $estimado = [28 => '0', 14 => '0'];
            $antes = '0';
            $real = '0';
            $sinStockDespues = 0;

            foreach ($sucursales as $sucursal) {
                $sucursalId = (int) $sucursal->id;

                foreach ($miembros as $miembro) {
                    $existencia = $stock[$sucursalId][$miembro['id']]['existencia'] ?? '0';
                    $porDia = $movimientos[$sucursalId][$miembro['id']] ?? [];
                    $ofrecido = false;

                    foreach ([28, 14] as $ventana) {
                        $neto = $ventas[$ventana][$sucursalId][$miembro['id']] ?? '0';
                        $con = $this->dias($calendario, max($ventanas[$ventana], $miembro['creado']), $existencia, $porDia, $corte)['con'];
                        $ofrecido = $ofrecido || $con > 0 || Decimal::comparar($neto, '0') !== 0;

                        if (Decimal::comparar($neto, '0') <= 0) {
                            continue;
                        }

                        // Igual que el plan: venta neta en granel × horizonte ÷ días con stock de la ventana (al menos 1).
                        $vendido = Decimal::multiplicar($neto, $miembro['factor'], 6);
                        $estimado[$ventana] = Decimal::sumar($estimado[$ventana], Decimal::dividir(Decimal::multiplicar($vendido, (string) $dias, 6), (string) max($con, 1), 6), 6);
                        $antes = $ventana === 28 ? Decimal::sumar($antes, $vendido, 6) : $antes;
                    }

                    $netoReal = $ventas['real'][$sucursalId][$miembro['id']] ?? '0';
                    $real = Decimal::sumar($real, Decimal::multiplicar(Decimal::comparar($netoReal, '0') > 0 ? $netoReal : '0', $miembro['factor'], 6), 6);
                    $despues = $this->dias($calendario, max($realDesde, $miembro['creado']), $existencia, $porDia, $realHasta);
                    // Solo cuenta quiebres de artículos que se ofrecían en la sucursal (con stock o ventas en algún momento).
                    $ofrecido = $ofrecido || $despues['con'] > 0 || Decimal::comparar($netoReal, '0') !== 0;
                    $sinStockDespues += $ofrecido ? $despues['historia'] - $despues['con'] : 0;
                }
            }

            $hayVentas = Decimal::comparar($antes, '0') > 0;
            $real = Decimal::redondear($real, 3);
            $fila = [
                'producto' => $granel->nombre, 'unidad' => $granel->unidad, 'historial_corte' => $historial, 'real' => $real,
                'estimado_28' => $hayVentas ? Decimal::redondear($estimado[28], 3) : null, 'estimado_14' => null,
                'error_28_pct' => null, 'error_14_pct' => null, 'dias_sin_stock_despues' => $sinStockDespues,
            ];

            if ($hayVentas) {
                $fila['estimado_14'] = Decimal::redondear($estimado[14], 3);
                $fila['error_28_pct'] = $this->error($fila['estimado_28'], $real);
                $fila['error_14_pct'] = $this->error($fila['estimado_14'], $real);
                $total['real'] = Decimal::sumar($total['real'], $real, 3);
                $total[28] = Decimal::sumar($total[28], $this->distancia($fila['estimado_28'], $real), 3);
                $total[14] = Decimal::sumar($total[14], $this->distancia($fila['estimado_14'], $real), 3);
            }

            $fila['nota'] = implode('. ', array_filter([
                $hayVentas ? null : 'sin ventas netas antes del corte: el método no habría estimado nada',
                $hayVentas && $historial < self::HISTORIAL_MINIMO ? "historial insuficiente al corte: {$historial} ".($historial === 1 ? 'día' : 'días') : null,
                $sinStockDespues > 0 ? 'hubo días sin stock después del corte: la venta real puede quedar por debajo de la demanda' : null,
            ])) ?: null;
            $filas[] = $fila;
        }

        $comparados = count(array_filter($filas, fn (array $f): bool => $f['estimado_28'] !== null));
        // Error ponderado (WAPE) = Σ |estimado − real| ÷ Σ real: compara las dos ventanas sin que un producto chico pese de más.
        $wape = fn (string $error): ?string => Decimal::comparar($total['real'], '0') > 0
            ? Decimal::redondear(Decimal::dividir(Decimal::multiplicar($error, '100', 3), $total['real'], 4), 1) : null;
        $resumen = ['corte' => $corte, 'real_desde' => $realDesde, 'real_hasta' => $realHasta, 'productos_comparados' => $comparados,
            'real_total' => $total['real'], 'error_ponderado_28_pct' => $wape($total[28]), 'error_ponderado_14_pct' => $wape($total[14])];

        return ['filas' => $filas, 'resumen' => $resumen, 'corte' => $corte, 'real_desde' => $realDesde, 'real_hasta' => $realHasta, 'recortado' => $recortado];
    }

    /** (estimado − real) ÷ real en porcentaje con 1 decimal; null si no hubo venta real. Positivo: se estimó de más. */
    private function error(string $estimado, string $real): ?string
    {
        return Decimal::comparar($real, '0') <= 0 ? null
            : Decimal::redondear(Decimal::dividir(Decimal::multiplicar(Decimal::restar($estimado, $real), '100', 3), $real, 4), 1);
    }

    /** |a − b| con 3 decimales. */
    private function distancia(string $a, string $b): string
    {
        $diferencia = Decimal::restar($a, $b, 3);

        return Decimal::comparar($diferencia, '0') < 0 ? Decimal::restar('0', $diferencia, 3) : $diferencia;
    }

    /**
     * Días del $desde al $hasta (inclusive), en orden.
     *
     * @return list<string>
     */
    private function calendario(string $desde, string $hasta): array
    {
        $dias = [];

        for ($dia = CarbonImmutable::createFromFormat('!Y-m-d', $desde); $dia->format('Y-m-d') <= $hasta; $dia = $dia->addDay()) {
            $dias[] = $dia->format('Y-m-d');
        }

        return $dias;
    }

    /**
     * El granel y sus presentaciones, con el factor a granel y el día de creación; y el día del más antiguo.
     *
     * @param  list<stdClass>  $presentaciones
     * @return array{0: list<array{id: int, nombre: string, factor: string, creado: string}>, 1: string}
     */
    private function miembros(stdClass $granel, array $presentaciones): array
    {
        $miembros = [['id' => (int) $granel->id, 'nombre' => (string) $granel->nombre, 'factor' => '1', 'creado' => (string) $granel->creado]];
        $creado = (string) $granel->creado;

        foreach ($presentaciones as $presentacion) {
            $miembros[] = ['id' => (int) $presentacion->id, 'nombre' => (string) $presentacion->nombre,
                'factor' => (string) $presentacion->granel_por_unidad, 'creado' => (string) $presentacion->creado];
            $creado = min($creado, (string) $presentacion->creado);
        }

        return [$miembros, $creado];
    }

    /**
     * Graneles con receta vigente que controlan stock (todos los activos o solo el pedido), sus presentaciones activas
     * y las sucursales activas: tres consultas.
     *
     * @return array{graneles: Collection<int, stdClass>, porGranel: array<int, list<stdClass>>, granelIds: list<int>, productos: list<int>, sucursales: Collection<int, stdClass>, sucursalIds: list<int>, recortado: bool}
     */
    private function familias(ContextoAgente $contexto, ?int $productoId): array
    {
        $empresaId = $contexto->empresaId;
        $creado = 'DATE('.$this->local('p.created_at').') AS creado';
        $graneles = DB::table('recetas as r')
            ->join('productos as p', fn (JoinClause $j) => $j->on('p.id', '=', 'r.producto_vigente')->on('p.empresa_id', '=', 'r.empresa_id'))
            ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
            ->where('r.empresa_id', $empresaId)
            ->where('p.clase', Producto::CLASE_VENTA)->where('p.controla_stock', true)
            ->when($productoId !== null, fn (Builder $q) => $q->where('p.id', $productoId), fn (Builder $q) => $q->where('p.activo', true))
            ->orderBy('p.nombre')->orderBy('p.id')
            ->limit(self::MAX_PRODUCTOS + 1)
            ->select(['p.id', 'p.activo', 'p.tipo_venta', 'u.admite_decimales', 'u.nombre as unidad', 'r.id as receta_id', 'r.activa', 'r.cantidad_base',
                DB::raw(Producto::sqlNombreCompleto('p', 'u').' AS nombre')])
            ->selectRaw($creado, [$this->desfase($contexto)])
            ->get();
        $recortado = $graneles->count() > self::MAX_PRODUCTOS;
        $graneles = $graneles->take(self::MAX_PRODUCTOS);
        $granelIds = array_values($graneles->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $presentaciones = $granelIds === [] ? collect() : DB::table('productos as p')
            ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
            ->where('p.empresa_id', $empresaId)
            ->whereIn('p.granel_id', $granelIds)
            ->where('p.clase', Producto::CLASE_VENTA)->where('p.activo', true)->where('p.controla_stock', true)
            ->orderBy('p.granel_por_unidad')->orderBy('p.id')
            ->select(['p.id', 'p.granel_id', 'p.granel_por_unidad', DB::raw(Producto::sqlNombreCompleto('p', 'u').' AS nombre')])
            ->selectRaw($creado, [$this->desfase($contexto)])
            ->get();

        $porGranel = [];

        foreach ($presentaciones as $presentacion) {
            $porGranel[(int) $presentacion->granel_id][] = $presentacion;
        }

        $productos = array_merge($granelIds, array_values($presentaciones->pluck('id')->map(fn ($id): int => (int) $id)->all()));
        $sucursales = DB::table('sucursales')->where('empresa_id', $empresaId)->where('activa', true)->orderBy('nombre')->orderBy('id')->get(['id', 'nombre']);
        $sucursalIds = array_values($sucursales->pluck('id')->map(fn ($id): int => (int) $id)->all());

        return ['graneles' => $graneles, 'porGranel' => $porGranel, 'granelIds' => $granelIds, 'productos' => $productos,
            'sucursales' => $sucursales, 'sucursalIds' => $sucursalIds, 'recortado' => $recortado];
    }

    /**
     * @param  array{vendido: string, demanda: string, necesidad: string, granel: string, presentaciones: string, dias_con_stock: int}  $suma
     * @param  list<string>  $quiebres
     * @param  array{cantidad: string, ordenes: int}  $orden
     * @param  array{id: int, nombre: string, origen: string}  $sucursal
     * @return array<string, mixed>
     */
    private function fila(stdClass $granel, int $dias, int $historial, array $suma, array $quiebres, array $orden, array $sucursal): array
    {
        $granelStock = Decimal::redondear($suma['granel'], 3);
        $presentaciones = Decimal::redondear($suma['presentaciones'], 3);
        $posicion = Decimal::sumar(Decimal::sumar($granelStock, $presentaciones), $orden['cantidad']);
        $necesidad = Decimal::redondear($suma['necesidad'], 3);
        $falta = Decimal::restar($necesidad, $posicion);
        $cifra = Decimal::comparar($falta, '0') > 0
            ? $this->subir($falta, Escalado::esEntera($granel->tipo_venta === null ? null : (string) $granel->tipo_venta, (bool) $granel->admite_decimales))
            : '0.000';
        $hayVentas = Decimal::comparar($suma['vendido'], '0') > 0;
        $insuficiente = $historial < self::HISTORIAL_MINIMO;
        $motivos = [];

        if (! $hayVentas) {
            $motivos[] = $suma['dias_con_stock'] === 0 ? 'sin stock en toda la ventana: la demanda no se conoce' : 'sin ventas netas en la ventana: no se sugiere producir';
        }

        if ($hayVentas && $insuficiente) {
            $motivos[] = "historial insuficiente: {$historial} ".($historial === 1 ? 'día' : 'días').' (mínimo '.self::HISTORIAL_MINIMO.'); cifra solo orientativa';
        }

        if ($hayVentas && $quiebres !== []) {
            $motivos[] = 'sin stock parte de la ventana ('.implode('; ', array_slice($quiebres, 0, 2)).(count($quiebres) > 2 ? '; …' : '').'): la demanda usa los días con stock';
        }

        if (! (bool) $granel->activa) {
            $motivos[] = 'receta desactivada: no admite órdenes';
        }

        if (! (bool) $granel->activo) {
            $motivos[] = 'producto inactivo';
        }

        return [
            'producto' => $granel->nombre, 'unidad' => $granel->unidad, 'historial_dias' => $historial, 'vendido_granel' => $suma['vendido'],
            'demanda_diaria' => $suma['demanda'], 'stock_granel' => $granelStock, 'stock_presentaciones' => $presentaciones,
            'en_ordenes' => $orden['cantidad'], 'ordenes' => $orden['ordenes'], 'posicion' => $posicion,
            'cobertura_dias' => Decimal::comparar($suma['demanda'], '0', 6) > 0 ? Decimal::redondear(Decimal::dividir($posicion, $suma['demanda'], 4), 1) : null,
            'necesidad' => $hayVentas ? $necesidad : null,
            'sugerido' => $hayVentas && ! $insuficiente ? $cifra : null,
            'sugerido_orientativo' => $hayVentas && $insuficiente ? $cifra : null,
            'confianza' => ! $hayVentas ? 'sin datos' : ($insuficiente || $quiebres !== [] ? 'baja' : 'normal'),
            'motivo' => $motivos === [] ? null : implode('. ', $motivos),
            'sucursal_produccion' => $sucursal['nombre'], 'origen_sucursal' => $sucursal['origen'],
            '_producto_id' => (int) $granel->id, '_receta_id' => (int) $granel->receta_id, '_receta_activa' => (bool) $granel->activa,
            '_cantidad_base' => (string) $granel->cantidad_base, '_sucursal_id' => $sucursal['id'],
        ];
    }

    /** Al entero hacia arriba si el granel va en unidades enteras; si no, a 3 decimales. */
    private function subir(string $cantidad, bool $entero): string
    {
        if (! $entero) {
            return Decimal::redondear($cantidad, 3);
        }

        $piso = Decimal::dividir($cantidad, '1', 0);

        return Decimal::sumar($piso, Decimal::comparar($piso, $cantidad) < 0 ? '1' : '0');
    }

    /**
     * Días de la ventana desde $inicio hasta $hasta (por defecto el último del calendario) (historia) y cuántos tuvieron
     * stock en algún momento (con). Saldo al abrir la ventana = existencia actual − Σ movimientos de la ventana, por eso
     * el calendario y los movimientos siempre llegan hasta hoy; un día con movimientos tuvo stock si el saldo antes o
     * después de alguno fue positivo (pico); uno sin movimientos, si el saldo que venía era positivo.
     *
     * @param  list<string>  $calendario
     * @param  array<string, array{pico: string, neto: string}>  $porDia
     * @return array{historia: int, con: int}
     */
    private function dias(array $calendario, string $inicio, string $existencia, array $porDia, ?string $hasta = null): array
    {
        $saldo = $existencia;

        foreach ($porDia as $dia) {
            $saldo = Decimal::restar($saldo, $dia['neto']);
        }

        $historia = 0;
        $con = 0;

        foreach ($calendario as $fecha) {
            $dia = $porDia[$fecha] ?? null;
            $hubo = Decimal::comparar($dia === null ? $saldo : $dia['pico'], '0') > 0;
            $saldo = $dia === null ? $saldo : Decimal::sumar($saldo, $dia['neto']);

            if ($fecha >= $inicio && ($hasta === null || $fecha <= $hasta)) {
                $historia++;
                $con += $hubo ? 1 : 0;
            }
        }

        return ['historia' => $historia, 'con' => $con];
    }

    /**
     * Movimientos de stock de la ventana agrupados por sucursal, producto y día local: saldo máximo (antes o después de
     * cada movimiento) y neto del día. Una consulta sobre el índice (empresa_id, producto_id, created_at).
     *
     * @param  list<int>  $sucursales
     * @param  list<int>  $productos
     * @return array<int, array<int, array<string, array{pico: string, neto: string}>>>
     */
    private function movimientosPorDia(ContextoAgente $contexto, array $sucursales, array $productos, string $desde, string $hasta): array
    {
        if ($sucursales === [] || $productos === []) {
            return [];
        }

        [$inicio, $fin] = Periodo::rango($desde, $hasta)->limitesUtc();
        $filas = DB::table('movimientos_stock')
            ->where('empresa_id', $contexto->empresaId)
            ->whereIn('sucursal_id', $sucursales)
            ->whereIn('producto_id', $productos)
            ->where('created_at', '>=', $inicio)->where('created_at', '<', $fin)
            ->select(['sucursal_id', 'producto_id'])
            ->selectRaw('DATE('.$this->local('created_at').') AS dia', [$this->desfase($contexto)])
            ->selectRaw('MAX(GREATEST(saldo_anterior, saldo_posterior)) AS pico')
            ->selectRaw('SUM(cantidad) AS neto')
            ->groupBy('sucursal_id', 'producto_id', 'dia')
            ->get();
        $porDia = [];

        foreach ($filas as $fila) {
            $porDia[(int) $fila->sucursal_id][(int) $fila->producto_id][substr((string) $fila->dia, 0, 10)] = ['pico' => (string) $fila->pico, 'neto' => (string) $fila->neto];
        }

        return $porDia;
    }

    /**
     * Lo planificado en órdenes emitidas o aprobadas (sin finalizar ni anular) de cada granel: una consulta.
     *
     * @param  list<int>  $graneles
     * @return array<int, array{cantidad: string, ordenes: int}>
     */
    private function enOrdenes(int $empresaId, array $graneles): array
    {
        if ($graneles === []) {
            return [];
        }

        $porGranel = [];

        foreach (DB::table('ordenes_produccion')
            ->where('empresa_id', $empresaId)
            ->whereIn('estado', [EstadoOrdenProduccion::Emitida->value, EstadoOrdenProduccion::Aprobada->value])
            ->whereIn('producto_id', $graneles)
            ->groupBy('producto_id')
            ->get(['producto_id', DB::raw('SUM(cantidad_planificada) AS cantidad'), DB::raw('COUNT(*) AS ordenes')]) as $fila) {
            $porGranel[(int) $fila->producto_id] = ['cantidad' => (string) $fila->cantidad, 'ordenes' => (int) $fila->ordenes];
        }

        return $porGranel;
    }

    /**
     * Sucursal de producción de cada granel: la de su última orden no anulada en una sucursal activa; si no tiene, la de
     * la última orden de la empresa; si la empresa nunca produjo, la primera sucursal activa (la más antigua). Dos
     * consultas.
     *
     * @param  list<int>  $graneles
     * @param  Collection<int, stdClass>  $sucursales  activas
     * @return array<int, array{id: int, nombre: string, origen: string}>
     */
    private function sucursalesProduccion(int $empresaId, array $graneles, Collection $sucursales): array
    {
        $nombres = $sucursales->mapWithKeys(fn (stdClass $s): array => [(int) $s->id => (string) $s->nombre])->all();

        if ($graneles === [] || $nombres === []) {
            return array_fill_keys($graneles, ['id' => 0, 'nombre' => '', 'origen' => 'sin sucursal activa']);
        }

        $ordenes = fn () => DB::table('ordenes_produccion')
            ->where('empresa_id', $empresaId)
            ->where('estado', '<>', EstadoOrdenProduccion::Anulada->value)
            ->whereIn('sucursal_id', array_keys($nombres));
        $porProducto = DB::table('ordenes_produccion')
            ->where('empresa_id', $empresaId)
            ->whereIn('id', $ordenes()->whereIn('producto_id', $graneles)->groupBy('producto_id')->selectRaw('MAX(id)'))
            ->pluck('sucursal_id', 'producto_id')
            ->all();
        $ultima = $ordenes()->orderByDesc('id')->value('sucursal_id');
        $respaldo = $ultima !== null ? ['id' => (int) $ultima, 'origen' => 'última orden de la empresa'] : ['id' => (int) min(array_keys($nombres)), 'origen' => 'primera sucursal activa'];
        $resultado = [];

        foreach ($graneles as $granelId) {
            $propia = $porProducto[$granelId] ?? null;
            $elegida = $propia !== null ? ['id' => (int) $propia, 'origen' => 'última orden del producto'] : $respaldo;
            $resultado[$granelId] = $elegida + ['nombre' => $nombres[$elegida['id']]];
        }

        return $resultado;
    }
}
