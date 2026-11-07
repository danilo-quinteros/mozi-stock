<?php

namespace App\Livewire\Creditos;

use App\Domain\Creditos\Actions\RegistrarCobro;
use App\Livewire\Concerns\FormularioPago;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\CuentaCobrar;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Cuenta por cobrar manual (§ 5): datos, cobros aplicados y registro de un cobro en la misma
 * pantalla. No es una venta: no mueve stock ni suma como vendido. El administrador anula sus
 * cobros o la cuenta con el modal compartido (Cuentas\Anular).
 */
class Cuenta extends Component
{
    use FormularioPago, MuestraAvisos;

    #[Locked]
    public int $cuentaId;

    public ?string $sucursal_id = null;

    public function mount(CuentaCobrar $cuenta): void
    {
        $this->authorize('view', $cuenta);
        $this->cuentaId = $cuenta->id;
        $actor = $this->actor();
        $this->sucursal_id = (string) ($actor->esVendedor() ? $actor->sucursal_id : ($actor->sucursalActivaId() ?? $cuenta->sucursal_id));
        $this->iniciarPago();

        if (is_string($mensaje = session('aviso'))) {
            $this->avisar($mensaje);
        }
    }

    public function registrar(RegistrarCobro $accion): void
    {
        $this->resetValidation();
        $cobro = $accion->ejecutar($this->actor(), $this->datosPago() + [
            'cuenta_id' => $this->cuentaId,
            'sucursal_id' => blank($this->sucursal_id) ? null : (int) $this->sucursal_id,
        ]);

        $this->iniciarPago();
        $this->avisar(__('Cobro :codigo de :importe registrado.', ['codigo' => $cobro->codigo(), 'importe' => Decimal::bs($cobro->monto)]));
    }

    public function render(): View
    {
        $actor = $this->actor();
        $cuenta = CuentaCobrar::query()->with([
            'cliente:id,nombre,documento', 'sucursal:id,nombre', 'usuario:id,name', 'anuladoPor:id,name',
            'aplicaciones' => fn ($q) => $q->with(['cobro' => fn ($q) => $q->with(['sucursal:id,nombre', 'receptor:id,name', 'anuladoPor:id,name'])]),
        ])->findOrFail($this->cuentaId);

        return view('livewire.creditos.cuenta', [
            'cuenta' => $cuenta,
            'cobrado' => Decimal::restar($cuenta->importe, $cuenta->saldo_pendiente, Decimal::ESCALA_DINERO),
            'pendiente' => ! $cuenta->estaAnulado() && Decimal::comparar($cuenta->saldo_pendiente, '0', 2) > 0,
            'sumaMixta' => $this->sumaMixta(),
            'administra' => $actor->esAdministrador(),
            'sucursales' => $actor->esAdministrador()
                ? Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre'])
                : Sucursal::query()->whereKey($actor->sucursal_id)->get(['id', 'nombre']),
        ])->title(__('Cuenta por cobrar · :codigo', ['codigo' => $cuenta->codigo()]));
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
