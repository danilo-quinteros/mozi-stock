<?php

namespace App\Livewire\Concerns;

use App\Domain\Caja\CajaOperacion;
use App\Models\TurnoCaja;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * Caja de la que entra o sale el efectivo (§ 24.3): uno de los turnos abiertos de la sucursal
 * que el usuario puede operar o, solo para el administrador, «fuera de caja» (externo). El
 * vendedor solo ve el turno de su caja asignada. CajaOperacion vuelve a validar la elección.
 * El componente debe tener la propiedad pública $sucursal_id.
 */
trait EligeCaja
{
    /** Id del turno elegido, 'externo' o '' (sin turno disponible). */
    public string $caja = '';

    public function updatedSucursalId(): void
    {
        unset($this->opcionesCaja);
        $this->elegirCajaPorDefecto();
    }

    /**
     * Turno de la caja principal (o el primero disponible); sin turno, el administrador queda
     * en «fuera de caja».
     */
    protected function elegirCajaPorDefecto(): void
    {
        $opciones = $this->opcionesCaja();
        $this->caja = (string) (array_key_first($opciones) ?? '');
    }

    /**
     * @return array<int|string, string> id del turno (o 'externo') => etiqueta
     */
    #[Computed]
    public function opcionesCaja(): array
    {
        /** @var User $actor */
        $actor = Auth::user();
        $sucursalId = ctype_digit((string) $this->sucursal_id) ? (int) $this->sucursal_id : null;
        $opciones = [];

        if ($sucursalId !== null) {
            foreach (app(CajaOperacion::class)->turnosDisponibles($actor, $sucursalId) as $turno) {
                /** @var TurnoCaja $turno */
                $opciones[$turno->id] = __(':caja · turno #:numero', ['caja' => $turno->caja->nombre, 'numero' => $turno->id]);
            }
        }

        if ($actor->esAdministrador()) {
            $opciones['externo'] = __('Fuera de caja (externo)');
        }

        return $opciones;
    }

    /**
     * @return array{externo: bool, turno_id: int|null}
     */
    protected function datosCaja(): array
    {
        return ['externo' => $this->caja === 'externo', 'turno_id' => ctype_digit($this->caja) ? (int) $this->caja : null];
    }
}
