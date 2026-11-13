<?php

namespace App\Domain\Despachos\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Despachos\HistorialEntrega;
use App\Enums\EstadoEntrega;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Autoriza la salida del almacén de una venta Por despachar (ESPECIFICACION_DESPACHOS.md § 5.2).
 *
 * Solo un administrador o un vendedor con permiso de almacén de la sucursal de la venta (VentaPolicy).
 * Despacha la venta completa (lo que queda después de devoluciones) y no mueve stock ni dinero: el stock
 * salió al confirmar. Bloquea la fila de la venta, la misma que bloquea una devolución, y exige que siga
 * Por despachar: un doble clic o un reintento recibe «Ya despachada por…» sin registrar nada más.
 */
final class DespacharVenta
{
    public function __construct(
        private readonly HistorialEntrega $historial,
        private readonly Auditor $auditor,
    ) {}

    public function ejecutar(User $actor, Venta $venta, ?string $retira = null): Venta
    {
        Gate::forUser($actor)->authorize('despachar', $venta);
        $retira = HistorialEntrega::texto($retira, 100, 'retira', __('«Retira»'));

        return DB::transaction(function () use ($actor, $venta, $retira): Venta {
            $bloqueada = Venta::query()->whereKey($venta->id)->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado_entrega !== EstadoEntrega::PorDespachar) {
                throw $this->historial->rechazo($bloqueada, 'despachar');
            }

            $this->aplicar($bloqueada, $actor, $retira, Carbon::now());

            return $bloqueada;
        }, attempts: 3);
    }

    /**
     * Despacho sobre una venta ya bloqueada y Por despachar. MarcarEntregada lo reutiliza en
     * «Despachar y entregar».
     */
    public function aplicar(Venta $venta, User $actor, ?string $retira, Carbon $momento): void
    {
        $venta->estado_entrega = EstadoEntrega::Despachado;
        $venta->despachado_por = $actor->id;
        $venta->despachado_en = $momento;
        $venta->despacho_retira = $retira;
        $venta->save();

        $this->historial->registrar($venta, EstadoEntrega::PorDespachar, EstadoEntrega::Despachado, $actor, $momento);
        $this->auditor->registrar('venta.despachada', $venta, $actor, $venta->empresa_id,
            ['estado_entrega' => EstadoEntrega::PorDespachar->value],
            ['estado_entrega' => EstadoEntrega::Despachado->value, 'retira' => $retira]);
    }
}
