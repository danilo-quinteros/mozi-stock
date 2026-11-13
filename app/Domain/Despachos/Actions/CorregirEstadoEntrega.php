<?php

namespace App\Domain\Despachos\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Despachos\HistorialEntrega;
use App\Domain\Tenancy\CapacidadesEmpresa;
use App\Enums\EstadoEntrega;
use App\Models\EventoEntrega;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * El administrador deshace el último cambio de entrega, con nota obligatoria
 * (ESPECIFICACION_DESPACHOS.md § 3 regla 3). Retrocede un solo paso:
 *
 * - Despachado → Por despachar (se borran quién, cuándo y «Retira»).
 * - Entregado → Despachado, o → Por despachar si se entregó al vender sin despacho (casilla «Entregado»).
 * - Cocina (ESPECIFICACION_COCINA.md § 5.3): Entregado → En cocina, también si se marcó al vender.
 *
 * Sin despacho, vuelve al estado pendiente con el que nació la venta (su primer evento). Si nació Entregado
 * por la casilla, al pendiente del flujo activo de la empresa (despachos o cocina).
 *
 * Las ventas entregadas sin registro de quién las entregó (anteriores a la capacidad o de una empresa sin
 * ella) no se pueden deshacer. Una venta cancelada no admite cambios. La solicitud indica el estado que
 * el administrador vio: si ya cambió (doble clic, otra persona), se rechaza en vez de retroceder dos pasos.
 * El historial conserva el evento original y agrega uno nuevo marcado como corrección.
 */
final class CorregirEstadoEntrega
{
    public function __construct(
        private readonly HistorialEntrega $historial,
        private readonly Auditor $auditor,
        private readonly CapacidadesEmpresa $capacidades,
    ) {}

    public function ejecutar(User $actor, Venta $venta, EstadoEntrega $desde, ?string $nota): Venta
    {
        Gate::forUser($actor)->authorize('corregirEntrega', $venta);
        $nota = HistorialEntrega::notaObligatoria($nota);

        return DB::transaction(function () use ($actor, $venta, $desde, $nota): Venta {
            $bloqueada = Venta::query()->whereKey($venta->id)->lockForUpdate()->firstOrFail();
            $actual = $bloqueada->estado_entrega;

            if ($actual !== $desde) {
                throw ValidationException::withMessages(['estado_entrega' => __('El estado de entrega cambió mientras tanto (ahora es «:estado»). Revisa la venta antes de corregir.', ['estado' => $actual->etiqueta()])]);
            }

            $anterior = $this->destino($bloqueada);

            if ($anterior === null) {
                throw ValidationException::withMessages(['estado_entrega' => $actual === EstadoEntrega::Cancelado
                    ? __('La venta está cancelada: ya no admite cambios de entrega.')
                    : __('No hay un cambio de entrega que deshacer.')]);
            }

            if ($actual === EstadoEntrega::Entregado) {
                $bloqueada->entregado_por = null;
                $bloqueada->entregado_en = null;
                $bloqueada->entrega_recibio = null;
            } else {
                $bloqueada->despachado_por = null;
                $bloqueada->despachado_en = null;
                $bloqueada->despacho_retira = null;
            }

            $bloqueada->estado_entrega = $anterior;
            $bloqueada->save();

            $this->historial->registrar($bloqueada, $actual, $anterior, $actor, Carbon::now(), $nota, correccion: true);
            $this->auditor->registrar('venta.entrega_corregida', $bloqueada, $actor, $bloqueada->empresa_id,
                ['estado_entrega' => $actual->value], ['estado_entrega' => $anterior->value, 'nota' => $nota]);

            return $bloqueada;
        }, attempts: 3);
    }

    /**
     * Estado al que volvería la venta con una corrección (null si no hay nada que deshacer). El diálogo lo
     * muestra antes de confirmar; la acción lo vuelve a calcular con la venta bloqueada.
     */
    public function destino(Venta $venta): ?EstadoEntrega
    {
        return match ($venta->estado_entrega) {
            EstadoEntrega::Despachado => EstadoEntrega::PorDespachar,
            EstadoEntrega::Entregado => match (true) {
                $venta->entregado_por === null => null,
                $venta->despachado_por !== null => EstadoEntrega::Despachado,
                default => $this->pendienteInicial($venta),
            },
            EstadoEntrega::PorDespachar, EstadoEntrega::EnCocina, EstadoEntrega::Cancelado => null,
        };
    }

    /**
     * Estado pendiente con el que nació la venta: Por despachar o En cocina. Si nació Entregado (casilla al
     * vender), el del flujo activo de la empresa; la Policy exige que haya uno.
     */
    private function pendienteInicial(Venta $venta): ?EstadoEntrega
    {
        $inicial = EventoEntrega::query()->where('empresa_id', $venta->empresa_id)->where('venta_id', $venta->id)
            ->orderBy('id')->first(['estado_nuevo'])?->estado_nuevo;

        if (in_array($inicial, [EstadoEntrega::PorDespachar, EstadoEntrega::EnCocina], true)) {
            return $inicial;
        }

        $flujo = $this->capacidades->flujoEntrega($venta->empresa_id);

        return $flujo === null ? null : EstadoEntrega::inicial($flujo);
    }
}
