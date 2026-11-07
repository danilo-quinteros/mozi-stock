<?php

namespace App\Domain\Creditos\Actions;

use App\Domain\Auditoria\Auditor;
use App\Models\CuentaCobrar;
use App\Models\User;
use App\Support\MotivoAnulacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Anulación de una cuenta por cobrar manual por el administrador (31/10/2026). No mueve dinero
 * (registrarla tampoco lo hizo): deja de contar en saldos, vencidos y cobros, y queda en su
 * historial como anulada. Exige anular antes sus cobros vigentes. Anular dos veces no hace nada.
 */
final class AnularCuentaCobrar
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  array{motivo: string|null}  $datos
     */
    public function ejecutar(User $actor, CuentaCobrar $cuenta, array $datos): CuentaCobrar
    {
        Gate::forUser($actor)->authorize('anular', $cuenta);
        $motivo = MotivoAnulacion::validar($datos['motivo'] ?? null);

        return DB::transaction(function () use ($actor, $cuenta, $motivo): CuentaCobrar {
            // Bloquear la cuenta serializa la anulación con cualquier cobro que la alcance.
            $bloqueada = CuentaCobrar::query()->whereKey($cuenta->id)->lockForUpdate()->firstOrFail();

            if ($bloqueada->estaAnulado()) {
                return $bloqueada;
            }

            $vigentes = DB::table('cobro_aplicaciones as a')
                ->join('cobros as c', 'c.id', '=', 'a.cobro_id')
                ->where('a.empresa_id', $bloqueada->empresa_id)
                ->where('a.cuenta_cobrar_id', $bloqueada->id)
                ->whereNull('c.anulado_at')
                ->orderBy('c.numero')
                ->pluck('c.numero');

            if ($vigentes->isNotEmpty()) {
                throw ValidationException::withMessages(['motivo' => __('La cuenta tiene cobros vigentes (:cobros). Anúlalos primero.', [
                    'cobros' => $vigentes->map(fn ($numero) => 'COB-'.str_pad((string) $numero, 5, '0', STR_PAD_LEFT))->implode(', '),
                ])]);
            }

            $bloqueada->anulado_at = Carbon::now();
            $bloqueada->anulado_por = $actor->id;
            $bloqueada->motivo_anulacion = $motivo;
            $bloqueada->save();

            $this->auditor->registrar('cuenta_cobrar.anulada', $bloqueada, $actor, $bloqueada->empresa_id, despues: [
                'cuenta' => $bloqueada->codigo(), 'importe' => $bloqueada->importe, 'saldo_pendiente' => $bloqueada->saldo_pendiente, 'motivo' => $motivo,
            ]);

            return $bloqueada;
        }, attempts: 3);
    }
}
