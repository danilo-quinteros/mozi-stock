<?php

namespace App\Domain\Produccion\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Inventario\LibroStock;
use App\Domain\Produccion\LineasOrden;
use App\Enums\EstadoOrdenProduccion;
use App\Enums\TipoOperacionInventario;
use App\Models\OrdenProduccion;
use App\Models\User;
use App\Support\MotivoAnulacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Anular una orden con motivo obligatorio (ESPECIFICACION_PRODUCCION.md § 6.2 y § 6.4). Emitida: solo cambia el
 * estado. Aprobada: devuelve toda la materia prima a los lotes de los que salió, en una operación `produccion` /
 * `anulacion`; el costo promedio no cambia (§ 9). Una orden Finalizada no se anula. Repetir la solicitud con la orden
 * ya anulada no hace nada.
 */
final class AnularOrden
{
    public function __construct(private readonly LibroStock $stock, private readonly Auditor $auditor) {}

    public function ejecutar(User $actor, OrdenProduccion $orden, ?string $motivo): OrdenProduccion
    {
        Gate::forUser($actor)->authorize('usar-produccion');

        $motivo = MotivoAnulacion::validar($motivo);
        $empresaId = (int) $actor->empresa_id;
        $this->stock->asegurarNiveles($empresaId, array_map(
            fn (array $l): array => ['sucursal_id' => $orden->sucursal_id, 'producto_id' => $l['producto_id']],
            LineasOrden::deOrden($empresaId, $orden->id),
        ));

        return DB::transaction(function () use ($actor, $empresaId, $orden, $motivo): OrdenProduccion {
            $bloqueada = OrdenProduccion::query()->whereKey($orden->id)->lockForUpdate()->firstOrFail();
            $estado = $bloqueada->estado;

            if ($estado === EstadoOrdenProduccion::Anulada) {
                return $bloqueada;
            }

            if ($estado === EstadoOrdenProduccion::Finalizada) {
                throw ValidationException::withMessages(['motivo' => __('La orden :codigo ya está finalizada: no se puede anular.', ['codigo' => $bloqueada->codigo()])]);
            }

            $operacion = null;

            if ($estado === EstadoOrdenProduccion::Aprobada && $bloqueada->operacion_consumo_id !== null) {
                $operacion = $this->stock->aplicar($actor, [
                    'tipo' => TipoOperacionInventario::Produccion,
                    'motivo' => 'anulacion',
                    'sucursal_id' => $bloqueada->sucursal_id,
                    'sucursal_destino_id' => null,
                    'nota' => AprobarOrden::nota($bloqueada),
                    'clave' => (string) Str::uuid(),
                ], array_map(fn (array $l): array => [
                    'sucursal_id' => $bloqueada->sucursal_id, 'producto_id' => $l['producto_id'], 'cantidad' => $l['cantidad_teorica'], 'nombre' => $l['nombre'],
                    'devolucion' => ['salida' => (int) $bloqueada->operacion_consumo_id, 'previas' => []],
                ], LineasOrden::deOrden($empresaId, $bloqueada->id)));
            }

            $bloqueada->estado = EstadoOrdenProduccion::Anulada;
            $bloqueada->anulada_en = Carbon::now();
            $bloqueada->anulada_por = $actor->id;
            $bloqueada->motivo_anulacion = $motivo;
            $bloqueada->operacion_anulacion_id = $operacion?->id;
            $bloqueada->save();

            $this->auditor->registrar('orden_produccion.anulada', $bloqueada, $actor, $empresaId, ['estado' => $estado->value], [
                'estado' => EstadoOrdenProduccion::Anulada->value, 'motivo' => $motivo,
                'operacion' => $operacion === null ? null : $operacion->serie.'-'.$operacion->numero,
            ]);

            return $bloqueada;
        }, attempts: 3);
    }
}
