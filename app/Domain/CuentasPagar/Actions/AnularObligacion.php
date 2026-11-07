<?php

namespace App\Domain\CuentasPagar\Actions;

use App\Domain\Auditoria\Auditor;
use App\Models\CuentaPagar;
use App\Models\PagoCuentaPagar;
use App\Models\User;
use App\Support\MotivoAnulacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Anulación de una obligación manual por el administrador (31/10/2026). No mueve dinero
 * (registrarla tampoco lo hizo): deja de contar en saldos y vencidos y queda como anulada. Las
 * deudas de compras y egresos siguen a su documento y no se anulan aquí. Exige anular antes los
 * pagos vigentes. Anular dos veces no hace nada.
 */
final class AnularObligacion
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  array{motivo: string|null}  $datos
     */
    public function ejecutar(User $actor, CuentaPagar $cuenta, array $datos): CuentaPagar
    {
        Gate::forUser($actor)->authorize('anular', $cuenta);

        if ($cuenta->origen !== 'manual') {
            throw ValidationException::withMessages(['motivo' => __('Solo se anulan obligaciones manuales; esta deuda sigue a su :origen.', [
                'origen' => $cuenta->origen === 'compra' ? __('compra') : __('egreso'),
            ])]);
        }

        $motivo = MotivoAnulacion::validar($datos['motivo'] ?? null);

        return DB::transaction(function () use ($actor, $cuenta, $motivo): CuentaPagar {
            $bloqueada = CuentaPagar::query()->whereKey($cuenta->id)->lockForUpdate()->firstOrFail();

            if ($bloqueada->estaAnulado()) {
                return $bloqueada;
            }

            if (PagoCuentaPagar::query()->where('cuenta_pagar_id', $bloqueada->id)->whereNull('anulado_at')->exists()) {
                throw ValidationException::withMessages(['motivo' => __('La obligación tiene pagos vigentes. Anúlalos primero.')]);
            }

            $bloqueada->anulado_at = Carbon::now();
            $bloqueada->anulado_por = $actor->id;
            $bloqueada->motivo_anulacion = $motivo;
            $bloqueada->save();

            $this->auditor->registrar('cuenta_pagar.anulada', $bloqueada, $actor, $bloqueada->empresa_id, despues: [
                'cuenta' => $bloqueada->codigo(), 'importe' => $bloqueada->importe(), 'saldo_pendiente' => $bloqueada->saldo_pendiente, 'motivo' => $motivo,
            ]);

            return $bloqueada;
        }, attempts: 3);
    }
}
