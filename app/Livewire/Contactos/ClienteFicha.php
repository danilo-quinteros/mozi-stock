<?php

namespace App\Livewire\Contactos;

use App\Domain\Ventas\Actions\GuardarCliente;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Cliente;
use App\Models\User;
use App\Models\Venta;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Ficha del cliente: datos de contacto e historial de ventas y créditos de la empresa. El
 * vendedor solo ve ventas de su sucursal (misma regla que VentaPolicy).
 */
class ClienteFicha extends Component
{
    use MuestraAvisos, WithPagination;

    #[Locked]
    public int $clienteId;

    public bool $formCliente = false;

    public bool $confirmandoEstado = false;

    public string $clienteNombre = '';

    public string $clienteDocumento = '';

    public string $clienteTelefono = '';

    public string $clienteCorreo = '';

    public function mount(Cliente $cliente): void
    {
        $this->authorize('view', $cliente);
        $this->clienteId = $cliente->id;
    }

    public function editar(): void
    {
        $cliente = $this->cliente();
        $this->authorize('update', $cliente);
        $this->resetValidation();
        $this->clienteNombre = $cliente->nombre;
        $this->clienteDocumento = (string) $cliente->documento;
        $this->clienteTelefono = (string) $cliente->telefono;
        $this->clienteCorreo = (string) $cliente->correo;
        $this->formCliente = true;
    }

    public function guardar(GuardarCliente $accion): void
    {
        $accion->actualizar($this->actor(), $this->cliente(), [
            'nombre' => $this->clienteNombre, 'documento' => $this->clienteDocumento,
            'telefono' => $this->clienteTelefono, 'correo' => $this->clienteCorreo,
        ]);
        $this->formCliente = false;
        $this->avisar(__('Datos del cliente actualizados.'));
    }

    public function cambiarEstado(GuardarCliente $accion): void
    {
        $cliente = $this->cliente();
        $accion->cambiarEstado($this->actor(), $cliente, ! $cliente->activo);
        $this->confirmandoEstado = false;
        $this->avisar($cliente->activo ? __('Cliente activado.') : __('Cliente desactivado: ya no aparece en nuevos pedidos.'));
    }

    public function cerrar(): void
    {
        $this->formCliente = false;
        $this->confirmandoEstado = false;
        $this->resetValidation();
    }

    public function render(): View
    {
        $actor = $this->actor();
        $cliente = $this->cliente();
        $sucursalId = $actor->sucursalActivaId();
        $ventas = Venta::query()
            ->where('cliente_id', $cliente->id)
            ->when($sucursalId !== null, fn (Builder $q) => $q->where('sucursal_id', $sucursalId));
        $totales = (clone $ventas)->toBase()->selectRaw('COALESCE(SUM(total), 0) AS vendido, COALESCE(SUM(devuelto), 0) AS devuelto')
            ->selectRaw('COALESCE(SUM('.Venta::sqlCobrado('ventas').'), 0) AS cobrado, COALESCE(SUM(saldo_pendiente), 0) AS saldo')->first();

        return view('livewire.contactos.cliente-ficha', [
            'cliente' => $cliente,
            'ventas' => $ventas->with(['sucursal:id,nombre', 'vendedor:id,name'])->orderByDesc('fecha')->orderByDesc('id')->paginate(15),
            'totales' => [
                'vendido' => Decimal::redondear((string) ($totales->vendido ?? '0')),
                'devuelto' => Decimal::redondear((string) ($totales->devuelto ?? '0')),
                'cobrado' => Decimal::redondear((string) ($totales->cobrado ?? '0')),
                'saldo' => Decimal::redondear((string) ($totales->saldo ?? '0')),
            ],
            'administra' => $actor->esAdministrador(),
            'cotizaciones' => DB::table('pedidos')->where('empresa_id', $cliente->empresa_id)->where('cliente_id', $cliente->id)
                ->where('estado', 'pendiente')->when($sucursalId !== null, fn ($q) => $q->where('sucursal_id', $sucursalId))->count(),
        ])->title($cliente->nombre);
    }

    private function cliente(): Cliente
    {
        return Cliente::query()->findOrFail($this->clienteId);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
