<?php

namespace App\Domain\AgenteMozi\Herramientas;

use App\Domain\AgenteMozi\Acciones\CatalogoAcciones;
use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\Inventario\Consultas\TrazabilidadLote;
use App\Models\Envasado;
use App\Models\OrdenProduccion;
use App\Models\Producto;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * trace_lot (UC-27, AGENTE_MOZI_IA.md § 2.1): trazabilidad de un número de lote dentro de la empresa. El granel y sus
 * presentaciones comparten número, así que se buscan todos los lotes con ese número (nunca de otra empresa). Hacia
 * adelante: cada movimiento con su documento, como la pantalla del lote (TrazabilidadLote): ventas con cliente,
 * devoluciones, traspasos, envasados. Hacia atrás, solo con Producción: la orden que creó el lote y los lotes de
 * materia prima que consumió (operaciones de consumo y cierre de la orden). Administrador con Lotes (analizar-lotes).
 */
final class TrazarLote extends HerramientaNegocio
{
    private const MAX_LOTES = 20;

    private const MAX_MOVIMIENTOS = 300;

    public function nombre(): string
    {
        return 'trace_lot';
    }

    public function descripcion(): string
    {
        return 'Trazabilidad de un número de lote (p. ej. OP-0015 o el número del proveedor): los productos con ese lote (el granel y sus presentaciones comparten número), su stock actual por sucursal '
            .'y cada movimiento con su documento (compra y proveedor, venta y cliente, devolución, traspaso, ajuste, orden de producción, envasado), del más reciente al más antiguo. '
            .'Con Producción, también hacia atrás: la orden que produjo el lote y los lotes de materia prima que consumió (solo las materias primas que controlan vencimiento tienen lote). '
            .'El stock sin número (regularización) no se puede rastrear por número.';
    }

    public function parametros(): array
    {
        return [
            'type' => 'object',
            'properties' => ['lote' => ['type' => 'string', 'description' => 'Número de lote tal como lo dijo el usuario.']],
            'required' => ['lote'],
            'additionalProperties' => false,
        ];
    }

    public function reglas(): array
    {
        return ['lote' => ['required', 'string', 'max:60']];
    }

    public function permiso(): string
    {
        return 'analizar-lotes';
    }

    public function avance(): string
    {
        return __('Siguiendo el lote…');
    }

    public function ejecutar(ContextoAgente $contexto, array $argumentos): array
    {
        $empresaId = $contexto->empresaId;
        $numero = trim((string) $argumentos['lote']);
        $lotes = $this->lotes($empresaId, $numero);

        // «OP-15» u «op 15» → OP-0015, el número que recibe lo producido.
        if ($lotes->isEmpty() && preg_match('/^OP[-\s]?0*(\d{1,9})$/i', $numero, $m) === 1) {
            $numero = OrdenProduccion::formatearCodigo((int) $m[1]);
            $lotes = $this->lotes($empresaId, $numero);
        }

        if ($lotes->isEmpty()) {
            throw new ArgumentoInvalido("No hay ningún lote con el número «{$numero}» en la empresa. Pide el número exacto (el stock sin número es de regularización y no se rastrea por número).");
        }

        $hoy = $contexto->fechaReferencia->format('Y-m-d');
        $filasLotes = [];
        $movimientos = [];

        foreach ($lotes as $lote) {
            $stock = TrazabilidadLote::stock($empresaId, (int) $lote->id);
            $total = array_reduce($stock, fn (string $suma, stdClass $s) => Decimal::sumar($suma, (string) $s->cantidad), '0');
            $vencimiento = $lote->vencimiento === null ? null : substr((string) $lote->vencimiento, 0, 10);
            $filasLotes[] = [
                'producto' => $lote->producto, 'clase' => $lote->clase === Producto::CLASE_MATERIA_PRIMA ? 'materia prima' : 'venta', 'lote' => $lote->numero,
                'vencimiento' => $vencimiento, 'estado' => $vencimiento === null ? 'sin fecha' : ($vencimiento < $hoy ? 'vencido' : 'vigente'),
                'stock_actual' => $total, 'unidad' => $lote->unidad,
                'sucursales' => implode(', ', array_map(fn (stdClass $s) => $s->sucursal.': '.Decimal::simple((string) $s->cantidad), $stock)),
                '_id' => (int) $lote->id,
            ];

            foreach (TrazabilidadLote::movimientos($empresaId, (int) $lote->id)->limit(self::MAX_MOVIMIENTOS)->get() as $mov) {
                $movimientos[] = ['_orden' => (string) $mov->created_at.'#'.str_pad((string) $mov->id, 12, '0', STR_PAD_LEFT)] + $this->movimiento($contexto, $lote->producto, $mov);
            }
        }

        usort($movimientos, fn (array $a, array $b) => strcmp($b['_orden'], $a['_orden']));
        $recortado = count($movimientos) > self::MAX_MOVIMIENTOS;
        $meta = $this->meta($contexto, 'inventario.trazabilidad', null, metodo: "Lote {$numero}: movimientos del libro por lote con su documento (Inventario › Trazabilidad).");
        $t = fn (string $c, string $l) => $this->col($c, $l, 'string');
        $n = fn (string $c, string $l) => $this->col($c, $l, 'number');
        $datasets = [
            // Acción (§ UC-27): «Ver trazabilidad», la pantalla del lote.
            $this->conAcciones($this->dataset('lotes', [
                $t('producto', __('Producto')), $t('clase', __('Clase')), $t('lote', __('Lote')), $this->col('vencimiento', __('Vencimiento'), 'date'),
                $t('estado', __('Estado')), $n('stock_actual', __('Stock actual')), $t('unidad', __('Unidad')), $t('sucursales', __('Stock por sucursal')),
            ], $filasLotes, $meta, 'Productos que tienen un lote con ese número en la empresa y su stock actual.'
                .($lotes->count() >= self::MAX_LOTES ? ' Recorte: primeros '.self::MAX_LOTES.' productos.' : '')),
                array_map(fn (array $l) => CatalogoAcciones::crear('ver_lote', $l['_id'], __('Ver trazabilidad del lote :lote · :producto', ['lote' => $l['lote'], 'producto' => $l['producto']])), $filasLotes)),
            $this->dataset('movimientos', [
                $this->col('fecha', __('Fecha'), 'date'), $t('hora', __('Hora')), $t('producto', __('Producto')), $t('tipo', __('Tipo')), $t('documento', __('Documento')),
                $n('cantidad', __('Cantidad')), $n('saldo_posterior', __('Saldo del lote después')), $t('sucursal', __('Sucursal')), $t('destino', __('Sucursal destino')),
                $t('cliente', __('Cliente')), $t('proveedor', __('Proveedor')), $t('usuario', __('Usuario')), $t('nota', __('Nota')),
            ], array_slice($movimientos, 0, self::MAX_MOVIMIENTOS), $meta, 'Movimientos del lote, del más reciente al más antiguo: cantidad positiva entra, negativa sale (venta, traspaso, consumo, envasado).'
                .($recortado ? ' Recorte: últimos '.self::MAX_MOVIMIENTOS.' movimientos.' : '')),
        ];

        if ($contexto->produccion()) {
            $datasets[] = $this->dataset('origen', [
                $t('orden', __('Orden')), $t('producto', __('Producto fabricado')), $this->col('fecha_finalizacion', __('Fecha de finalización'), 'date'),
                $n('obtenido', __('Obtenido')), $t('materia_prima', __('Materia prima')), $t('lote_materia', __('Lote de materia prima')),
                $this->col('vencimiento_materia', __('Vencimiento de la materia prima'), 'date'), $n('cantidad_consumida', __('Cantidad consumida')), $t('unidad', __('Unidad')),
            ], $this->origen($contexto, array_values($lotes->pluck('id')->map(fn ($id): int => (int) $id)->all())), $meta,
                'Hacia atrás: la orden de producción que creó el lote y los lotes de materia prima que consumió. Las materias primas sin control de vencimiento no tienen lote y no figuran.');
        }

        return $datasets;
    }

    /**
     * @return Collection<int, stdClass>
     */
    private function lotes(int $empresaId, string $numero): Collection
    {
        return DB::table('lotes as l')
            ->join('productos as p', fn (JoinClause $j) => $j->on('p.id', '=', 'l.producto_id')->on('p.empresa_id', '=', 'l.empresa_id'))
            ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
            ->where('l.empresa_id', $empresaId)
            ->where('l.numero', $numero)
            ->orderBy('p.granel_id')->orderBy('p.nombre')
            ->limit(self::MAX_LOTES)
            ->get(['l.id', 'l.numero', 'l.vencimiento', 'p.clase', 'u.nombre as unidad', DB::raw(Producto::sqlNombreCompleto('p', 'u').' AS producto')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function movimiento(ContextoAgente $contexto, string $producto, stdClass $mov): array
    {
        $local = CarbonImmutable::parse((string) $mov->created_at, 'UTC')->setTimezone($contexto->zonaHoraria);
        $documento = match (true) {
            $mov->orden_numero !== null => OrdenProduccion::formatearCodigo((int) $mov->orden_numero),
            $mov->envasado_numero !== null => Envasado::formatearCodigo((int) $mov->envasado_numero),
            default => $mov->serie.'-'.str_pad((string) $mov->numero, 5, '0', STR_PAD_LEFT),
        };

        return [
            'fecha' => $local->format('Y-m-d'), 'hora' => $local->format('H:i'), 'producto' => $producto,
            'tipo' => $mov->motivo === null || $mov->motivo === '' ? $mov->tipo : "{$mov->tipo} ({$mov->motivo})", 'documento' => $documento,
            'cantidad' => (string) $mov->cantidad, 'saldo_posterior' => (string) $mov->saldo_posterior, 'sucursal' => $mov->sucursal, 'destino' => $mov->destino,
            'cliente' => $mov->cliente, 'proveedor' => $mov->proveedor, 'usuario' => $mov->usuario,
            'nota' => $mov->nota === null ? null : mb_substr((string) $mov->nota, 0, 200),
        ];
    }

    /**
     * Órdenes que crearon esos lotes y los lotes de materia prima que consumieron (consumo al aprobar y ajuste al
     * finalizar; la línea del producto obtenido se excluye). Dos consultas.
     *
     * @param  list<int>  $loteIds
     * @return list<array<string, mixed>>
     */
    private function origen(ContextoAgente $contexto, array $loteIds): array
    {
        $empresaId = $contexto->empresaId;
        $ordenes = DB::table('ordenes_produccion as o')
            ->join('productos as p', fn (JoinClause $j) => $j->on('p.id', '=', 'o.producto_id')->on('p.empresa_id', '=', 'o.empresa_id'))
            ->join('unidades_medida as u', 'u.id', '=', 'p.unidad_medida_id')
            ->where('o.empresa_id', $empresaId)
            ->whereIn('o.lote_id', $loteIds)
            ->get(['o.id', 'o.numero', 'o.producto_id', 'o.finalizada_en', 'o.cantidad_obtenida', 'o.operacion_consumo_id', 'o.operacion_cierre_id',
                DB::raw(Producto::sqlNombreCompleto('p', 'u').' AS producto')]);
        $filas = [];

        foreach ($ordenes as $orden) {
            $operaciones = array_values(array_filter([$orden->operacion_consumo_id, $orden->operacion_cierre_id], fn ($id) => $id !== null));
            $base = [
                'orden' => OrdenProduccion::formatearCodigo((int) $orden->numero), 'producto' => $orden->producto,
                'fecha_finalizacion' => $orden->finalizada_en === null ? null : CarbonImmutable::parse((string) $orden->finalizada_en, 'UTC')->setTimezone($contexto->zonaHoraria)->format('Y-m-d'),
                'obtenido' => $orden->cantidad_obtenida,
            ];
            $consumos = $operaciones === [] ? collect() : DB::table('movimientos_stock_lotes as ml')
                ->join('lotes as lm', fn (JoinClause $j) => $j->on('lm.id', '=', 'ml.lote_id')->on('lm.empresa_id', '=', 'ml.empresa_id'))
                ->join('productos as m', fn (JoinClause $j) => $j->on('m.id', '=', 'ml.producto_id')->on('m.empresa_id', '=', 'ml.empresa_id'))
                ->join('unidades_medida as mu', 'mu.id', '=', 'm.unidad_medida_id')
                ->where('ml.empresa_id', $empresaId)
                ->whereIn('ml.operacion_id', $operaciones)
                ->where('ml.producto_id', '<>', $orden->producto_id)
                ->groupBy('ml.producto_id', 'ml.lote_id', 'm.nombre', 'm.contenido', 'mu.abreviatura', 'mu.nombre', 'lm.numero', 'lm.vencimiento')
                ->havingRaw('SUM(ml.cantidad) <> 0')
                ->orderBy('m.nombre')
                ->get([DB::raw(Producto::sqlNombreCompleto('m', 'mu').' AS materia_prima'), 'mu.nombre as unidad', 'lm.numero', 'lm.vencimiento',
                    DB::raw('-SUM(ml.cantidad) AS consumido')]);

            if ($consumos->isEmpty()) {
                $filas[] = $base + ['materia_prima' => null, 'lote_materia' => null, 'vencimiento_materia' => null, 'cantidad_consumida' => null, 'unidad' => null];
            }

            foreach ($consumos as $consumo) {
                $filas[] = $base + [
                    'materia_prima' => $consumo->materia_prima, 'lote_materia' => $consumo->numero ?? 'sin número (regularización)',
                    'vencimiento_materia' => $consumo->vencimiento, 'cantidad_consumida' => (string) $consumo->consumido, 'unidad' => $consumo->unidad,
                ];
            }
        }

        return $filas;
    }
}
