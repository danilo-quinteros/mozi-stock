<?php

namespace App\Domain\Caja;

use App\Domain\Caja\Actions\AsegurarParticipanteTurno;
use App\Models\Caja;
use App\Models\MovimientoCaja;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Único punto que escribe movimientos_caja: una fila por medio con importe y evento de
 * origen (pago_venta, compra, egreso, devolucion). La restricción única por origen y medio
 * impide registrar dos veces el mismo dinero. Debe ejecutarse dentro de la transacción del
 * llamador, junto con el registro que origina el movimiento.
 *
 * El dinero de una caja solo entra en un turno abierto de esa misma caja y sucursal, que el
 * usuario puede operar; en caja compartida el usuario queda como participante del turno.
 */
final class LibroCaja
{
    public function __construct(private readonly AsegurarParticipanteTurno $participantes) {}

    /**
     * @param  'ingreso'|'egreso'  $sentido
     * @param  array{efectivo: string, electronico: string}  $importes
     */
    public function registrar(
        User $usuario,
        int $sucursalId,
        string $sentido,
        array $importes,
        string $origenTipo,
        int $origenId,
        CarbonInterface $fecha,
        ?int $cajaId = null,
        ?int $turnoId = null,
    ): void {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Un movimiento de caja solo se registra dentro de una transacción.');
        }

        // Bloquea el turno hasta el final de la transacción: si se cerró mientras tanto, el dinero
        // no entra en un arqueo ya guardado; si está abierto, el cierre espera a este movimiento.
        if ($turnoId !== null) {
            $turno = TurnoCaja::withoutGlobalScopes()
                ->where('empresa_id', (int) $usuario->empresa_id)
                ->whereKey($turnoId)
                ->lockForUpdate()
                ->first();

            if ($turno === null || ! $turno->estaAbierto()) {
                throw ValidationException::withMessages([
                    'caja' => __('El turno de caja se cerró mientras se registraba la operación. Abre la caja e inténtalo de nuevo.'),
                ]);
            }

            if ((int) $turno->sucursal_id !== $sucursalId || ($cajaId !== null && (int) $turno->caja_id !== $cajaId)) {
                throw ValidationException::withMessages([
                    'caja' => __('La caja elegida no pertenece a la sucursal de la operación.'),
                ]);
            }

            if (! $turno->usuarioPuedeOperar($usuario)) {
                throw ValidationException::withMessages([
                    'caja' => __('No estás autorizado para operar el turno de esta caja.'),
                ]);
            }

            $cajaId = (int) $turno->caja_id;
            $this->participantes->ejecutar($usuario, $turno);
        } elseif ($cajaId !== null && ! Caja::withoutGlobalScopes()->where('empresa_id', (int) $usuario->empresa_id)
            ->where('sucursal_id', $sucursalId)->whereKey($cajaId)->exists()) {
            throw ValidationException::withMessages([
                'caja' => __('La caja elegida no pertenece a la sucursal de la operación.'),
            ]);
        }

        foreach (['efectivo' => $importes['efectivo'], 'electronico' => $importes['electronico']] as $medio => $importe) {
            if (Decimal::comparar($importe, '0', Decimal::ESCALA_DINERO) <= 0) {
                continue;
            }

            $movimiento = new MovimientoCaja;
            $movimiento->empresa_id = (int) $usuario->empresa_id;
            $movimiento->sucursal_id = $sucursalId;
            $movimiento->caja_id = $cajaId;
            $movimiento->turno_caja_id = $turnoId;
            $movimiento->sentido = $sentido;
            $movimiento->metodo = $medio;
            $movimiento->monto = $importe;
            $movimiento->origen_tipo = $origenTipo;
            $movimiento->origen_id = $origenId;
            $movimiento->fecha_operacion = Carbon::instance($fecha);
            $movimiento->usuario_id = $usuario->id;
            $movimiento->save();
        }
    }
}
