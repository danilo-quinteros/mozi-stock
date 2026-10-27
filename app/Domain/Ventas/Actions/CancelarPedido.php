<?php

namespace App\Domain\Ventas\Actions;

use App\Domain\Auditoria\Auditor;
use App\Enums\EstadoPedido;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Cancela un pedido pendiente. No toca stock ni finanzas; el pedido se conserva.
 */
final class CancelarPedido
{
    public function __construct(private readonly Auditor $auditor) {}

    public function ejecutar(User $actor, Pedido $pedido): Pedido
    {
        Gate::forUser($actor)->authorize('update', $pedido);

        return DB::transaction(function () use ($actor, $pedido): Pedido {
            $bloqueado = Pedido::query()->whereKey($pedido->id)->lockForUpdate()->firstOrFail();

            if ($bloqueado->estado === EstadoPedido::Cancelado) {
                return $bloqueado;
            }

            if (! $bloqueado->estaPendiente()) {
                throw ValidationException::withMessages(['lineas' => __('Una venta confirmada no se cancela: requiere una devolución.')]);
            }

            $bloqueado->estado = EstadoPedido::Cancelado;
            $bloqueado->cancelado_en = Carbon::now();
            $bloqueado->cancelado_por = $actor->id;
            $bloqueado->save();
            $this->auditor->registrar('pedido.cancelado', $bloqueado, $actor, $bloqueado->empresa_id,
                ['estado' => EstadoPedido::Pendiente->value], ['estado' => EstadoPedido::Cancelado->value, 'total' => $bloqueado->total]);

            return $bloqueado;
        });
    }
}
