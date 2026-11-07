<?php

namespace App\Domain\Caja;

use App\Models\Caja;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Caja y turno de un cobro o pago posterior, o del pago inicial de una compra o egreso
 * (Cuentas por cobrar y pagar § 24.3):
 *
 * - El efectivo que entra o sale físicamente de una caja exige un turno abierto que el usuario
 *   pueda operar; así el arqueo de ese turno lo incluye.
 * - El vendedor usa siempre el turno de su caja asignada. El administrador elige entre los
 *   turnos abiertos de la sucursal que puede operar; sin elección se usa el de la caja principal.
 * - Solo el administrador registra efectivo como externo (fuera de caja, sin turno). Decisión
 *   del propietario (30/10/2026).
 * - Lo electrónico no mueve efectivo ni exige turno; si hay uno elegido o disponible se vincula
 *   como referencia del arqueo.
 *
 * También decide con qué caja trabaja un usuario en una sucursal (POS y barra superior): una
 * caja solo se usa desde su propia sucursal.
 */
final class CajaOperacion
{
    /**
     * @return array{caja_id: int|null, turno_id: int|null, externo: bool}
     *
     * @throws ValidationException
     */
    public function resolver(User $actor, int $sucursalId, string $efectivo, bool $externo, string $campo = 'externo', ?int $turnoId = null): array
    {
        $conEfectivo = Decimal::comparar($efectivo, '0', Decimal::ESCALA_DINERO) > 0;

        if ($conEfectivo && $externo) {
            if (! $actor->esAdministrador()) {
                throw ValidationException::withMessages([$campo => __('Solo un administrador registra efectivo fuera de caja.')]);
            }

            return ['caja_id' => null, 'turno_id' => null, 'externo' => true];
        }

        $turno = $turnoId !== null && $actor->esAdministrador()
            ? ($this->turnosDisponibles($actor, $sucursalId)->firstWhere('id', $turnoId)
                ?? throw ValidationException::withMessages([$campo => __('La caja elegida ya no tiene un turno abierto en esta sucursal. Elige otra.')]))
            : $this->turnoDe($actor, $sucursalId);

        if ($conEfectivo && $turno === null) {
            throw ValidationException::withMessages([$campo => $actor->esAdministrador()
                ? __('El efectivo por caja requiere un turno abierto en esta sucursal. Ábrelo en la barra superior o márcalo como externo.')
                : __('El efectivo por caja requiere un turno abierto. Abre tu caja en la barra superior.')]);
        }

        return ['caja_id' => $turno?->caja_id, 'turno_id' => $turno?->id, 'externo' => false];
    }

    /**
     * Caja con la que el usuario trabaja en la sucursal, o null si no tiene ninguna:
     *
     * - Vendedor: solo su caja asignada, activa y de su propia sucursal. Sin asignación no se usa
     *   la caja principal en su lugar (§ 6.5: un usuario no asignado no abre ni usa una caja).
     * - Administrador: la caja elegida si es de esa sucursal y está activa; sin elección, la del
     *   turno que él mismo abrió en esa sucursal (la principal primero si abrió varios), la
     *   principal o la primera activa.
     */
    public function cajaDe(User $actor, int $sucursalId, ?int $cajaElegida = null): ?Caja
    {
        $cajas = fn () => Caja::withoutGlobalScopes()
            ->where('empresa_id', (int) $actor->empresa_id)
            ->where('sucursal_id', $sucursalId)
            ->where('activa', true);

        if ($actor->esVendedor()) {
            return $actor->caja_id === null || (int) $actor->sucursal_id !== $sucursalId
                ? null
                : $cajas()->whereKey($actor->caja_id)->first();
        }

        if (! $actor->esAdministrador()) {
            return null;
        }

        if ($cajaElegida !== null) {
            return $cajas()->whereKey($cajaElegida)->first();
        }

        return $cajas()->whereHas('turnoActivo', fn ($q) => $q->where('usuario_apertura_id', $actor->id))
            ->orderByDesc('es_principal')->orderBy('id')->first()
            ?? $cajas()->orderByDesc('es_principal')->orderBy('id')->first();
    }

    /**
     * Turnos abiertos que abrió el usuario en su empresa, con su caja y sucursal. El vendedor
     * tiene a lo sumo uno; el administrador, uno por caja.
     *
     * @return Collection<int, TurnoCaja>
     */
    public function turnosAbiertosDe(User $actor): Collection
    {
        return TurnoCaja::withoutGlobalScopes()
            ->where('empresa_id', (int) $actor->empresa_id)
            ->where('usuario_apertura_id', $actor->id)
            ->where('estado', 'abierto')
            ->with(['caja:id,empresa_id,sucursal_id,nombre,modalidad,es_principal', 'sucursal:id,nombre'])
            ->orderBy('fecha_apertura')->orderBy('id')
            ->get();
    }

    /**
     * Turno que se usa sin elección: el de la caja asignada si es vendedor; para el
     * administrador, el de la caja principal o el primero de otra caja activa.
     */
    public function turnoDe(User $actor, int $sucursalId): ?TurnoCaja
    {
        return $this->turnosDisponibles($actor, $sucursalId)->first();
    }

    /**
     * Turnos abiertos de la sucursal que el usuario puede operar, con su caja: para el vendedor
     * solo el de su caja asignada; para el administrador, los de cajas activas (principal primero).
     *
     * @return Collection<int, TurnoCaja>
     */
    public function turnosDisponibles(User $actor, int $sucursalId): Collection
    {
        if ($actor->esVendedor()) {
            $caja = $this->cajaDe($actor, $sucursalId);
            $turno = $caja?->turnoActivo;

            return new Collection($turno !== null && $turno->usuarioPuedeOperar($actor) ? [$turno->setRelation('caja', $caja)] : []);
        }

        return TurnoCaja::query()
            ->join('cajas', 'cajas.id', '=', 'turnos_caja.caja_id')
            ->where('turnos_caja.empresa_id', $actor->empresa_id)
            ->where('turnos_caja.sucursal_id', $sucursalId)
            ->where('turnos_caja.estado', 'abierto')
            ->where('cajas.activa', true)
            ->orderByDesc('cajas.es_principal')
            ->orderBy('turnos_caja.id')
            ->with(['caja', 'usuarioApertura:id,name'])
            ->get(['turnos_caja.*'])
            ->filter(fn (TurnoCaja $turno) => $turno->usuarioPuedeOperar($actor))
            ->values();
    }
}
