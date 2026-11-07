<?php

namespace App\Domain\Caja\Actions;

use App\Models\TurnoCaja;
use App\Models\TurnoUsuario;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Deja constancia de quién trabaja en un turno de caja compartida (§ 7.4): quien lo abre y quien
 * vende, cobra o paga en él. Un usuario figura una vez por turno (índice único turno + usuario).
 * Los llamadores ya validaron que el usuario puede operar el turno.
 */
final class AsegurarParticipanteTurno
{
    public function ejecutar(User $usuario, TurnoCaja $turno): void
    {
        if ($turno->caja->modalidad !== 'compartida') {
            return;
        }

        $yaParticipa = TurnoUsuario::withoutGlobalScopes()
            ->where('turno_caja_id', $turno->id)
            ->where('user_id', $usuario->id)
            ->exists();

        if (! $yaParticipa) {
            $participante = new TurnoUsuario;
            $participante->empresa_id = $turno->empresa_id;
            $participante->turno_caja_id = $turno->id;
            $participante->user_id = $usuario->id;
            $participante->incorporado_at = Carbon::now();
            $participante->save();
        }
    }
}
