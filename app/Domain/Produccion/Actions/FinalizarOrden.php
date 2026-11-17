<?php

namespace App\Domain\Produccion\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Inventario\DatosLote;
use App\Domain\Inventario\LibroLotes;
use App\Domain\Inventario\LibroStock;
use App\Domain\Produccion\CostoPromedio;
use App\Domain\Produccion\Escalado;
use App\Domain\Produccion\LineasOrden;
use App\Enums\EstadoOrdenProduccion;
use App\Enums\TipoOperacionInventario;
use App\Models\OrdenProduccion;
use App\Models\Producto;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Finalizar una orden Aprobada (ESPECIFICACION_PRODUCCION.md § 6.3, § 6.4 y § 9), en una sola transacción:
 *
 * 1. Corrige el consumo con el real de cada línea (PR8): lo que se usó de menos vuelve a los lotes de los que salió
 *    (como una devolución de la operación de consumo); lo que se usó de más se descuenta por FEFO entre vigentes y, si
 *    no alcanza, se rechaza todo.
 * 2. Ingresa lo obtenido al producto, en el lote OP-0015 con el vencimiento escrito al finalizar cuando el producto
 *    controla vencimiento.
 * 3. Costo de la orden = Σ consumo real × costo guardado al aprobar; costo unitario = costo ÷ obtenido; el producto
 *    recalcula su costo promedio (PPP).
 *
 * Orden de bloqueo (§ 11): la orden, después todas las fichas de una vez (CostoPromedio::bloquear), después saldos y
 * lotes en LibroStock. Repetir la solicitud con la orden ya finalizada no hace nada.
 */
final class FinalizarOrden
{
    /** Máximo de una cantidad: DECIMAL(18,3). */
    private const MAXIMO = '999999999999999';

    public function __construct(
        private readonly LibroStock $stock,
        private readonly LibroLotes $lotes,
        private readonly CostoPromedio $costos,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{cantidad_obtenida: mixed, consumos: mixed, vencimiento?: mixed}  $datos  consumos: producto_id => cantidad real
     */
    public function ejecutar(User $actor, OrdenProduccion $orden, array $datos): OrdenProduccion
    {
        Gate::forUser($actor)->authorize('usar-produccion');

        $empresaId = (int) $actor->empresa_id;
        $producto = Producto::query()->with('unidad:id,abreviatura,admite_decimales')->findOrFail($orden->producto_id);
        $obtenida = $this->obtenida($datos['cantidad_obtenida'] ?? null, $producto);
        $lineas = LineasOrden::deOrden($empresaId, $orden->id);
        $reales = $this->consumos($datos['consumos'] ?? null, $lineas);
        $lote = $this->lote($empresaId, $orden, $producto, $datos['vencimiento'] ?? null);
        $nombre = Producto::formatoNombreCompleto($producto->nombre, $producto->contenido, $producto->unidad->abreviatura);

        // Filas de saldo y el lote nuevo del producto, antes y fuera de la transacción (como LibroStock::registrar).
        $this->stock->asegurarNiveles($empresaId, [
            ...array_map(fn (array $l): array => ['sucursal_id' => $orden->sucursal_id, 'producto_id' => $l['producto_id']], $lineas),
            ['sucursal_id' => $orden->sucursal_id, 'producto_id' => $producto->id, 'cantidad' => $obtenida, 'nombre' => $nombre] + ($lote === null ? [] : ['lote' => $lote]),
        ]);

        return DB::transaction(function () use ($actor, $empresaId, $orden, $producto, $obtenida, $reales, $lote, $nombre): OrdenProduccion {
            $bloqueada = OrdenProduccion::query()->whereKey($orden->id)->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado === EstadoOrdenProduccion::Finalizada) {
                return $bloqueada;
            }

            if ($bloqueada->estado !== EstadoOrdenProduccion::Aprobada || $bloqueada->operacion_consumo_id === null) {
                throw ValidationException::withMessages(['orden' => $bloqueada->estado === EstadoOrdenProduccion::Emitida
                    ? __('Aprueba la orden :codigo antes de registrar lo producido.', ['codigo' => $bloqueada->codigo()])
                    : __('La orden :codigo está anulada: no se puede finalizar.', ['codigo' => $bloqueada->codigo()])]);
            }

            $lineas = LineasOrden::deOrden($empresaId, $bloqueada->id);
            $bloqueados = $this->costos->bloquear($empresaId, [...array_column($lineas, 'producto_id'), $producto->id], todas: true);
            $cambios = [];
            $costoTotal = '0';
            $porLinea = [];

            foreach ($lineas as $linea) {
                $real = $reales[$linea['producto_id']];
                $diferencia = Decimal::restar($linea['cantidad_teorica'], $real);
                $porLinea[$linea['id']] = $real;
                $costoTotal = Decimal::sumar($costoTotal, Decimal::multiplicar($real, (string) $linea['costo_unitario'], 9), 9);

                if (Decimal::comparar($diferencia, '0') > 0) {
                    // Se usó menos: vuelve a los lotes de los que salió al aprobar.
                    $cambios[] = [
                        'sucursal_id' => $bloqueada->sucursal_id, 'producto_id' => $linea['producto_id'], 'cantidad' => $diferencia,
                        'nombre' => $linea['nombre'], 'devolucion' => ['salida' => (int) $bloqueada->operacion_consumo_id, 'previas' => []],
                    ];
                } elseif (Decimal::comparar($diferencia, '0') < 0) {
                    $cambios[] = ['sucursal_id' => $bloqueada->sucursal_id, 'producto_id' => $linea['producto_id'], 'cantidad' => $diferencia, 'nombre' => $linea['nombre']];
                }
            }

            $cambios[] = ['sucursal_id' => $bloqueada->sucursal_id, 'producto_id' => $producto->id, 'cantidad' => $obtenida, 'nombre' => $nombre]
                + ($lote === null ? [] : ['lote' => $lote]);

            $operacion = $this->stock->aplicar($actor, [
                'tipo' => TipoOperacionInventario::Produccion,
                'motivo' => 'cierre',
                'sucursal_id' => $bloqueada->sucursal_id,
                'sucursal_destino_id' => null,
                'nota' => AprobarOrden::nota($bloqueada),
                'clave' => (string) Str::uuid(),
            ], $cambios);

            $costoTotal = Decimal::redondear($costoTotal, 6);
            $costoUnitario = Decimal::redondear(Decimal::dividir($costoTotal, $obtenida, 8), 6);
            $promedio = $this->costos->ingresar($empresaId, [$producto->id => $bloqueados[$producto->id] ?? '0'], [
                ['producto_id' => $producto->id, 'cantidad' => $obtenida, 'importe' => $costoTotal],
            ]);

            LineasOrden::actualizar($empresaId, 'cantidad_real', $porLinea);

            $bloqueada->estado = EstadoOrdenProduccion::Finalizada;
            $bloqueada->cantidad_obtenida = $obtenida;
            $bloqueada->finalizada_en = Carbon::now();
            $bloqueada->finalizada_por = $actor->id;
            $bloqueada->operacion_cierre_id = $operacion->id;
            $bloqueada->lote_id = $lote === null ? null : $this->loteId($empresaId, $producto->id, $lote['numero']);
            $bloqueada->costo_total = $costoTotal;
            $bloqueada->costo_unitario = $costoUnitario;
            $bloqueada->save();

            $this->auditor->registrar('orden_produccion.finalizada', $bloqueada, $actor, $empresaId, ['estado' => EstadoOrdenProduccion::Aprobada->value], [
                'estado' => EstadoOrdenProduccion::Finalizada->value, 'operacion' => $operacion->serie.'-'.$operacion->numero,
                'cantidad_obtenida' => $obtenida, 'lote' => $lote, 'costo_total' => $costoTotal, 'costo_unitario' => $costoUnitario,
                'costo_promedio' => $promedio[$producto->id] ?? null,
                'items' => array_map(fn (array $l): array => ['producto_id' => $l['producto_id'], 'teorica' => $l['cantidad_teorica'], 'real' => $reales[$l['producto_id']]], $lineas),
            ]);

            return $bloqueada;
        }, attempts: 3);
    }

    private function obtenida(mixed $valor, Producto $producto): string
    {
        $cantidad = Decimal::normalizar(is_scalar($valor) ? (string) $valor : '', Decimal::ESCALA_CANTIDAD);

        if ($cantidad === null || Decimal::comparar($cantidad, '0') <= 0 || Decimal::comparar($cantidad, self::MAXIMO) > 0) {
            throw ValidationException::withMessages(['cantidad_obtenida' => __('Ingresa la cantidad obtenida, mayor que cero (hasta 3 decimales).')]);
        }

        if (Escalado::esEntera($producto->tipo_venta, (bool) $producto->unidad->admite_decimales) && ! Decimal::esEntero($cantidad)) {
            throw ValidationException::withMessages(['cantidad_obtenida' => __(':producto se mide en unidades enteras: escribe la cantidad sin decimales.', ['producto' => $producto->nombre])]);
        }

        return $cantidad;
    }

    /**
     * Consumo real de cada línea (≥ 0). Cada materia prima de la orden debe venir; otras se ignoran.
     *
     * @param  list<array{producto_id: int, nombre: string, unidad: string, entera: bool}>  $lineas
     * @return array<int, string> producto_id => consumo real
     */
    private function consumos(mixed $entrada, array $lineas): array
    {
        $entrada = is_array($entrada) ? $entrada : [];
        $reales = [];
        $errores = [];

        foreach ($lineas as $linea) {
            $valor = $entrada[$linea['producto_id']] ?? null;
            $real = Decimal::normalizar(is_scalar($valor) ? (string) $valor : '', Decimal::ESCALA_CANTIDAD);

            if ($real === null || Decimal::comparar($real, '0') < 0 || Decimal::comparar($real, self::MAXIMO) > 0) {
                $errores["consumos.{$linea['producto_id']}"] = __(':materia: escribe lo que se usó (0 o más, hasta 3 decimales).', ['materia' => $linea['nombre']]);
            } elseif ($linea['entera'] && ! Decimal::esEntero($real)) {
                $errores["consumos.{$linea['producto_id']}"] = __(':materia se usa en unidades enteras (:unidad): escribe una cantidad sin decimales.', ['materia' => $linea['nombre'], 'unidad' => $linea['unidad']]);
            } else {
                $reales[$linea['producto_id']] = $real;
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores + ['consumos' => __('Revisa el consumo real marcado.')]);
        }

        return $reales;
    }

    /**
     * Lote del producto obtenido (§ 6.3.4): número = código de la orden; vencimiento = el escrito al finalizar
     * (obligatorio). Sin control de vencimiento (o sin la capacidad de lotes) no hay lote y el vencimiento se ignora.
     *
     * @return array{numero: string, vencimiento: string}|null
     */
    private function lote(int $empresaId, OrdenProduccion $orden, Producto $producto, mixed $valor): ?array
    {
        if (! $producto->controla_vencimiento || ! $this->lotes->activo($empresaId)) {
            return null;
        }

        $texto = is_scalar($valor) ? trim((string) $valor) : '';

        if ($texto === '') {
            throw ValidationException::withMessages(['vencimiento' => __('Escribe la fecha de vencimiento del lote producido.')]);
        }

        $vencimiento = DatosLote::vencimiento($texto);

        if ($vencimiento === null) {
            throw ValidationException::withMessages(['vencimiento' => __('Escribe el vencimiento como dd/mm/aaaa.')]);
        }

        if (DatosLote::vencido($vencimiento)) {
            throw ValidationException::withMessages(['vencimiento' => __('El vencimiento no puede ser anterior a hoy.')]);
        }

        return ['numero' => $orden->codigo(), 'vencimiento' => $vencimiento];
    }

    private function loteId(int $empresaId, int $productoId, string $numero): ?int
    {
        $id = DB::table('lotes')->where('empresa_id', $empresaId)->where('producto_id', $productoId)
            ->where('es_regularizacion', false)->where('numero', $numero)->value('id');

        return $id === null ? null : (int) $id;
    }
}
