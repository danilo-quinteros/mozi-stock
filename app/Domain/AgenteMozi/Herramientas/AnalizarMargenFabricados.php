<?php

namespace App\Domain\AgenteMozi\Herramientas;

use App\Domain\AgenteMozi\Acciones\CatalogoAcciones;
use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\DTOs\AccionPropuesta;
use App\Domain\Ventas\CalculadoraDevolucion;
use App\Models\Producto;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * analyze_manufactured_margin (UC-25, AGENTE_MOZI_IA.md § 2.1): margen sobre materiales de los productos fabricados
 * (con receta vigente) y de las presentaciones. Margen = precio − costo de materiales, con el precio de lista y con el
 * precio neto realmente vendido en el período, con los criterios de Reportes › Ventas: ventas confirmadas por su fecha,
 * descuento global repartido entre las líneas como lo reparte la devolución (CalculadoraDevolucion::netosPorLinea) y
 * devoluciones en su propia fecha (su importe ya es neto). Para comparar el granel con sus presentaciones, todo se lleva
 * a la unidad del granel (÷ granel por unidad).
 *
 * Las líneas de venta no guardan el costo del momento: se usa el costo promedio actual y el resultado es estimado
 * (IA-P4). Costo 0 (todavía sin producir ni envasar) = no calculable. Nunca se llama utilidad: es margen sobre
 * materiales. Los productos de reventa quedan fuera (su costo no se mantiene).
 */
final class AnalizarMargenFabricados extends HerramientaProduccion
{
    private const DIAS = 30;

    private const MAX_PRODUCTOS = 300;

    public function nombre(): string
    {
        return 'analyze_manufactured_margin';
    }

    public function descripcion(): string
    {
        return 'Margen sobre materiales de los productos fabricados y de las presentaciones (por defecto los últimos '.self::DIAS.' días): precio − costo de materiales (costo promedio actual, estimado), '
            .'con el precio de lista y con el precio neto realmente vendido (descuentos y devoluciones como Reportes › Ventas), y el margen del período. '
            .'Para comparar granel y presentaciones, los valores por unidad de granel (precio ÷ granel por unidad). Costo 0 = no calculable. No es utilidad: no incluye mano de obra ni gastos. No incluye productos de reventa.';
    }

    public function parametros(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->propiedadesPeriodo('hace '.self::DIAS.' días'),
            'additionalProperties' => false,
        ];
    }

    public function reglas(): array
    {
        return $this->reglasPeriodo();
    }

    public function avance(): string
    {
        return __('Calculando el margen de los productos fabricados…');
    }

    public function ejecutar(ContextoAgente $contexto, array $argumentos): array
    {
        $empresaId = $contexto->empresaId;
        $periodo = $this->periodo($contexto, $argumentos, self::DIAS);
        [$inicio, $fin] = $periodo->limitesUtc();
        $productos = DB::table('productos as p')
            ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
            ->leftJoin('productos as g', fn (JoinClause $j) => $j->on('g.id', '=', 'p.granel_id')->on('g.empresa_id', '=', 'p.empresa_id'))
            ->leftJoin('unidades_medida as gu', 'gu.id', '=', 'g.unidad_medida_id')
            ->where('p.empresa_id', $empresaId)
            ->where('p.clase', Producto::CLASE_VENTA)
            ->where(fn (Builder $q) => $q->whereNotNull('p.granel_id')->orWhereExists(fn (Builder $r) => $r->selectRaw('1')->from('recetas as rv')
                ->whereColumn('rv.empresa_id', 'p.empresa_id')->whereColumn('rv.producto_vigente', 'p.id')))
            ->orderBy('p.nombre')->orderBy('p.id')
            ->get(['p.id', 'p.activo', 'p.granel_id', 'p.granel_por_unidad', 'p.precio_consumidor', 'p.costo_promedio', 'u.nombre as unidad',
                DB::raw(Producto::sqlNombreCompleto('p', 'u').' AS nombre'), DB::raw('CASE WHEN g.id IS NULL THEN NULL ELSE '.Producto::sqlNombreCompleto('g', 'gu').' END AS granel'),
                'gu.nombre as unidad_granel'])
            ->keyBy(fn (stdClass $p): int => (int) $p->id);
        $ids = array_values($productos->keys()->all());
        $ventas = $this->ventas($empresaId, $ids, $inicio, $fin);
        $devueltas = $ids === [] ? collect() : DB::table('devolucion_items as di')
            ->join('devoluciones as d', fn (JoinClause $j) => $j->on('d.id', '=', 'di.devolucion_id')->on('d.empresa_id', '=', 'di.empresa_id'))
            ->where('di.empresa_id', $empresaId)
            ->whereIn('di.producto_id', $ids)
            ->where('d.fecha', '>=', $inicio)
            ->where('d.fecha', '<', $fin)
            ->groupBy('di.producto_id')
            ->get(['di.producto_id', DB::raw('SUM(di.cantidad) AS cantidad'), DB::raw('SUM(di.importe) AS importe')])
            ->keyBy(fn (stdClass $d): int => (int) $d->producto_id);

        $filas = [];
        $resumen = ['productos' => 0, 'con_ventas' => 0, 'no_calculables' => 0, 'venta_neta' => '0.00', 'margen_periodo' => '0.00', 'venta_neta_calculable' => '0.00'];

        foreach ($productos as $id => $p) {
            $vendido = $ventas[$id] ?? ['cantidad' => '0', 'importe' => '0.00'];
            $devuelto = $devueltas->get($id);
            $unidades = Decimal::restar($vendido['cantidad'], (string) ($devuelto->cantidad ?? '0'));
            $ventaNeta = Decimal::restar($vendido['importe'], (string) ($devuelto->importe ?? '0'), 2);
            $conMovimiento = Decimal::comparar($vendido['cantidad'], '0') > 0 || $devuelto !== null;

            if (! (bool) $p->activo && ! $conMovimiento) {
                continue;
            }

            $fila = $this->fila($p, $unidades, $ventaNeta);
            $filas[] = $fila;
            $resumen['productos']++;
            $resumen['con_ventas'] += Decimal::comparar($unidades, '0') > 0 ? 1 : 0;
            $resumen['venta_neta'] = Decimal::sumar($resumen['venta_neta'], $ventaNeta, 2);

            if ($fila['costo_materiales'] === null) {
                $resumen['no_calculables']++;

                continue;
            }

            $resumen['margen_periodo'] = Decimal::sumar($resumen['margen_periodo'], $fila['margen_periodo'] ?? '0', 6);
            $resumen['venta_neta_calculable'] = Decimal::sumar($resumen['venta_neta_calculable'], $ventaNeta, 2);
        }

        $resumen['margen_periodo_pct'] = $this->porcentaje($resumen['margen_periodo'], $resumen['venta_neta_calculable']);
        // Granel junto a sus presentaciones, de la menor a la mayor.
        usort($filas, fn (array $a, array $b) => strcmp((string) $a['granel'], (string) $b['granel']) ?: Decimal::comparar($a['_factor'], $b['_factor']));
        $recortado = count($filas) > self::MAX_PRODUCTOS;

        $metodo = 'Margen sobre materiales = precio − costo promedio actual. Precio neto = (ventas con descuentos − devoluciones del período) ÷ unidades netas. Por unidad de granel = ÷ granel por unidad.';
        $meta = $this->meta($contexto, 'produccion.margen', $periodo, 'estimated', $metodo, [
            'note' => 'Estimado: las ventas no guardan el costo del momento (costo promedio actual). Margen sobre materiales, no utilidad.',
        ]);
        $t = fn (string $c, string $l) => $this->col($c, $l, 'string');
        $n = fn (string $c, string $l) => $this->col($c, $l, 'number');
        $d = fn (string $c, string $l) => $this->col($c, $l, 'money');
        $p = fn (string $c, string $l) => $this->col($c, $l, 'percent');
        $i = fn (string $c, string $l) => $this->col($c, $l, 'integer');

        return [
            $this->dataset('resumen', [
                $i('productos', __('Productos')), $i('con_ventas', __('Con ventas')), $i('no_calculables', __('No calculables')),
                $d('venta_neta', __('Venta neta')), $d('margen_periodo', __('Margen sobre materiales del período')), $p('margen_periodo_pct', __('Margen sobre materiales %')),
            ], [$resumen], $meta, 'Resumen (una fila). margen_periodo y su % solo con los productos calculables (costo > 0). Margen sobre materiales, no utilidad.'),
            $this->conAcciones($this->dataset('margenes', [
                $t('producto', __('Producto')), $t('origen', __('Origen')), $t('granel', __('Granel')), $t('unidad_granel', __('Unidad del granel')),
                $n('granel_por_unidad', __('Granel por unidad')), $d('precio_lista', __('Precio de lista')), $d('costo_materiales', __('Costo de materiales')),
                $d('margen_lista', __('Margen de lista')), $p('margen_lista_pct', __('Margen de lista %')), $n('unidades_netas', __('Unidades vendidas netas')),
                $d('venta_neta', __('Venta neta')), $d('precio_neto', __('Precio neto vendido')), $d('margen_neto', __('Margen neto por unidad')),
                $p('margen_neto_pct', __('Margen neto %')), $d('margen_periodo', __('Margen del período')),
                $d('precio_lista_por_unidad_granel', __('Precio de lista por unidad de granel')), $d('margen_lista_por_unidad_granel', __('Margen de lista por unidad de granel')),
                $d('margen_neto_por_unidad_granel', __('Margen neto por unidad de granel')), $t('estado', __('Estado')),
            ], array_slice($filas, 0, self::MAX_PRODUCTOS), $meta, 'Por producto, el granel junto a sus presentaciones. Los valores «por unidad de granel» permiten comparar presentaciones de distinto tamaño. '
                .'estado = no calculable si el costo es 0.'.($recortado ? ' Recorte: '.self::MAX_PRODUCTOS.' productos.' : '')),
                $this->acciones($filas)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fila(stdClass $p, string $unidades, string $ventaNeta): array
    {
        $esPresentacion = $p->granel_id !== null;
        $factor = $esPresentacion ? (string) $p->granel_por_unidad : '1';
        $costo = (string) $p->costo_promedio;
        $calculable = Decimal::comparar($costo, '0', 6) > 0;
        $precio = (string) $p->precio_consumidor;
        $precioNeto = Decimal::comparar($unidades, '0') > 0 ? Decimal::dividir($ventaNeta, $unidades, 6) : null;
        $margenLista = $calculable ? Decimal::restar($precio, $costo, 6) : null;
        $margenNeto = $calculable && $precioNeto !== null ? Decimal::restar($precioNeto, $costo, 6) : null;
        $porGranel = fn (?string $valor) => $valor === null || Decimal::comparar($factor, '0') <= 0 ? null : Decimal::dividir($valor, $factor, 6);

        return [
            'producto' => $p->nombre, 'origen' => $esPresentacion ? 'presentación' : 'fabricado',
            'granel' => $esPresentacion ? $p->granel : $p->nombre, 'unidad_granel' => $esPresentacion ? $p->unidad_granel : $p->unidad,
            'granel_por_unidad' => $factor, 'precio_lista' => $precio, 'costo_materiales' => $calculable ? $costo : null,
            'margen_lista' => $margenLista, 'margen_lista_pct' => $margenLista === null ? null : $this->pct($margenLista, $precio),
            'unidades_netas' => $unidades, 'venta_neta' => $ventaNeta, 'precio_neto' => $precioNeto,
            'margen_neto' => $margenNeto, 'margen_neto_pct' => $margenNeto === null ? null : $this->pct($margenNeto, (string) $precioNeto),
            'margen_periodo' => $calculable ? Decimal::restar($ventaNeta, Decimal::multiplicar($unidades, $costo, 6), 6) : null,
            'precio_lista_por_unidad_granel' => $porGranel($precio),
            'margen_lista_por_unidad_granel' => $porGranel($margenLista), 'margen_neto_por_unidad_granel' => $porGranel($margenNeto),
            'estado' => match (true) {
                ! $calculable => 'no calculable: costo 0 (todavía sin producir ni envasar)',
                Decimal::comparar($unidades, '0') <= 0 => 'sin ventas netas en el período',
                default => 'calculado (estimado)',
            },
            '_factor' => $factor,
            '_id' => (int) $p->id,
        ];
    }

    /**
     * «Ver producto» (§ UC-25): los de menor y los de mayor margen de lista sobre materiales, para que el enlace sirva
     * tanto si preguntan por el que menos deja como por el que más; los no calculables, al final.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return list<AccionPropuesta>
     */
    private function acciones(array $filas): array
    {
        $calculables = array_values(array_filter($filas, fn (array $f): bool => $f['margen_lista_pct'] !== null));
        usort($calculables, fn (array $a, array $b): int => Decimal::comparar((string) $a['margen_lista_pct'], (string) $b['margen_lista_pct'], 1));
        $mitad = intdiv(CatalogoAcciones::MAXIMO_OFRECIDAS, 2);
        $elegidas = count($calculables) <= CatalogoAcciones::MAXIMO_OFRECIDAS ? $calculables
            : [...array_slice($calculables, 0, $mitad), ...array_slice($calculables, -$mitad)];

        return array_map(fn (array $f) => CatalogoAcciones::crear('ver_producto', (int) $f['_id'], __('Ver producto :producto', ['producto' => (string) $f['producto']])),
            [...$elegidas, ...array_filter($filas, fn (array $f): bool => $f['margen_lista_pct'] === null)]);
    }

    /** Parte de un precio en porcentaje con 1 decimal (6 decimales de precisión); null si el precio no es positivo. */
    private function pct(string $parte, string $total): ?string
    {
        return Decimal::comparar($total, '0', 6) <= 0 ? null
            : Decimal::redondear(Decimal::dividir(Decimal::multiplicar($parte, '100', 6), $total, 6), 1);
    }

    /**
     * Unidades e importe neto vendidos por producto: el descuento global de cada venta se reparte entre todas sus
     * líneas como en la devolución (orden de la línea), así la suma por producto respeta el total de la venta.
     *
     * @param  list<int>  $productos
     * @return array<int, array{cantidad: string, importe: string}>
     */
    private function ventas(int $empresaId, array $productos, CarbonImmutable $inicio, CarbonImmutable $fin): array
    {
        if ($productos === []) {
            return [];
        }

        $lineas = DB::table('venta_items as vi')
            ->join('ventas as v', fn (JoinClause $j) => $j->on('v.id', '=', 'vi.venta_id')->on('v.empresa_id', '=', 'vi.empresa_id'))
            ->where('vi.empresa_id', $empresaId)
            ->whereIn('vi.producto_id', $productos)
            ->where('v.fecha', '>=', $inicio)
            ->where('v.fecha', '<', $fin)
            ->get(['vi.id', 'vi.venta_id', 'vi.producto_id', 'vi.cantidad', 'vi.total', 'v.descuento_global']);
        $conDescuento = array_values(array_unique($lineas->filter(fn (stdClass $l) => Decimal::comparar((string) $l->descuento_global, '0', 2) > 0)
            ->pluck('venta_id')->map(fn ($id): int => (int) $id)->all()));
        $netos = [];

        foreach (array_chunk($conDescuento, 500) as $grupo) {
            $todas = DB::table('venta_items as vi')
                ->join('ventas as v', fn (JoinClause $j) => $j->on('v.id', '=', 'vi.venta_id')->on('v.empresa_id', '=', 'vi.empresa_id'))
                ->where('vi.empresa_id', $empresaId)
                ->whereIn('vi.venta_id', $grupo)
                ->orderBy('vi.venta_id')->orderBy('vi.orden')->orderBy('vi.id')
                ->get(['vi.id', 'vi.venta_id', 'vi.total', 'v.descuento_global'])
                ->groupBy('venta_id');

            foreach ($todas as $items) {
                $netos += CalculadoraDevolucion::netosPorLinea(
                    $items->mapWithKeys(fn (stdClass $i): array => [(int) $i->id => (string) $i->total])->all(),
                    (string) $items->first()->descuento_global,
                );
            }
        }

        $ventas = [];

        foreach ($lineas as $linea) {
            $id = (int) $linea->producto_id;
            $ventas[$id] ??= ['cantidad' => '0', 'importe' => '0.00'];
            $ventas[$id]['cantidad'] = Decimal::sumar($ventas[$id]['cantidad'], (string) $linea->cantidad);
            $ventas[$id]['importe'] = Decimal::sumar($ventas[$id]['importe'], $netos[(int) $linea->id] ?? (string) $linea->total, 2);
        }

        return $ventas;
    }
}
