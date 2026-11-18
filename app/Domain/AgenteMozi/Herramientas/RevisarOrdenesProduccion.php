<?php

namespace App\Domain\AgenteMozi\Herramientas;

use App\Domain\AgenteMozi\Acciones\CatalogoAcciones;
use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\DTOs\AccionPropuesta;
use App\Enums\EstadoOrdenProduccion;
use App\Models\OrdenProduccion;
use App\Models\Producto;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * review_production_orders (UC-20, AGENTE_MOZI_IA.md § 2.1): órdenes emitidas y aprobadas por fecha programada (por
 * defecto de hoy a 7 días, siempre con las atrasadas). Para las emitidas, el requerimiento acumulado de cada materia
 * prima por sucursal en orden de fecha programada y número, contra el stock utilizable: el formulario revisa cada
 * orden sola, así que dos órdenes pueden alcanzar por separado y no juntas. Una aprobada ya descontó su materia prima:
 * no vuelve a pedirla; se informa el valor descontado (teórico × costo guardado al aprobar). Nunca aprueba ni finaliza.
 */
final class RevisarOrdenesProduccion extends HerramientaProduccion
{
    private const MAX_ORDENES = 300;

    public function nombre(): string
    {
        return 'review_production_orders';
    }

    public function descripcion(): string
    {
        return 'Órdenes de producción en curso: emitidas y aprobadas con fecha programada en el rango (por defecto de hoy a 7 días) más todas las atrasadas. '
            .'Para las emitidas dice si la materia prima alcanza para cada orden sola y en conjunto (requerimiento acumulado por sucursal en orden de fecha programada; una emitida no reserva stock) y los faltantes acumulados por materia prima. '
            .'Para las aprobadas, días desde la aprobación y valor de la materia prima ya descontada. Las finalizadas y anuladas no figuran (para eso usa query_business_data con ordenes_produccion o produccion). Solo lectura.';
    }

    public function parametros(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date_from' => ['type' => 'string', 'description' => 'Primer día de la fecha programada (AAAA-MM-DD). Por defecto hoy.'],
                'date_to' => ['type' => 'string', 'description' => 'Último día de la fecha programada (AAAA-MM-DD), incluido. Por defecto hoy + 7 días.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function reglas(): array
    {
        return $this->reglasPeriodo();
    }

    public function avance(): string
    {
        return __('Revisando las órdenes de producción…');
    }

    public function ejecutar(ContextoAgente $contexto, array $argumentos): array
    {
        $empresaId = $contexto->empresaId;
        $hoy = $contexto->fechaReferencia->format('Y-m-d');
        $desde = is_string($argumentos['date_from'] ?? null) ? $argumentos['date_from'] : $hoy;
        $hasta = is_string($argumentos['date_to'] ?? null) ? $argumentos['date_to']
            : CarbonImmutable::createFromFormat('!Y-m-d', $desde)->addDays(7)->format('Y-m-d');

        if ($desde > $hasta) {
            throw new ArgumentoInvalido('date_from debe ser anterior o igual a date_to.');
        }

        if (CarbonImmutable::createFromFormat('!Y-m-d', $desde)->diffInDays(CarbonImmutable::createFromFormat('!Y-m-d', $hasta)) > 366) {
            throw new ArgumentoInvalido('El rango máximo es de un año.');
        }

        $abiertas = [EstadoOrdenProduccion::Emitida->value, EstadoOrdenProduccion::Aprobada->value];
        $ordenes = DB::table('ordenes_produccion as o')
            ->join('productos as p', fn (JoinClause $j) => $j->on('p.id', '=', 'o.producto_id')->on('p.empresa_id', '=', 'o.empresa_id'))
            ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
            ->join('sucursales as s', fn (JoinClause $j) => $j->on('s.id', '=', 'o.sucursal_id')->on('s.empresa_id', '=', 'o.empresa_id'))
            ->where('o.empresa_id', $empresaId)
            ->whereIn('o.estado', $abiertas)
            ->where(fn (Builder $q) => $q->whereBetween('o.fecha_programada', [$desde, $hasta])->orWhere('o.fecha_programada', '<', $hoy))
            ->orderBy('o.fecha_programada')->orderBy('o.numero')
            ->limit(self::MAX_ORDENES + 1)
            ->select(['o.id', 'o.numero', 'o.estado', 'o.sucursal_id', 's.nombre as sucursal', 'o.cantidad_planificada', 'o.fecha_programada',
                'u.nombre as unidad', DB::raw(Producto::sqlNombreCompleto('p', 'u').' AS producto')])
            ->selectRaw('DATE('.$this->local('o.aprobada_en').') AS fecha_aprobacion', [$this->desfase($contexto)])
            ->get();
        $recortado = $ordenes->count() > self::MAX_ORDENES;
        $ordenes = $ordenes->take(self::MAX_ORDENES);
        $lineas = DB::table('orden_produccion_items as i')
            ->join('productos as m', fn (JoinClause $j) => $j->on('m.id', '=', 'i.producto_id')->on('m.empresa_id', '=', 'i.empresa_id'))
            ->join('unidades_medida as mu', 'mu.id', '=', 'm.unidad_medida_id')
            ->where('i.empresa_id', $empresaId)
            ->whereIn('i.orden_id', $ordenes->pluck('id')->all())
            ->orderBy('i.orden_id')->orderBy('i.orden')
            ->get(['i.orden_id', 'i.producto_id', 'i.cantidad_teorica', 'i.costo_unitario', 'mu.nombre as unidad', DB::raw(Producto::sqlNombreCompleto('m', 'mu').' AS nombre')])
            ->groupBy('orden_id');
        $emitidas = $ordenes->where('estado', EstadoOrdenProduccion::Emitida->value);
        $stock = $this->stockUtilizable($contexto,
            array_values($emitidas->pluck('sucursal_id')->map(fn ($id): int => (int) $id)->unique()->all()),
            array_values($emitidas->flatMap(fn (stdClass $o) => $lineas->get($o->id, collect())->pluck('producto_id'))->map(fn ($id): int => (int) $id)->unique()->all()));

        $acumulado = [];
        $faltantes = [];
        $filas = [];
        $sinMateria = 0;
        $atrasadas = 0;
        $valorAprobadas = '0';

        foreach ($ordenes as $orden) {
            $sucursalId = (int) $orden->sucursal_id;
            $fecha = substr((string) $orden->fecha_programada, 0, 10);
            $atraso = $fecha < $hoy ? (int) CarbonImmutable::createFromFormat('!Y-m-d', $fecha)->diffInDays(CarbonImmutable::createFromFormat('!Y-m-d', $hoy)) : 0;
            $atrasadas += $atraso > 0 ? 1 : 0;
            $fila = [
                'orden' => OrdenProduccion::formatearCodigo((int) $orden->numero), 'estado' => $orden->estado, 'producto' => $orden->producto, 'sucursal' => $orden->sucursal,
                'fecha_programada' => $fecha, 'dias_atraso' => $atraso, 'planificado' => (string) $orden->cantidad_planificada, 'unidad' => $orden->unidad,
                'alcanza_sola' => null, 'alcanza_en_orden' => null, 'falta' => null, 'dias_desde_aprobacion' => null, 'valor_materia_descontada' => null,
                '_id' => (int) $orden->id,
            ];

            if ($orden->estado === EstadoOrdenProduccion::Aprobada->value) {
                $valor = '0';

                foreach ($lineas->get($orden->id, collect()) as $linea) {
                    $valor = Decimal::sumar($valor, Decimal::multiplicar((string) $linea->cantidad_teorica, (string) ($linea->costo_unitario ?? '0'), 6), 6);
                }

                $valorAprobadas = Decimal::sumar($valorAprobadas, $valor, 6);
                $fila['valor_materia_descontada'] = $valor;
                $fila['dias_desde_aprobacion'] = $orden->fecha_aprobacion === null ? null
                    : (int) CarbonImmutable::createFromFormat('!Y-m-d', substr((string) $orden->fecha_aprobacion, 0, 10))->diffInDays(CarbonImmutable::createFromFormat('!Y-m-d', $hoy));
                $filas[] = $fila;

                continue;
            }

            $sola = true;
            $junta = true;
            $falta = [];

            foreach ($lineas->get($orden->id, collect()) as $linea) {
                $materiaId = (int) $linea->producto_id;
                $utilizable = $stock[$sucursalId][$materiaId]['utilizable'] ?? '0';
                $necesario = (string) $linea->cantidad_teorica;
                $total = Decimal::sumar($acumulado[$sucursalId][$materiaId] ?? '0', $necesario);
                $acumulado[$sucursalId][$materiaId] = $total;
                $clave = "{$sucursalId}:{$materiaId}";
                $faltantes[$clave] ??= ['sucursal' => $orden->sucursal, 'materia_prima' => $linea->nombre, 'unidad' => $linea->unidad, 'requerido' => '0',
                    'utilizable' => $utilizable, 'faltante' => '0.000', 'ordenes' => 0, 'primera_orden_sin_stock' => null];
                $faltantes[$clave]['requerido'] = $total;
                $faltantes[$clave]['ordenes']++;

                if (Decimal::comparar($necesario, $utilizable) > 0) {
                    $sola = false;
                }

                if (Decimal::comparar($total, $utilizable) > 0) {
                    $junta = false;
                    $falta[] = $linea->nombre;
                    $faltantes[$clave]['faltante'] = Decimal::restar($total, $utilizable);
                    $faltantes[$clave]['primera_orden_sin_stock'] ??= $fila['orden'];
                }
            }

            $sinMateria += $junta ? 0 : 1;
            $fila['alcanza_sola'] = $sola ? 'sí' : 'no';
            $fila['alcanza_en_orden'] = $junta ? 'sí' : 'no';
            $fila['falta'] = $falta === [] ? null : implode(', ', $falta);
            $filas[] = $fila;
        }

        $faltantes = array_values(array_filter($faltantes, fn (array $f) => Decimal::comparar($f['faltante'], '0') > 0));
        usort($faltantes, fn (array $a, array $b) => [$a['sucursal'], $a['materia_prima']] <=> [$b['sucursal'], $b['materia_prima']]);
        $metodo = "Emitidas y aprobadas con fecha programada del {$desde} al {$hasta} y las atrasadas; stock actual menos lotes vencidos; requerimiento acumulado en orden de fecha programada.";
        $meta = $this->meta($contexto, 'produccion.ordenes', null, metodo: $metodo, extra: ['period' => ['from' => $desde, 'to' => $hasta]]);
        $n = fn (string $c, string $l) => $this->col($c, $l, 'number');
        $t = fn (string $c, string $l) => $this->col($c, $l, 'string');
        $i = fn (string $c, string $l) => $this->col($c, $l, 'integer');

        return [
            $this->dataset('resumen', [
                $i('emitidas', __('Emitidas')), $i('aprobadas', __('Aprobadas')), $i('atrasadas', __('Atrasadas')),
                $i('emitidas_sin_materia_prima', __('Emitidas sin materia prima suficiente')), $i('materias_con_faltante', __('Materias primas con faltante')),
                $this->col('valor_descontado_aprobadas', __('Materia prima descontada en aprobadas'), 'money'),
            ], [[
                'emitidas' => $emitidas->count(), 'aprobadas' => $ordenes->count() - $emitidas->count(), 'atrasadas' => $atrasadas,
                'emitidas_sin_materia_prima' => $sinMateria, 'materias_con_faltante' => count($faltantes), 'valor_descontado_aprobadas' => $valorAprobadas,
            ]], $meta, 'Resumen (una fila). emitidas_sin_materia_prima = emitidas que no alcanzan con el requerimiento acumulado de su sucursal.'),
            $this->conAcciones($this->dataset('ordenes', [
                $t('orden', __('Orden')), $t('estado', __('Estado')), $t('producto', __('Producto')), $t('sucursal', __('Sucursal')),
                $this->col('fecha_programada', __('Fecha programada'), 'date'), $i('dias_atraso', __('Días de atraso')), $n('planificado', __('Planificado')),
                $t('unidad', __('Unidad')), $t('alcanza_sola', __('Alcanza sola')), $t('alcanza_en_orden', __('Alcanza en conjunto')), $t('falta', __('Materia prima que falta')),
                $i('dias_desde_aprobacion', __('Días desde la aprobación')), $this->col('valor_materia_descontada', __('Materia prima descontada'), 'money'),
            ], $filas, $meta, 'Órdenes por fecha programada. alcanza_sola: la orden sola contra el stock (lo que avisa su pantalla); alcanza_en_orden: sumando las emitidas anteriores de la misma sucursal. Las aprobadas ya descontaron su materia prima.'
                .($recortado ? ' Recorte: primeras '.self::MAX_ORDENES.' órdenes.' : '')),
                $this->acciones($filas)),
            $this->dataset('faltantes', [
                $t('sucursal', __('Sucursal')), $t('materia_prima', __('Materia prima')), $t('unidad', __('Unidad')), $n('requerido', __('Requerido por las emitidas')),
                $n('utilizable', __('Utilizable')), $n('faltante', __('Faltante')), $i('ordenes', __('Órdenes que la piden')), $t('primera_orden_sin_stock', __('Primera orden sin stock')),
            ], $faltantes, $meta, 'Materias primas cuyo requerimiento acumulado de las emitidas supera el stock utilizable de la sucursal.'),
        ];
    }

    /**
     * «Abrir orden» (§ UC-20): primero las emitidas que no alcanzan en conjunto y las atrasadas. Nunca aprueba ni finaliza.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return list<AccionPropuesta>
     */
    private function acciones(array $filas): array
    {
        $prioridad = fn (array $f): array => [$f['alcanza_en_orden'] === 'no' ? 0 : 1, (int) $f['dias_atraso'] > 0 ? 0 : 1];
        usort($filas, fn (array $a, array $b): int => $prioridad($a) <=> $prioridad($b));

        return array_map(fn (array $f) => CatalogoAcciones::crear('abrir_orden', (int) $f['_id'],
            __('Abrir orden :orden · :producto', ['orden' => (string) $f['orden'], 'producto' => (string) $f['producto']])),
            array_slice($filas, 0, CatalogoAcciones::MAXIMO_OFRECIDAS));
    }
}
