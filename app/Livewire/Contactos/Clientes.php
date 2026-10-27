<?php

namespace App\Livewire\Contactos;

use App\Domain\Contactos\Consultas\ListadoClientes;
use App\Domain\Reportes\Periodo;
use App\Domain\Ventas\Actions\GuardarCliente;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Directorio de clientes (clientes.html V3). Cualquier usuario de la empresa registra
 * clientes; el vendedor solo ve el historial de ventas de su sucursal.
 */
#[Title('Clientes')]
class Clientes extends Component
{
    use MuestraAvisos, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    public bool $formCliente = false;

    /** Cliente en edición desde el directorio; null = alta nueva. */
    #[Locked]
    public ?int $editandoId = null;

    public string $clienteNombre = '';

    public string $clienteDocumento = '';

    public string $clienteTelefono = '';

    public string $clienteCorreo = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Cliente::class);
    }

    public function updatedBuscar(): void
    {
        $this->resetPage();
    }

    public function nuevoCliente(): void
    {
        $this->authorize('create', Cliente::class);
        $this->resetValidation();
        $this->reset('clienteNombre', 'clienteDocumento', 'clienteTelefono', 'clienteCorreo', 'editandoId');
        $this->formCliente = true;
    }

    public function editarCliente(int $id): void
    {
        $cliente = Cliente::query()->findOrFail($id);
        $this->authorize('update', $cliente);
        $this->resetValidation();
        $this->editandoId = $cliente->id;
        $this->clienteNombre = $cliente->nombre;
        $this->clienteDocumento = (string) $cliente->documento;
        $this->clienteTelefono = (string) $cliente->telefono;
        $this->clienteCorreo = (string) $cliente->correo;
        $this->formCliente = true;
    }

    public function actualizarCliente(GuardarCliente $accion): void
    {
        $cliente = $accion->actualizar($this->actor(), Cliente::query()->findOrFail($this->editandoId), [
            'nombre' => $this->clienteNombre, 'documento' => $this->clienteDocumento,
            'telefono' => $this->clienteTelefono, 'correo' => $this->clienteCorreo,
        ]);
        $this->formCliente = false;
        $this->editandoId = null;
        $this->avisar(__('Datos de :nombre actualizados.', ['nombre' => $cliente->nombre]));
    }

    public function crearCliente(GuardarCliente $accion): void
    {
        $cliente = $accion->crear($this->actor(), [
            'nombre' => $this->clienteNombre, 'documento' => $this->clienteDocumento,
            'telefono' => $this->clienteTelefono, 'correo' => $this->clienteCorreo,
        ]);
        $this->formCliente = false;
        $this->avisar(__('Cliente :nombre registrado.', ['nombre' => $cliente->nombre]));
    }

    public function cerrar(): void
    {
        $this->formCliente = false;
        $this->resetValidation();
    }

    public function render(): View
    {
        $actor = $this->actor();
        $sucursalId = $actor->sucursalActivaId();

        return view('livewire.contactos.clientes', [
            'clientes' => ListadoClientes::consulta((int) $actor->empresa_id, $this->buscar, $sucursalId)->paginate(25),
            'resumen' => ListadoClientes::resumen((int) $actor->empresa_id, Periodo::mesActual(), $sucursalId),
            'empresa' => $actor->empresa?->nombre,
        ]);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
