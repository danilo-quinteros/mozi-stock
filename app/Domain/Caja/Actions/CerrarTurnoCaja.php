<?php

namespace App\Domain\Caja\Actions;

use App\Domain\Auditoria\Auditor;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CerrarTurnoCaja
{
    public function __construct(private readonly Auditor $auditor) {}

    public function ejecutar(
        User $actor,
        TurnoCaja $turno,
        string|float|int|null $montoCierre = null,
        ?string $observaciones = null,
    ): TurnoCaja {
        return DB::transaction(function () use ($actor, $turno, $montoCierre, $observaciones): TurnoCaja {
            /** @var TurnoCaja $bloqueado */
            $bloqueado = TurnoCaja::withoutGlobalScopes()
                ->where('empresa_id', $actor->empresa_id)
                ->whereKey($turno->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $bloqueado->estaAbierto()) {
                throw ValidationException::withMessages([
                    'turno' => __('Este turno ya se encuentra cerrado.'),
                ]);
            }

            if (! $bloqueado->usuarioPuedeCerrar($actor)) {
                throw ValidationException::withMessages([
                    'turno' => __('No tienes autorización para cerrar este turno.'),
                ]);
            }

            $monto = $montoCierre !== null && trim((string) $montoCierre) !== ''
                ? (Decimal::normalizar((string) $montoCierre, Decimal::ESCALA_DINERO) ?? '0.00')
                : null;
            $observaciones = $observaciones !== null && trim($observaciones) !== '' ? trim($observaciones) : null;

            // El turno está bloqueado: ningún cobro o salida puede entrar mientras se calcula el arqueo.
            $arqueo = $bloqueado->calcularArqueo($monto);

            // Decisión del propietario (01/11/2026): con faltante o sobrante el motivo es obligatorio.
            if ($arqueo['diferencia'] !== null && Decimal::comparar($arqueo['diferencia'], '0', Decimal::ESCALA_DINERO) !== 0 && $observaciones === null) {
                throw ValidationException::withMessages([
                    'observaciones' => __('Hay una diferencia de :monto. Escribe el motivo antes de cerrar.', ['monto' => Decimal::bs($arqueo['diferencia'])]),
                ]);
            }

            $ahora = Carbon::now();

            $bloqueado->estado = 'cerrado';
            $bloqueado->usuario_cierre_id = $actor->id;
            $bloqueado->fecha_cierre = $ahora;
            $bloqueado->monto_cierre = $monto;
            $bloqueado->efectivo_esperado = $arqueo['efectivo_esperado'];
            $bloqueado->diferencia = $arqueo['diferencia'];
            $bloqueado->observaciones = $observaciones;
            $bloqueado->save();

            $this->auditor->registrar('turno_caja.cerrado', $bloqueado, $actor, $bloqueado->empresa_id,
                despues: [
                    'caja_id' => $bloqueado->caja_id,
                    'fecha_cierre' => $bloqueado->fecha_cierre->toIso8601String(),
                    'monto_cierre' => $bloqueado->monto_cierre,
                    'efectivo_esperado' => $arqueo['efectivo_esperado'],
                    'diferencia' => $arqueo['diferencia'],
                    'estado_diferencia' => $arqueo['estado_diferencia'],
                    'observaciones' => $bloqueado->observaciones,
                ]);

            return $bloqueado;
        });
    }
}
