<?php

namespace App\Livewire\Caja;

use App\Domain\Caja\Actions\AbrirTurnoCaja;
use App\Domain\Caja\Actions\CerrarTurnoCaja;
use App\Domain\Caja\CajaOperacion;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Caja;
use App\Models\Sucursal;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Caja de la barra superior y los modales de apertura y cierre. Caja y arqueo y Mi caja los
 * abren con un evento: el administrador puede abrir cualquier caja activa de su empresa y
 * cerrar cualquier turno abierto de ella (decisión del propietario, 06/11/2026). Las acciones
 * vuelven a validar la caja, el turno y los permisos al confirmar.
 */
class ControlCajaTopbar extends Component
{
    use MuestraAvisos;

    public bool $modalAperturaAbierto = false;

    public bool $modalCierreAbierto = false;

    public string $fondoInicial = '0.00';

    public string $montoCierre = '';

    public string $observacionesCierre = '';

    /** Caja elegida en Caja y arqueo; null = la de la barra. */
    #[Locked]
    public ?int $cajaAperturaId = null;

    /** Turno elegido en Caja y arqueo; null = el de la barra. */
    #[Locked]
    public ?int $turnoCierreId = null;

    #[On('turno-modificado')]
    public function actualizar(): void
    {
        // Re-render reactivo
    }

    #[On('abrir-apertura-caja')]
    public function abrirModalApertura(?int $cajaId = null): void
    {
        $caja = $this->cajaParaAbrir($cajaId);

        if ($caja === null) {
            $this->avisar($cajaId === null ? __('No tienes una caja asignada en tu sucursal.') : __('Esa caja ya no está disponible.'), 'danger');

            return;
        }

        if ($caja->turnoActivo !== null) {
            $this->avisar(__(':caja ya tiene un turno abierto.', ['caja' => $caja->nombre]), 'danger');

            return;
        }

        $this->resetValidation(['fondoInicial', 'caja_nombre']);
        $this->cajaAperturaId = $cajaId === null ? null : $caja->id;
        $this->fondoInicial = '0.00';
        $this->modalAperturaAbierto = true;
    }

    public function cerrarModalApertura(): void
    {
        $this->modalAperturaAbierto = false;
        $this->cajaAperturaId = null;
        $this->resetValidation(['fondoInicial', 'caja_nombre']);
    }

    public function ejecutarApertura(AbrirTurnoCaja $accion): void
    {
        $caja = $this->cajaParaAbrir($this->cajaAperturaId);

        if ($caja === null) {
            $this->avisar(__('No se encontró una caja activa para abrir turno.'), 'danger');

            return;
        }

        $normalizado = Decimal::normalizar($this->fondoInicial, Decimal::ESCALA_DINERO);

        if ($normalizado === null || Decimal::comparar($normalizado, '0', Decimal::ESCALA_DINERO) < 0) {
            $this->addError('fondoInicial', __('Ingresa un importe de fondo inicial válido.'));

            return;
        }

        try {
            $turno = $accion->ejecutar($this->actor(), $caja, $normalizado);
        } catch (ValidationException $e) {
            // El motivo se muestra en el modal (antes quedaba oculto y el botón «no hacía nada»).
            foreach ($e->errors() as $campo => $mensajes) {
                $this->addError($campo === 'monto_apertura' ? 'fondoInicial' : 'caja_nombre', $mensajes[0]);
            }

            return;
        }

        $this->modalAperturaAbierto = false;
        $this->cajaAperturaId = null;
        $this->dispatch('turno-modificado');
        $this->avisar(__('Turno #:id de :caja abierto con éxito.', ['id' => $turno->id, 'caja' => $caja->nombre]), 'success');
    }

    #[On('abrir-cierre-caja')]
    public function abrirModalCierre(?int $turnoId = null): void
    {
        $turno = $turnoId === null ? $this->turnoActual() : $this->turnoAbierto($turnoId);

        if ($turno === null) {
            $this->avisar(__('No hay un turno abierto para cerrar.'), 'danger');

            return;
        }

        if (! $turno->usuarioPuedeCerrar($this->actor())) {
            $this->avisar(__('No tienes autorización para cerrar este turno.'), 'danger');

            return;
        }

        $this->resetValidation(['montoCierre', 'observacionesCierre']);
        $this->turnoCierreId = $turnoId === null ? null : $turno->id;
        $this->montoCierre = $turno->calcularArqueo()['efectivo_esperado'];
        $this->observacionesCierre = '';
        $this->modalCierreAbierto = true;
    }

    public function cerrarModalCierre(): void
    {
        $this->modalCierreAbierto = false;
        $this->turnoCierreId = null;
        $this->reset('montoCierre', 'observacionesCierre');
        $this->resetValidation(['montoCierre', 'observacionesCierre']);
    }

    public function ejecutarCierre(CerrarTurnoCaja $accion): void
    {
        $turno = $this->turnoCierre();

        if ($turno === null) {
            $this->cerrarModalCierre();
            $this->avisar(__('Ese turno ya no está abierto.'), 'danger');

            return;
        }

        $normalizado = Decimal::normalizar($this->montoCierre, Decimal::ESCALA_DINERO);

        if ($normalizado === null || Decimal::comparar($normalizado, '0', Decimal::ESCALA_DINERO) < 0) {
            $this->addError('montoCierre', __('Ingresa un importe de efectivo contado válido.'));

            return;
        }

        try {
            $turno = $accion->ejecutar($this->actor(), $turno, $normalizado, $this->observacionesCierre);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensajes) {
                $this->addError($campo === 'observaciones' ? 'observacionesCierre' : 'montoCierre', $mensajes[0]);
            }

            return;
        }

        $arqueo = $turno->calcularArqueo($normalizado);
        $this->modalCierreAbierto = false;
        $this->turnoCierreId = null;
        $this->reset('montoCierre', 'observacionesCierre');
        $this->dispatch('turno-modificado');

        $mensajeDiferencia = match ($arqueo['estado_diferencia']) {
            'cuadrada' => __('Caja cuadrada.'),
            'sobrante' => __('Sobrante de :monto.', ['monto' => Decimal::bs($arqueo['diferencia'])]),
            'faltante' => __('Faltante de :monto.', ['monto' => Decimal::bs(ltrim($arqueo['diferencia'], '-'))]),
            default => '',
        };

        $this->avisar(__('Turno de caja #:id cerrado con éxito. :dif', [
            'id' => $turno->id,
            'dif' => $mensajeDiferencia,
        ]), 'success');
    }

    public function render(): View
    {
        $actor = $this->actor();

        if (! $actor->perteneceAEmpresa()) {
            return view('livewire.caja.control-caja-topbar', ['visible' => false]);
        }

        $caja = $this->cajaActual();
        $turno = $caja === null ? null : $this->turnoDe($caja);
        // El administrador puede tener varias cajas abiertas: la barra muestra una y avisa del resto.
        $misTurnos = $actor->esAdministrador() ? app(CajaOperacion::class)->turnosAbiertosDe($actor) : collect();
        $turnoCierre = $this->modalCierreAbierto ? $this->turnoCierre()?->loadMissing(['caja', 'sucursal:id,nombre', 'usuarioApertura:id,name']) : null;

        return view('livewire.caja.control-caja-topbar', [
            'visible' => true,
            'caja' => $caja,
            'turno' => $turno,
            'otrosTurnos' => $misTurnos->reject(fn (TurnoCaja $t): bool => $t->id === $turno?->id)->values(),
            'misTurnos' => $misTurnos,
            'cajaApertura' => $this->modalAperturaAbierto ? $this->cajaParaAbrir($this->cajaAperturaId)?->loadMissing('sucursal:id,nombre') : null,
            'turnoCierre' => $turnoCierre,
            'arqueoCierre' => $turnoCierre?->calcularArqueo($this->montoCierre),
        ]);
    }

    /**
     * Vendedor: su caja asignada de su sucursal. Administrador: en la sucursal activa (o, si no
     * eligió una, en la de su primer turno abierto), la caja del turno que él abrió, la principal
     * o la primera activa (CajaOperacion::cajaDe).
     */
    private function cajaActual(): ?Caja
    {
        $actor = $this->actor();

        if (! $actor->perteneceAEmpresa()) {
            return null;
        }

        $sucursalId = $actor->sucursalActivaId() ?? ($actor->esAdministrador()
            ? (app(CajaOperacion::class)->turnosAbiertosDe($actor)->first()->sucursal_id
                ?? Sucursal::withoutGlobalScopes()->where('empresa_id', $actor->empresa_id)->where('activa', true)->orderBy('id')->value('id'))
            : null);

        return $sucursalId === null ? null : app(CajaOperacion::class)->cajaDe($actor, (int) $sucursalId);
    }

    /**
     * Caja a abrir: la de la barra o, para el administrador, una caja activa de su empresa.
     * El vendedor solo abre la suya: otra caja enviada por la solicitud no se acepta.
     */
    private function cajaParaAbrir(?int $cajaId): ?Caja
    {
        $actor = $this->actor();

        if ($cajaId === null) {
            return $this->cajaActual();
        }

        if (! $actor->esAdministrador()) {
            $propia = $this->cajaActual();

            return $propia?->id === $cajaId ? $propia : null;
        }

        return Caja::withoutGlobalScopes()->where('empresa_id', $actor->empresa_id)->where('activa', true)->find($cajaId);
    }

    private function turnoActual(): ?TurnoCaja
    {
        $caja = $this->cajaActual();

        return $caja === null ? null : $this->turnoDe($caja);
    }

    private function turnoDe(Caja $caja): ?TurnoCaja
    {
        return TurnoCaja::withoutGlobalScopes()
            ->where('empresa_id', $caja->empresa_id)
            ->where('caja_id', $caja->id)
            ->where('estado', 'abierto')
            ->first();
    }

    /** Turno abierto de la empresa del usuario; quién puede cerrarlo lo decide usuarioPuedeCerrar. */
    private function turnoAbierto(int $turnoId): ?TurnoCaja
    {
        return TurnoCaja::withoutGlobalScopes()
            ->where('empresa_id', $this->actor()->empresa_id)
            ->where('estado', 'abierto')
            ->find($turnoId);
    }

    private function turnoCierre(): ?TurnoCaja
    {
        return $this->turnoCierreId === null ? $this->turnoActual() : $this->turnoAbierto($this->turnoCierreId);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
