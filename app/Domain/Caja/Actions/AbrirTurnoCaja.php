<?php

namespace App\Domain\Caja\Actions;

use App\Domain\Auditoria\Auditor;
use App\Models\Caja;
use App\Models\Scopes\EmpresaScope;
use App\Models\Sucursal;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Abre el turno de una caja física (§ 8 y § 12): un solo turno abierto por caja, la caja y su
 * sucursal activas, y el usuario activo de la misma empresa. El vendedor solo abre su caja
 * asignada de su sucursal y tiene un solo turno abierto a la vez; el administrador abre
 * cualquier caja activa de su empresa y puede tener abierto un turno en cada una (decisión del
 * propietario, 06/11/2026): cada turno conserva su fondo, sus movimientos y su arqueo.
 */
final class AbrirTurnoCaja
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly AsegurarParticipanteTurno $participantes,
    ) {}

    public function ejecutar(User $actor, Caja $caja, string|float|int $montoApertura): TurnoCaja
    {
        $monto = Decimal::normalizar((string) $montoApertura, Decimal::ESCALA_DINERO);

        if ($monto === null || Decimal::comparar($monto, '0', Decimal::ESCALA_DINERO) < 0) {
            throw ValidationException::withMessages([
                'monto_apertura' => __('El fondo inicial debe ser un número válido mayor o igual a cero.'),
            ]);
        }

        return DB::transaction(function () use ($actor, $caja, $monto): TurnoCaja {
            /** @var Caja $bloqueada */
            $bloqueada = Caja::withoutGlobalScope(EmpresaScope::class)
                ->where('empresa_id', (int) $actor->empresa_id)
                ->whereKey($caja->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $bloqueada->activa) {
                throw ValidationException::withMessages([
                    'caja' => __('Esta caja está inactiva y no admite nuevas aperturas.'),
                ]);
            }

            $sucursalActiva = Sucursal::withoutGlobalScopes()
                ->where('empresa_id', $bloqueada->empresa_id)
                ->whereKey($bloqueada->sucursal_id)
                ->value('activa');

            if (! $sucursalActiva) {
                throw ValidationException::withMessages([
                    'caja' => __('La sucursal de esta caja está inactiva.'),
                ]);
            }

            if (! $actor->activo || ! ($actor->esAdministrador() || $actor->esVendedor())) {
                throw ValidationException::withMessages([
                    'caja' => __('Tu usuario no puede abrir cajas.'),
                ]);
            }

            if ($actor->esVendedor()) {
                if ((int) $actor->sucursal_id !== (int) $bloqueada->sucursal_id) {
                    throw ValidationException::withMessages([
                        'caja' => __('No perteneces a la sucursal de esta caja.'),
                    ]);
                }

                // Sin caja asignada no se abre ninguna, tampoco la principal (§ 6.5).
                if ($actor->caja_id === null) {
                    throw ValidationException::withMessages([
                        'caja' => __('No tienes una caja asignada. Solicita al administrador que te asigne una.'),
                    ]);
                }

                if ((int) $actor->caja_id !== (int) $bloqueada->id) {
                    throw ValidationException::withMessages([
                        'caja' => __('Solo tienes autorización para utilizar tu caja asignada.'),
                    ]);
                }
            }

            // Validar que la caja no tenga un turno abierto ya
            $turnoExistente = TurnoCaja::withoutGlobalScope(EmpresaScope::class)
                ->where('empresa_id', (int) $actor->empresa_id)
                ->where('caja_id', $bloqueada->id)
                ->where('estado', 'abierto')
                ->lockForUpdate()
                ->first();

            if ($turnoExistente !== null) {
                throw ValidationException::withMessages([
                    'caja' => __('Esta caja ya tiene un turno abierto.'),
                ]);
            }

            // El vendedor atiende un solo cajón: no abre un segundo turno. El administrador sí,
            // en otra caja (la de esta ya se comprobó arriba y la protege el índice único).
            $otroTurno = $actor->esVendedor() ? TurnoCaja::withoutGlobalScope(EmpresaScope::class)
                ->where('empresa_id', (int) $actor->empresa_id)
                ->where('usuario_apertura_id', $actor->id)
                ->where('estado', 'abierto')
                ->lockForUpdate()
                ->first() : null;

            if ($otroTurno !== null) {
                throw ValidationException::withMessages([
                    'caja' => __('Ya tienes un turno abierto en otra caja. Ciérralo antes de abrir uno nuevo.'),
                ]);
            }

            $ahora = Carbon::now();

            $turno = new TurnoCaja;
            $turno->empresa_id = $bloqueada->empresa_id;
            $turno->sucursal_id = $bloqueada->sucursal_id;
            $turno->caja_id = $bloqueada->id;
            $turno->usuario_apertura_id = $actor->id;
            $turno->monto_apertura = $monto;
            $turno->fecha_apertura = $ahora;
            $turno->estado = 'abierto';
            $turno->save();

            $this->participantes->ejecutar($actor, $turno->setRelation('caja', $bloqueada));

            $this->auditor->registrar('turno_caja.abierto', $turno, $actor, $turno->empresa_id,
                despues: [
                    'caja_id' => $turno->caja_id,
                    'monto_apertura' => $turno->monto_apertura,
                    'fecha_apertura' => $turno->fecha_apertura->toIso8601String(),
                ]);

            return $turno;
        });
    }
}
