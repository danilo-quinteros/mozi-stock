<?php

namespace App\Domain\Despachos\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Despachos\HistorialEntrega;
use App\Domain\Tenancy\CapacidadesEmpresa;
use App\Enums\Capacidad;
use App\Enums\EstadoEntrega;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * El cliente recibió los productos (ESPECIFICACION_DESPACHOS.md § 5.3, ESPECIFICACION_COCINA.md § 5.2).
 *
 * Lo marca cualquiera que pueda ver la venta. En despachos, siempre después del despacho: no se saltan
 * estados. Con $despacharTambien («Despachar y entregar», § 3 regla 2), quien tiene permiso de almacén hace
 * las dos transiciones en una sola acción; quedan dos eventos con su nombre y «Recibió» también como «Retira».
 * En cocina, el pedido pasa de En cocina a Entregado sin despacho.
 *
 * Bloquea la venta y exige el estado esperado: si dos personas pulsan a la vez, se registra una sola entrega
 * y la segunda recibe «Ya entregado por…». Nunca registra pagos ni movimientos de caja: si el cliente paga
 * al recibir, es un abono aparte (D2).
 */
final class MarcarEntregada
{
    public function __construct(
        private readonly HistorialEntrega $historial,
        private readonly DespacharVenta $despachar,
        private readonly Auditor $auditor,
        private readonly CapacidadesEmpresa $capacidades,
    ) {}

    public function ejecutar(User $actor, Venta $venta, ?string $recibio = null, ?string $nota = null, bool $despacharTambien = false): Venta
    {
        Gate::forUser($actor)->authorize($despacharTambien ? 'despachar' : 'marcarEntregada', $venta);
        $recibio = HistorialEntrega::texto($recibio, 100, 'recibio', __('«Recibió»'));
        $nota = HistorialEntrega::texto($nota, 250, 'nota', __('La nota'));

        return DB::transaction(function () use ($actor, $venta, $recibio, $nota, $despacharTambien): Venta {
            $bloqueada = Venta::query()->whereKey($venta->id)->lockForUpdate()->firstOrFail();
            $desde = $bloqueada->estado_entrega;
            $valida = $despacharTambien
                ? $desde === EstadoEntrega::PorDespachar
                : in_array($desde, [EstadoEntrega::Despachado, EstadoEntrega::EnCocina], true);

            if (! $valida) {
                throw $this->historial->rechazo($bloqueada, match (true) {
                    $despacharTambien => 'despachar',
                    $this->capacidades->flujoEntrega($bloqueada->empresa_id) === Capacidad::Cocina => 'entregar_pedido',
                    default => 'entregar',
                });
            }

            $ahora = Carbon::now();

            if ($despacharTambien) {
                $this->despachar->aplicar($bloqueada, $actor, $recibio, $ahora);
                $desde = EstadoEntrega::Despachado;
            }

            $bloqueada->estado_entrega = EstadoEntrega::Entregado;
            $bloqueada->entregado_por = $actor->id;
            $bloqueada->entregado_en = $ahora;
            $bloqueada->entrega_recibio = $recibio;
            $bloqueada->save();

            $this->historial->registrar($bloqueada, $desde, EstadoEntrega::Entregado, $actor, $ahora, $nota);
            $this->auditor->registrar('venta.entregada', $bloqueada, $actor, $bloqueada->empresa_id,
                ['estado_entrega' => $desde->value],
                ['estado_entrega' => EstadoEntrega::Entregado->value, 'recibio' => $recibio, 'nota' => $nota]);

            return $bloqueada;
        }, attempts: 3);
    }
}
