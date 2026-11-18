<?php

namespace App\Livewire\Concerns;

use App\Domain\Caja\CajaOperacion;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Support\Decimal;
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

    /**
     * Parte en efectivo de un pago según su medio: todo si es efectivo, lo escrito en «Efectivo»
     * si es mixto, nada si es electrónico. Informativo: la acción valida el desglose.
     */
    protected function parteEfectivo(?string $metodo, ?string $total, ?string $efectivo): ?string
    {
        return match ($metodo) {
            'efectivo' => Decimal::normalizar($total ?? '', Decimal::ESCALA_DINERO),
            'mixto' => Decimal::normalizar($efectivo ?? '', Decimal::ESCALA_DINERO),
            default => null,
        };
    }

    /**
     * Aviso (no bloquea) cuando una salida de efectivo supera lo que, según el sistema, hay en el
     * turno elegido: esa caja quedaría en negativo y su arqueo no cuadraría. Suele pasar cuando se
     * paga con dinero que no está en la caja (p. ej. del administrador), que se registra «fuera de
     * caja». Solo mira un turno de opcionesCaja (de la empresa y que el usuario puede operar).
     *
     * @return array{caja: string, disponible: string, salida: string, quedaria: string, externo: bool}|null
     */
    protected function avisoCajaNegativa(?string $salida): ?array
    {
        if ($salida === null || Decimal::comparar($salida, '0', Decimal::ESCALA_DINERO) <= 0 || ! ctype_digit($this->caja)) {
            return null;
        }

        // Propiedad calculada: la misma consulta que usa el selector en esta petición.
        $opciones = $this->opcionesCaja;

        if (! array_key_exists((int) $this->caja, $opciones)) {
            return null;
        }

        /** @var User $actor */
        $actor = Auth::user();
        $turno = TurnoCaja::withoutGlobalScopes()
            ->where('empresa_id', (int) $actor->empresa_id)
            ->where('estado', 'abierto')
            ->find((int) $this->caja);

        if ($turno === null) {
            return null;
        }

        $disponible = $turno->efectivoEnCaja();
        $quedaria = Decimal::restar($disponible, $salida, Decimal::ESCALA_DINERO);

        return Decimal::comparar($quedaria, '0', Decimal::ESCALA_DINERO) < 0
            ? ['caja' => $opciones[(int) $this->caja], 'disponible' => $disponible, 'salida' => $salida, 'quedaria' => $quedaria, 'externo' => array_key_exists('externo', $opciones)]
            : null;
    }
}
