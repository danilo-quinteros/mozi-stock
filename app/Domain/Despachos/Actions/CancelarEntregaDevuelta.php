<?php

namespace App\Domain\Despachos\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Despachos\HistorialEntrega;
use App\Domain\Ventas\Actions\RegistrarDevolucion;
use App\Enums\EstadoEntrega;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Despachada, rechazada en la puerta y devuelta por completo: el administrador puede dejarla Cancelado
 * con una nota (ESPECIFICACION_DESPACHOS.md § 5.5 punto 2). No mueve stock ni dinero; eso ya lo hizo la
 * devolución. Antes del despacho, la devolución total la cancela sola (RegistrarDevolucion).
 */
final class CancelarEntregaDevuelta
{
    public function __construct(
        private readonly HistorialEntrega $historial,
        private readonly Auditor $auditor,
    ) {}

    public function ejecutar(User $actor, Venta $venta, ?string $nota): Venta
    {
        Gate::forUser($actor)->authorize('corregirEntrega', $venta);
        $nota = HistorialEntrega::notaObligatoria($nota);

        return DB::transaction(function () use ($actor, $venta, $nota): Venta {
            $bloqueada = Venta::query()->whereKey($venta->id)->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado_entrega !== EstadoEntrega::Despachado) {
                throw ValidationException::withMessages(['estado_entrega' => __('Solo se cancela así una venta despachada que todavía no se entregó.')]);
            }

            if (! self::devueltaPorCompleto($bloqueada)) {
                throw ValidationException::withMessages(['estado_entrega' => __('Para cancelarla, primero registra la devolución de todos los productos.')]);
            }

            $bloqueada->estado_entrega = EstadoEntrega::Cancelado;
            $bloqueada->save();

            $this->historial->registrar($bloqueada, EstadoEntrega::Despachado, EstadoEntrega::Cancelado, $actor, Carbon::now(), $nota);
            $this->auditor->registrar('venta.entrega_cancelada', $bloqueada, $actor, $bloqueada->empresa_id,
                ['estado_entrega' => EstadoEntrega::Despachado->value], ['estado_entrega' => EstadoEntrega::Cancelado->value, 'nota' => $nota]);

            return $bloqueada;
        }, attempts: 3);
    }

    /**
     * Todas las líneas devueltas en su cantidad completa (leído con la venta bloqueada).
     */
    public static function devueltaPorCompleto(Venta $venta): bool
    {
        $items = VentaItem::query()->where('venta_id', $venta->id)->pluck('cantidad', 'id');
        $devuelto = RegistrarDevolucion::devueltoPorLinea($venta->empresa_id, array_values(array_map('intval', $items->keys()->all())));

        return $items->every(fn ($cantidad, $id): bool => Decimal::comparar($devuelto[(int) $id] ?? '0', (string) $cantidad) >= 0);
    }
}
