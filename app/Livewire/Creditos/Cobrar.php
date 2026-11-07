<?php

namespace App\Livewire\Creditos;

use App\Domain\Creditos\Actions\RegistrarCobro;
use App\Domain\Creditos\Consultas\CuentasPorCobrar;
use App\Domain\Creditos\Consultas\PendientesCobro;
use App\Livewire\Concerns\FormularioPago;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Cobro a un cliente (§ 8): un solo pago repartido entre sus ventas a crédito, cuotas y
 * cuentas manuales, automáticamente (vencimientos más antiguos primero) o a mano. El reparto
 * y los saldos que se muestran son informativos; RegistrarCobro los valida con bloqueo.
 */
#[Title('Registrar cobro')]
class Cobrar extends Component
{
    use FormularioPago, MuestraAvisos;

    #[Url(except: '')]
    public string $cliente = '';

    public ?string $sucursal_id = null;

    public string $modo = 'automatico';

    /** @var array<string, string> */
    public array $asignaciones = [];

    public function mount(): void
    {
        $this->authorize('viewAny', Pedido::class);
        $actor = $this->actor();
        $this->sucursal_id = (string) ($actor->esVendedor() ? $actor->sucursal_id
            : ($actor->sucursalActivaId() ?? Sucursal::query()->where('activa', true)->orderBy('nombre')->value('id')));
        $this->iniciarPago();
    }

    public function updatedCliente(): void
    {
        $this->resetValidation();
        $this->reset('asignaciones');
    }

    public function registrar(RegistrarCobro $accion): void
    {
        $this->resetValidation();
        $cobro = $accion->ejecutar($this->actor(), $this->datosPago() + [
            'cliente_id' => ctype_digit($this->cliente) ? (int) $this->cliente : null,
            'sucursal_id' => blank($this->sucursal_id) ? null : (int) $this->sucursal_id,
            'modo' => $this->modo,
            'asignaciones' => $this->asignaciones,
        ]);

        $this->iniciarPago();
        $this->reset('asignaciones');
        $this->avisar(__('Cobro :codigo de :importe registrado.', ['codigo' => $cobro->codigo(), 'importe' => Decimal::bs($cobro->monto)]));
    }

    public function render(): View
    {
        $actor = $this->actor();
        // El scope de empresa descarta clientes de otra empresa.
        $cliente = ctype_digit($this->cliente) ? Cliente::query()->find((int) $this->cliente) : null;
        $items = $cliente === null ? [] : PendientesCobro::cargar('cliente', $cliente->id)['items'];

        return view('livewire.creditos.cobrar', [
            'clientes' => CuentasPorCobrar::clientesConSaldo((int) $actor->empresa_id),
            'elegido' => $cliente,
            'items' => $items,
            'saldoTotal' => array_reduce($items, fn (string $suma, array $i) => Decimal::sumar($suma, $i['saldo'], 2), '0.00'),
            'sumaMixta' => $this->sumaMixta(),
            'administra' => $actor->esAdministrador(),
            'sucursales' => $actor->esAdministrador()
                ? Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre'])
                : Sucursal::query()->whereKey($actor->sucursal_id)->get(['id', 'nombre']),
        ]);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
