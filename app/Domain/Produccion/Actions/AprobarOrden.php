<?php

namespace App\Domain\Produccion\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Inventario\LibroStock;
use App\Domain\Produccion\LineasOrden;
use App\Domain\Tenancy\ResolverSucursal;
use App\Enums\EstadoOrdenProduccion;
use App\Enums\TipoOperacionInventario;
use App\Models\OrdenProduccion;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Aprobar una orden Emitida (ESPECIFICACION_PRODUCCION.md § 6.2 y § 6.4): descuenta de la sucursal la materia prima
 * teórica en una operación de inventario `produccion` / `consumo` (FEFO entre lotes vigentes, M3). Si no alcanza, se
 * rechaza sin guardar nada (PR7). Guarda en cada línea el costo promedio de la materia prima en ese momento (§ 9).
 *
 * Orden de bloqueo (§ 11): la orden (FOR UPDATE) y después LibroStock (fichas, saldos, lotes). Repetir la solicitud
 * con la orden ya aprobada no hace nada: el estado se lee con la orden bloqueada.
 */
final class AprobarOrden
{
    public function __construct(
        private readonly LibroStock $stock,
        private readonly ResolverSucursal $sucursales,
        private readonly Auditor $auditor,
    ) {}

    public function ejecutar(User $actor, OrdenProduccion $orden): OrdenProduccion
    {
        Gate::forUser($actor)->authorize('usar-produccion');

        $empresaId = (int) $actor->empresa_id;
        // Filas de saldo de las materias primas en la sucursal, antes y fuera de la transacción (como LibroStock).
        $this->stock->asegurarNiveles($empresaId, array_map(
            fn (array $l): array => ['sucursal_id' => $orden->sucursal_id, 'producto_id' => $l['producto_id']],
            LineasOrden::deOrden($empresaId, $orden->id),
        ));

        return DB::transaction(function () use ($actor, $empresaId, $orden): OrdenProduccion {
            $bloqueada = OrdenProduccion::query()->whereKey($orden->id)->lockForUpdate()->firstOrFail();

            if (in_array($bloqueada->estado, [EstadoOrdenProduccion::Aprobada, EstadoOrdenProduccion::Finalizada], true)) {
                return $bloqueada;
            }

            if ($bloqueada->estado !== EstadoOrdenProduccion::Emitida) {
                throw ValidationException::withMessages(['orden' => __('La orden :codigo está anulada: no se puede aprobar.', ['codigo' => $bloqueada->codigo()])]);
            }

            $sucursal = $this->sucursales->paraOperacion($actor, $bloqueada->sucursal_id);
            $lineas = LineasOrden::deOrden($empresaId, $bloqueada->id);
            $inactivas = array_column(array_filter($lineas, fn (array $l): bool => ! $l['activo']), 'nombre');

            if ($inactivas !== []) {
                throw ValidationException::withMessages(['orden' => __('La orden usa materias primas inactivas (:materias). Actívalas en Materias primas o anula la orden.', [
                    'materias' => implode(', ', $inactivas),
                ])]);
            }

            $operacion = $this->stock->aplicar($actor, [
                'tipo' => TipoOperacionInventario::Produccion,
                'motivo' => 'consumo',
                'sucursal_id' => $sucursal->id,
                'sucursal_destino_id' => null,
                'nota' => self::nota($bloqueada),
                'clave' => (string) Str::uuid(),
            ], array_map(fn (array $l): array => [
                'sucursal_id' => $sucursal->id, 'producto_id' => $l['producto_id'],
                'cantidad' => '-'.$l['cantidad_teorica'], 'nombre' => $l['nombre'],
            ], $lineas));

            // Lectura con bloqueo (las fichas ya están bloqueadas en compartido por LibroStock): el último costo
            // confirmado, no el de la foto de la transacción.
            $costos = DB::table('productos')->where('empresa_id', $empresaId)
                ->whereIn('id', array_column($lineas, 'producto_id'))
                ->sharedLock()
                ->pluck('costo_promedio', 'id');
            $porLinea = [];

            foreach ($lineas as $linea) {
                $porLinea[$linea['id']] = (string) ($costos[$linea['producto_id']] ?? '0');
            }

            LineasOrden::actualizar($empresaId, 'costo_unitario', $porLinea);

            $bloqueada->estado = EstadoOrdenProduccion::Aprobada;
            $bloqueada->aprobada_en = Carbon::now();
            $bloqueada->aprobada_por = $actor->id;
            $bloqueada->operacion_consumo_id = $operacion->id;
            $bloqueada->save();

            $this->auditor->registrar('orden_produccion.aprobada', $bloqueada, $actor, $empresaId, ['estado' => EstadoOrdenProduccion::Emitida->value], [
                'estado' => EstadoOrdenProduccion::Aprobada->value, 'operacion' => $operacion->serie.'-'.$operacion->numero,
                'items' => array_map(fn (array $l): array => ['producto_id' => $l['producto_id'], 'cantidad' => $l['cantidad_teorica'], 'costo' => $porLinea[$l['id']]], $lineas),
            ]);

            return $bloqueada;
        }, attempts: 3);
    }

    /**
     * Nota de las operaciones de inventario de la orden (§ 6.4): «Orden OP-0015 · Suavizante».
     */
    public static function nota(OrdenProduccion $orden): string
    {
        $producto = DB::table('productos as p')->leftJoin('unidades_medida as um', 'um.id', '=', 'p.unidad_medida_id')
            ->where('p.empresa_id', $orden->empresa_id)->where('p.id', $orden->producto_id)
            ->selectRaw(Producto::sqlNombreCompleto().' as nombre')->value('nombre');

        return mb_substr(__('Orden :codigo · :producto', ['codigo' => $orden->codigo(), 'producto' => (string) $producto]), 0, 250);
    }
}
