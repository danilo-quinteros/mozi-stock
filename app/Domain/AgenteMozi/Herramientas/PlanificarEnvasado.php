<?php

namespace App\Domain\AgenteMozi\Herramientas;

use App\Domain\AgenteMozi\Acciones\CatalogoAcciones;
use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\Produccion\Escalado;
use App\Models\Producto;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * plan_packaging (UC-26, AGENTE_MOZI_IA.md § 2.1): qué presentaciones reponer y cuántas unidades alcanzan para envasar.
 * Por presentación y sucursal:
 * - cobertura = stock utilizable ÷ venta diaria, con la venta neta (ventas − devoluciones) de los últimos 28 días
 *   (IA-P1): estimación;
 * - unidades máximas = el menor de (granel utilizable ÷ granel por unidad) y (envase utilizable ÷ cantidad por unidad),
 *   hacia abajo y comprobado con las reglas de redondeo de Nuevo envasado (RegistrarEnvasado::teoricos: unidades enteras
 *   hacia arriba), con el material que limita. Cada máximo supone que todo el material va a esa presentación: los
 *   máximos de un mismo granel no se suman.
 * Utilizable = saldo − lotes vencidos (los vencidos no se envasan). Lote sugerido del granel: el vigente que vence
 * antes y, al final, el de regularización, como la pantalla de envasado. Solo lectura.
 */
final class PlanificarEnvasado extends HerramientaProduccion
{
    private const MAX_FILAS = 300;

    public function nombre(): string
    {
        return 'plan_packaging';
    }

    public function descripcion(): string
    {
        return 'Envasado: por presentación y sucursal, stock utilizable, venta diaria (venta neta de los últimos '.self::DIAS_DEMANDA.' días, estimación), cobertura en días y cuántas unidades se pueden envasar con el granel y los envases utilizables (sin lotes vencidos), con el material que limita y el granel necesario. '
            .'Cada máximo supone que todo el granel va a esa presentación: los máximos de un mismo granel NO se suman. También el granel utilizable por sucursal y el lote sugerido (el vigente que vence antes, como Nuevo envasado). Solo lectura.';
    }

    public function parametros(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sucursal' => ['type' => 'string', 'description' => 'Sucursal (nombre). Por defecto todas las activas.'],
                'granel' => ['type' => 'string', 'description' => 'Granel (producto de origen de las presentaciones), nombre o código. Por defecto todos.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function reglas(): array
    {
        return [
            'sucursal' => ['sometimes', 'nullable', 'string', 'max:120'],
            'granel' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    public function avance(): string
    {
        return __('Calculando el envasado…');
    }

    public function ejecutar(ContextoAgente $contexto, array $argumentos): array
    {
        $empresaId = $contexto->empresaId;
        $conPresentaciones = fn (Builder $q) => $q->whereNull('p.granel_id')->whereExists(fn (Builder $r) => $r->selectRaw('1')->from('productos as pr')
            ->whereColumn('pr.granel_id', 'p.id')->whereColumn('pr.empresa_id', 'p.empresa_id'));
        $granel = $this->producto($contexto, $argumentos['granel'] ?? null, Producto::CLASE_VENTA, 'un granel con presentaciones', $conPresentaciones);
        $sucursal = $this->sucursal($contexto, $argumentos['sucursal'] ?? null);

        // Las presentaciones que ofrece Nuevo envasado: activas, con stock, sin tallas y colores, de un granel activo.
        $presentaciones = DB::table('productos as p')
            ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
            ->join('productos as g', fn (JoinClause $j) => $j->on('g.id', '=', 'p.granel_id')->on('g.empresa_id', '=', 'p.empresa_id'))
            ->join('unidades_medida as gu', 'gu.id', '=', 'g.unidad_medida_id')
            ->where('p.empresa_id', $empresaId)
            ->where('p.clase', Producto::CLASE_VENTA)->where('p.activo', true)->where('p.controla_stock', true)->whereNull('p.modelo_id')
            ->where('g.activo', true)->where('g.controla_stock', true)
            ->when($granel !== null, fn (Builder $q) => $q->where('p.granel_id', $granel->id))
            ->orderBy('g.nombre')->orderBy('p.granel_por_unidad')->orderBy('p.id')
            ->get(['p.id', 'p.granel_id', 'p.granel_por_unidad', 'p.tipo_venta', 'u.admite_decimales', 'g.tipo_venta as g_tipo_venta', 'gu.admite_decimales as g_decimales',
                'g.controla_vencimiento', 'gu.nombre as unidad_granel', DB::raw(Producto::sqlNombreCompleto('p', 'u').' AS nombre'),
                DB::raw(Producto::sqlNombreCompleto('g', 'gu').' AS granel')]);
        $envases = $this->envases($empresaId, array_values($presentaciones->pluck('id')->map(fn ($id): int => (int) $id)->all()));
        $sucursales = DB::table('sucursales')->where('empresa_id', $empresaId)->where('activa', true)
            ->when($sucursal !== null, fn (Builder $q) => $q->where('id', $sucursal->id))
            ->orderBy('nombre')->get(['id', 'nombre']);
        $sucursalIds = array_values($sucursales->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $graneles = array_values(array_unique($presentaciones->pluck('granel_id')->map(fn ($id): int => (int) $id)->all()));
        $productos = array_values(array_unique(array_merge(
            $graneles,
            array_values($presentaciones->pluck('id')->map(fn ($id): int => (int) $id)->all()),
            array_merge([], ...array_map(fn (Collection $e): array => $e->pluck('producto_id')->map(fn ($id): int => (int) $id)->all(), $envases->all())),
        )));
        $stock = $this->stockUtilizable($contexto, $sucursalIds, $productos);
        $demanda = $this->ventaNeta($contexto, $sucursalIds, array_values($presentaciones->pluck('id')->map(fn ($id): int => (int) $id)->all()));
        $lotes = $this->lotes($contexto, $sucursalIds, array_values(array_unique($presentaciones->where('controla_vencimiento', true)->pluck('granel_id')->map(fn ($id): int => (int) $id)->all())));

        $filas = [];
        $filasGranel = [];

        foreach ($sucursales as $s) {
            $sucursalId = (int) $s->id;

            foreach ($presentaciones->groupBy('granel_id') as $granelId => $delGranel) {
                $primera = $delGranel->first();
                $granelStock = $stock[$sucursalId][(int) $granelId] ?? ['existencia' => '0', 'vencido' => '0', 'utilizable' => '0'];
                $lote = $lotes[$sucursalId][(int) $granelId] ?? null;
                $filasGranel[] = [
                    'granel' => $primera->granel, 'sucursal' => $s->nombre, 'unidad' => $primera->unidad_granel, 'existencia' => $granelStock['existencia'],
                    'vencido' => $granelStock['vencido'], 'utilizable' => $granelStock['utilizable'], 'presentaciones' => $delGranel->count(),
                    'lote_sugerido' => $lote === null ? null : ($lote->es_regularizacion ? 'sin número (regularización)' : (string) $lote->numero),
                    'vencimiento_lote' => $lote?->vencimiento === null ? null : substr((string) $lote->vencimiento, 0, 10),
                    'cantidad_lote' => $lote === null ? null : (string) $lote->cantidad,
                ];

                foreach ($delGranel as $presentacion) {
                    $filas[] = $this->fila($presentacion, $s, $envases->get((int) $presentacion->id, collect()), $stock[$sucursalId] ?? [], $demanda[$sucursalId][(int) $presentacion->id] ?? '0');
                }
            }
        }

        // Primero lo que se agota antes (cobertura menor); sin ventas, al final.
        usort($filas, function (array $a, array $b): int {
            if (($a['cobertura_dias'] === null) !== ($b['cobertura_dias'] === null)) {
                return $a['cobertura_dias'] === null ? 1 : -1;
            }

            return Decimal::comparar((string) ($a['cobertura_dias'] ?? '0'), (string) ($b['cobertura_dias'] ?? '0'), 1)
                ?: strcmp((string) $a['sucursal'], (string) $b['sucursal']) ?: strcmp((string) $a['presentacion'], (string) $b['presentacion']);
        });
        $recortado = count($filas) > self::MAX_FILAS;

        $desde = $contexto->fechaReferencia->subDays(self::DIAS_DEMANDA - 1)->format('Y-m-d');
        $metodo = 'Máximo = menor de granel y envases utilizables ÷ cantidad por unidad, con el redondeo de Nuevo envasado. Cobertura = stock utilizable ÷ venta neta diaria de '.self::DIAS_DEMANDA.' días.';
        $meta = $this->meta($contexto, 'produccion.envasado', null, 'estimated', $metodo, [
            'period' => ['from' => $desde, 'to' => $contexto->fechaReferencia->format('Y-m-d')],
            'note' => 'Los máximos de un mismo granel no se suman: cada uno supone que todo el granel va a esa presentación. La cobertura es una estimación.',
        ]);
        $t = fn (string $c, string $l) => $this->col($c, $l, 'string');
        $n = fn (string $c, string $l) => $this->col($c, $l, 'number');

        return [
            $this->dataset('graneles', [
                $t('granel', __('Granel')), $t('sucursal', __('Sucursal')), $t('unidad', __('Unidad')), $n('existencia', __('Stock')), $n('vencido', __('Vencido')),
                $n('utilizable', __('Granel utilizable')), $this->col('presentaciones', __('Presentaciones'), 'integer'), $t('lote_sugerido', __('Lote sugerido')),
                $this->col('vencimiento_lote', __('Vence'), 'date'), $n('cantidad_lote', __('Cantidad del lote')),
            ], $filasGranel, $meta, 'Granel utilizable por sucursal (sin lotes vencidos) y el lote que Nuevo envasado propone (el vigente que vence antes). Un envasado usa un solo lote.'),
            // Acción (§ UC-26): «Nuevo envasado», sin datos precargados (IA-P3).
            $this->conAcciones($this->dataset('presentaciones', [
                $t('presentacion', __('Presentación')), $t('granel', __('Granel')), $t('sucursal', __('Sucursal')), $n('stock', __('Stock utilizable')),
                $n('venta_diaria', __('Venta diaria estimada')), $n('cobertura_dias', __('Cobertura en días')),
                $n('granel_por_unidad', __('Granel por unidad')), $n('maximo_unidades', __('Unidades que se pueden envasar')), $t('limita', __('Material que limita')),
                $n('granel_necesario', __('Granel necesario para el máximo')), $t('unidad_granel', __('Unidad del granel')), $t('nota', __('Nota')),
            ], array_slice($filas, 0, self::MAX_FILAS), $meta, 'Por presentación y sucursal, de la que se agota antes a la que dura más (sin ventas al final). '
                .'maximo_unidades supone que todo el granel va a esa presentación: los máximos de un mismo granel no se suman.'.($recortado ? ' Recorte: '.self::MAX_FILAS.' filas.' : '')),
                $filas === [] ? [] : [CatalogoAcciones::crear('nuevo_envasado', null, __('Nuevo envasado'))]),
        ];
    }

    /**
     * @param  Collection<int, stdClass>  $envases
     * @param  array<int, array{existencia: string, vencido: string, utilizable: string}>  $stock  producto_id => stock de la sucursal
     * @return array<string, mixed>
     */
    private function fila(stdClass $presentacion, stdClass $sucursal, Collection $envases, array $stock, string $vendido): array
    {
        $granelId = (int) $presentacion->granel_id;
        $porUnidad = (string) $presentacion->granel_por_unidad;
        $granelEntero = Escalado::esEntera($presentacion->g_tipo_venta === null ? null : (string) $presentacion->g_tipo_venta, (bool) $presentacion->g_decimales);
        $lineas = [['producto_id' => $granelId, 'cantidad' => $porUnidad, 'entera' => $granelEntero]];
        $nombres = [$granelId => (string) $presentacion->granel];

        foreach ($envases as $envase) {
            $lineas[] = ['producto_id' => (int) $envase->producto_id, 'cantidad' => (string) $envase->cantidad,
                'entera' => Escalado::esEntera($envase->tipo_venta === null ? null : (string) $envase->tipo_venta, (bool) $envase->admite_decimales)];
            $nombres[(int) $envase->producto_id] = (string) $envase->nombre;
        }

        $disponible = array_map(fn (array $s): string => $s['utilizable'], $stock);
        $maximo = $this->maximo($lineas, '1', Escalado::esEntera($presentacion->tipo_venta === null ? null : (string) $presentacion->tipo_venta, (bool) $presentacion->admite_decimales), $disponible);
        $utilizable = $stock[(int) $presentacion->id]['utilizable'] ?? '0';
        $diaria = Decimal::comparar($vendido, '0') > 0 ? Decimal::dividir($vendido, (string) self::DIAS_DEMANDA, 3) : null;
        $sinEnvases = $envases->isEmpty() ? 'sin materiales de envasado registrados' : null;

        return [
            'presentacion' => $presentacion->nombre, 'granel' => $presentacion->granel, 'sucursal' => $sucursal->nombre, 'stock' => $utilizable,
            'venta_diaria' => $diaria,
            'cobertura_dias' => $diaria === null ? null : Decimal::redondear(Decimal::dividir(Decimal::multiplicar($utilizable, (string) self::DIAS_DEMANDA, 3), $vendido, 4), 1),
            'granel_por_unidad' => $porUnidad, 'maximo_unidades' => $maximo['maximo'],
            'limita' => $maximo['limita'] === null ? null : ($nombres[$maximo['limita']] ?? null),
            'granel_necesario' => Escalado::teorica($maximo['maximo'], '1', $porUnidad, $granelEntero), 'unidad_granel' => $presentacion->unidad_granel,
            'nota' => $diaria === null ? implode('; ', array_filter(['sin ventas netas en '.self::DIAS_DEMANDA.' días', $sinEnvases])) : $sinEnvases,
        ];
    }

    /**
     * Envases de cada presentación (composición vigente), una consulta.
     *
     * @param  list<int>  $presentaciones
     * @return Collection<int, Collection<int, stdClass>>
     */
    private function envases(int $empresaId, array $presentaciones): Collection
    {
        if ($presentaciones === []) {
            return collect();
        }

        return DB::table('presentacion_envases as e')
            ->join('productos as m', fn (JoinClause $j) => $j->on('m.id', '=', 'e.producto_id')->on('m.empresa_id', '=', 'e.empresa_id'))
            ->join('unidades_medida as mu', 'mu.id', '=', 'm.unidad_medida_id')
            ->where('e.empresa_id', $empresaId)
            ->whereIn('e.presentacion_id', $presentaciones)
            ->orderBy('e.presentacion_id')->orderBy('e.orden')
            ->get(['e.presentacion_id', 'e.producto_id', 'e.cantidad', 'm.tipo_venta', 'mu.admite_decimales', DB::raw(Producto::sqlNombreCompleto('m', 'mu').' AS nombre')])
            ->groupBy(fn (stdClass $e): int => (int) $e->presentacion_id);
    }

    /**
     * Lote que propone Nuevo envasado para cada granel y sucursal: los vigentes por vencimiento y, al final, el de
     * regularización; los vencidos no. Solo con Lotes y vencimientos activo. Una consulta.
     *
     * @param  list<int>  $sucursales
     * @param  list<int>  $graneles  solo los que controlan vencimiento
     * @return array<int, array<int, stdClass>> sucursal_id => granel_id => lote
     */
    private function lotes(ContextoAgente $contexto, array $sucursales, array $graneles): array
    {
        if (! $contexto->lotes() || $sucursales === [] || $graneles === []) {
            return [];
        }

        $hoy = $contexto->fechaReferencia->format('Y-m-d');
        $filas = DB::table('stock_lotes as s')
            ->join('lotes as l', fn (JoinClause $j) => $j->on('l.id', '=', 's.lote_id')->on('l.empresa_id', '=', 's.empresa_id'))
            ->where('s.empresa_id', $contexto->empresaId)
            ->whereIn('s.sucursal_id', $sucursales)
            ->whereIn('s.producto_id', $graneles)
            ->where('s.cantidad', '>', 0)
            ->where(fn (Builder $q) => $q->where('l.es_regularizacion', true)->orWhere('l.vencimiento', '>=', $hoy))
            ->orderBy('l.es_regularizacion')->orderBy('l.vencimiento')->orderBy('l.id')
            ->get(['s.sucursal_id', 's.producto_id', 'l.numero', 'l.vencimiento', 'l.es_regularizacion', 's.cantidad']);
        $primero = [];

        foreach ($filas as $fila) {
            $fila->es_regularizacion = (bool) $fila->es_regularizacion;
            $primero[(int) $fila->sucursal_id][(int) $fila->producto_id] ??= $fila;
        }

        return $primero;
    }
}
