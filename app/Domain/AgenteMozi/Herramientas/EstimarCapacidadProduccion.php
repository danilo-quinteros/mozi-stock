<?php

namespace App\Domain\AgenteMozi\Herramientas;

use App\Domain\AgenteMozi\Acciones\CatalogoAcciones;
use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\Produccion\Escalado;
use App\Enums\EstadoOrdenProduccion;
use App\Models\Producto;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * estimate_production_capacity (UC-19, AGENTE_MOZI_IA.md § 2.1): cuánto se puede producir con la materia prima que hay
 * y cuál limita, por receta vigente y sucursal activa. Utilizable = saldo − lotes vencidos (como DisponibilidadMaterias).
 * Con cantidad, escala la receta como una orden (Escalado, M5) y da el faltante de cada línea: el mismo aviso del
 * formulario de la orden. Aparte, la capacidad que quedaría si se aprobaran las órdenes emitidas de la sucursal (una
 * emitida no reserva stock: solo aprobar descuenta). Nunca emite ni aprueba nada.
 */
final class EstimarCapacidadProduccion extends HerramientaProduccion
{
    private const MAX_RECETAS = 100;

    private const MAX_FILAS = 500;

    public function nombre(): string
    {
        return 'estimate_production_capacity';
    }

    public function descripcion(): string
    {
        return 'Capacidad de producción con la materia prima actual: por producto con receta vigente y sucursal activa, el máximo producible y la materia prima que limita '
            .'(utilizable = stock − lotes vencidos), y el máximo que quedaría si se aprobaran las órdenes emitidas de esa sucursal (una orden emitida no reserva stock). '
            .'Con cantidad, el necesario y el faltante de cada materia prima para producir esa cantidad, igual que el aviso de Nueva orden. '
            .'producto y sucursal por nombre o código; sin producto revisa todos los fabricados, sin sucursal todas las activas. Solo lectura: para producir, el usuario emite la orden en Producción › Órdenes.';
    }

    public function parametros(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'producto' => ['type' => 'string', 'description' => 'Producto fabricado (nombre o código). Opcional.'],
                'cantidad' => ['type' => 'string', 'description' => 'Cantidad a producir en la unidad del producto, con punto decimal (p. ej. 500). Requiere producto.'],
                'sucursal' => ['type' => 'string', 'description' => 'Sucursal por nombre. Opcional.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function reglas(): array
    {
        return [
            'producto' => ['sometimes', 'nullable', 'string', 'max:120'],
            'cantidad' => ['sometimes', 'nullable', 'regex:/^\d{1,15}(\.\d{1,3})?$/'],
            'sucursal' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    public function avance(): string
    {
        return __('Calculando la capacidad de producción…');
    }

    public function ejecutar(ContextoAgente $contexto, array $argumentos): array
    {
        $empresaId = $contexto->empresaId;
        $conReceta = fn (Builder $q) => $q->whereExists(fn (Builder $r) => $r->selectRaw('1')->from('recetas as rv')
            ->whereColumn('rv.empresa_id', 'p.empresa_id')->whereColumn('rv.producto_vigente', 'p.id'));
        $producto = $this->producto($contexto, $argumentos['producto'] ?? null, Producto::CLASE_VENTA, 'un producto con receta', $conReceta);
        $sucursal = $this->sucursal($contexto, $argumentos['sucursal'] ?? null);
        $cantidad = isset($argumentos['cantidad']) ? Decimal::normalizar((string) $argumentos['cantidad'], 3) : null;

        if ($cantidad !== null && ($producto === null || Decimal::comparar($cantidad, '0') <= 0)) {
            throw new ArgumentoInvalido($producto === null ? 'cantidad requiere producto: pregunta qué producto quiere producir.' : 'cantidad debe ser mayor que cero.');
        }

        if ($cantidad !== null && Escalado::esEntera($producto->tipo_venta, (bool) $producto->admite_decimales) && ! Decimal::esEntero($cantidad)) {
            throw new ArgumentoInvalido("{$producto->nombre} se produce en unidades enteras: usa una cantidad sin decimales.");
        }

        $recetas = DB::table('recetas as r')
            ->join('productos as p', fn (JoinClause $j) => $j->on('p.id', '=', 'r.producto_id')->on('p.empresa_id', '=', 'r.empresa_id'))
            ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
            ->where('r.empresa_id', $empresaId)
            ->where('r.vigente', true)
            ->where('p.clase', Producto::CLASE_VENTA)
            ->when($producto !== null, fn (Builder $q) => $q->where('p.id', $producto->id), fn (Builder $q) => $q->where('p.activo', true))
            ->orderBy('p.nombre')
            ->limit(self::MAX_RECETAS)
            ->get(['r.id', 'r.producto_id', 'r.cantidad_base', 'r.activa', 'r.version', 'p.activo', 'p.tipo_venta', 'u.admite_decimales', 'u.nombre as unidad',
                DB::raw(Producto::sqlNombreCompleto('p', 'u').' AS nombre')]);
        $sucursales = DB::table('sucursales')->where('empresa_id', $empresaId)->where('activa', true)
            ->when($sucursal !== null, fn (Builder $q) => $q->where('id', $sucursal->id))
            ->orderBy('nombre')->get(['id', 'nombre']);
        $lineas = DB::table('receta_items as i')
            ->join('productos as m', fn (JoinClause $j) => $j->on('m.id', '=', 'i.producto_id')->on('m.empresa_id', '=', 'i.empresa_id'))
            ->join('unidades_medida as mu', 'mu.id', '=', 'm.unidad_medida_id')
            ->where('i.empresa_id', $empresaId)
            ->whereIn('i.receta_id', $recetas->pluck('id')->all())
            ->orderBy('i.receta_id')->orderBy('i.orden')
            ->get(['i.receta_id', 'i.producto_id', 'i.cantidad', 'm.activo', 'm.tipo_venta', 'mu.admite_decimales', 'mu.nombre as unidad',
                DB::raw(Producto::sqlNombreCompleto('m', 'mu').' AS nombre')])
            ->groupBy('receta_id');
        $materias = array_values($lineas->flatten(1)->pluck('producto_id')->map(fn ($id): int => (int) $id)->unique()->all());
        $sucursalIds = array_values($sucursales->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $stock = $this->stockUtilizable($contexto, $sucursalIds, $materias);
        $emitidas = $this->pedidoEmitidas($empresaId, $sucursalIds, $materias);

        $capacidad = [];
        $detalle = [];

        foreach ($recetas as $receta) {
            $propias = array_values(array_map(fn (stdClass $l): array => [
                'producto_id' => (int) $l->producto_id,
                'cantidad' => (string) $l->cantidad,
                'entera' => Escalado::esEntera($l->tipo_venta === null ? null : (string) $l->tipo_venta, (bool) $l->admite_decimales),
                'nombre' => (string) $l->nombre,
                'unidad' => (string) $l->unidad,
                'activo' => (bool) $l->activo,
            ], $lineas->get($receta->id, collect())->all()));
            $base = (string) $receta->cantidad_base;
            $entero = Escalado::esEntera($receta->tipo_venta === null ? null : (string) $receta->tipo_venta, (bool) $receta->admite_decimales);
            $inactivas = array_column(array_filter($propias, fn (array $l) => ! $l['activo']), 'nombre');
            $bloqueo = match (true) {
                ! $receta->activo => 'producto inactivo',
                ! $receta->activa => 'receta desactivada',
                $propias === [] => 'receta sin materias primas',
                $inactivas !== [] => 'materia prima inactiva: '.implode(', ', $inactivas),
                default => null,
            };
            $nombres = array_column($propias, 'nombre', 'producto_id');

            foreach ($sucursales as $s) {
                $sucursalId = (int) $s->id;
                $utilizable = array_map(fn (array $st) => $st['utilizable'], $stock[$sucursalId] ?? []);
                $tras = [];

                foreach ($utilizable as $materiaId => $cantidadMateria) {
                    $resto = Decimal::restar($cantidadMateria, $emitidas[$sucursalId][$materiaId] ?? '0');
                    $tras[$materiaId] = Decimal::comparar($resto, '0') < 0 ? '0.000' : $resto;
                }

                $ahora = $this->maximo($propias, $base, $entero, $utilizable);
                $despues = $this->maximo($propias, $base, $entero, $tras);
                $necesarias = $cantidad === null ? [] : array_combine(array_column($propias, 'producto_id'),
                    array_map(fn (array $l) => Escalado::teorica($cantidad, $base, $l['cantidad'], $l['entera']), $propias));
                $faltan = 0;

                foreach ($propias as $linea) {
                    $id = $linea['producto_id'];
                    $tengo = $stock[$sucursalId][$id] ?? ['existencia' => '0', 'vencido' => '0', 'utilizable' => '0'];
                    $necesario = $necesarias[$id] ?? null;
                    $faltante = null;

                    if ($necesario !== null) {
                        $resta = Decimal::restar($necesario, $tengo['utilizable']);
                        $faltante = Decimal::comparar($resta, '0') > 0 ? $resta : '0.000';
                        $faltan += Decimal::comparar($faltante, '0') > 0 ? 1 : 0;
                    }

                    if (count($detalle) < self::MAX_FILAS) {
                        $detalle[] = [
                            'producto' => $receta->nombre, 'sucursal' => $s->nombre, 'materia_prima' => $linea['nombre'], 'unidad' => $linea['unidad'],
                            'cantidad_receta' => $linea['cantidad'], 'cantidad_base' => $base, 'existencia' => $tengo['existencia'], 'vencido' => $tengo['vencido'],
                            'utilizable' => $tengo['utilizable'], 'pedido_emitidas' => $emitidas[$sucursalId][$id] ?? '0',
                            'alcanza_para' => $ahora['por_linea'][$id] ?? null, 'limita' => $ahora['limita'] === $id && $bloqueo === null ? 'sí' : 'no',
                            'necesario' => $necesario, 'faltante' => $faltante,
                        ];
                    }
                }

                $capacidad[] = [
                    'producto' => $receta->nombre, 'sucursal' => $s->nombre, 'unidad' => $receta->unidad, 'version_receta' => $receta->version,
                    'cantidad_base' => $base,
                    'maximo' => $bloqueo === null ? $ahora['maximo'] : '0',
                    'limita' => $bloqueo === null && $ahora['limita'] !== null ? $nombres[$ahora['limita']] : null,
                    'maximo_tras_emitidas' => $bloqueo === null ? $despues['maximo'] : '0',
                    'estado' => $bloqueo ?? (Decimal::comparar($ahora['maximo'], '0') > 0 ? 'se puede producir' : 'sin materia prima suficiente'),
                    'cantidad_pedida' => $cantidad,
                    'alcanza_pedido' => $cantidad === null ? null : ($bloqueo === null && $faltan === 0 ? 'sí' : 'no'),
                    'lineas_con_faltante' => $cantidad === null ? null : $faltan,
                ];
            }
        }

        $meta = $this->meta($contexto, 'produccion.recetas', null, metodo: 'Recetas vigentes; stock actual de cada sucursal activa menos lotes vencidos; cantidades escaladas como una orden (enteras hacia arriba).');
        $n = fn (string $c, string $l) => $this->col($c, $l, 'number');
        $t = fn (string $c, string $l) => $this->col($c, $l, 'string');

        return [
            // Acción (§ UC-19): «Nueva orden», sin datos precargados (IA-P3).
            $this->conAcciones($this->dataset('capacidad', [
                $t('producto', __('Producto')), $t('sucursal', __('Sucursal')), $t('unidad', __('Unidad')), $this->col('version_receta', __('Versión de receta'), 'integer'),
                $n('cantidad_base', __('Cantidad base')), $n('maximo', __('Máximo producible')), $t('limita', __('Materia prima que limita')),
                $n('maximo_tras_emitidas', __('Máximo si se aprueban las emitidas')), $t('estado', __('Estado')),
                $n('cantidad_pedida', __('Cantidad pedida')), $t('alcanza_pedido', __('Alcanza para lo pedido')), $this->col('lineas_con_faltante', __('Líneas con faltante'), 'integer'),
            ], $capacidad, $meta, 'Una fila por producto y sucursal. maximo nunca es negativo; 0 con estado si no se puede producir. maximo_tras_emitidas descuenta lo que piden las órdenes emitidas de la sucursal (todas, de cualquier producto). Con cantidad: alcanza_pedido y lineas_con_faltante.'
                .($recetas->count() >= self::MAX_RECETAS ? ' Recorte: solo los primeros '.self::MAX_RECETAS.' productos con receta.' : '')),
                $capacidad === [] ? [] : [CatalogoAcciones::crear('nueva_orden', null, __('Nueva orden de producción'))]),
            $this->dataset('lineas', [
                $t('producto', __('Producto')), $t('sucursal', __('Sucursal')), $t('materia_prima', __('Materia prima')), $t('unidad', __('Unidad')),
                $n('cantidad_receta', __('Cantidad en la receta')), $n('cantidad_base', __('Cantidad base')), $n('existencia', __('Existencia')),
                $n('vencido', __('En lotes vencidos')), $n('utilizable', __('Utilizable')), $n('pedido_emitidas', __('Pedido por órdenes emitidas')),
                $n('alcanza_para', __('Alcanza para')), $t('limita', __('Limita')), $n('necesario', __('Necesario')), $n('faltante', __('Faltante')),
            ], $detalle, $meta, 'Materias primas de cada receta por sucursal. alcanza_para = cuánto producto alcanza con esa materia prima sola. necesario y faltante solo con cantidad.'
                .(count($detalle) >= self::MAX_FILAS ? ' Recorte: primeras '.self::MAX_FILAS.' líneas.' : '')),
        ];
    }

    /**
     * Lo que piden las órdenes emitidas por sucursal y materia prima (aún no descontado).
     *
     * @param  list<int>  $sucursales
     * @param  list<int>  $materias
     * @return array<int, array<int, string>>
     */
    private function pedidoEmitidas(int $empresaId, array $sucursales, array $materias): array
    {
        if ($sucursales === [] || $materias === []) {
            return [];
        }

        $pedido = [];

        DB::table('orden_produccion_items as i')
            ->join('ordenes_produccion as o', fn (JoinClause $j) => $j->on('o.id', '=', 'i.orden_id')->on('o.empresa_id', '=', 'i.empresa_id'))
            ->where('i.empresa_id', $empresaId)
            ->where('o.estado', EstadoOrdenProduccion::Emitida->value)
            ->whereIn('o.sucursal_id', $sucursales)
            ->whereIn('i.producto_id', $materias)
            ->groupBy('o.sucursal_id', 'i.producto_id')
            ->get(['o.sucursal_id', 'i.producto_id', DB::raw('SUM(i.cantidad_teorica) AS cantidad')])
            ->each(function (stdClass $fila) use (&$pedido): void {
                $pedido[(int) $fila->sucursal_id][(int) $fila->producto_id] = (string) $fila->cantidad;
            });

        return $pedido;
    }
}
