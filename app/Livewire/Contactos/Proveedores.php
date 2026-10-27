<?php

namespace App\Livewire\Contactos;

use App\Domain\Compras\Actions\GuardarProveedor;
use App\Domain\Contactos\Consultas\ListadoProveedores;
use App\Domain\Reportes\Periodo;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Directorio de proveedores (proveedores.html V3). Solo administradores.
 */
#[Title('Proveedores')]
class Proveedores extends Component
{
    use MuestraAvisos, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    public bool $formProveedor = false;

    /** Proveedor en edición desde el directorio; null = alta nueva. */
    #[Locked]
    public ?int $editandoId = null;

    public string $nombre = '';

    public string $documento = '';

    public string $telefono = '';

    public string $correo = '';

    public string $ciudad = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Proveedor::class);
    }

    public function updatedBuscar(): void
    {
        $this->resetPage();
    }

    public function nuevoProveedor(): void
    {
        $this->authorize('create', Proveedor::class);
        $this->resetValidation();
        $this->reset('nombre', 'documento', 'telefono', 'correo', 'ciudad', 'editandoId');
        $this->formProveedor = true;
    }

    public function editarProveedor(int $id): void
    {
        $proveedor = Proveedor::query()->findOrFail($id);
        $this->authorize('update', $proveedor);
        $this->resetValidation();
        $this->editandoId = $proveedor->id;
        $this->nombre = $proveedor->nombre;
        $this->documento = (string) $proveedor->documento;
        $this->telefono = (string) $proveedor->telefono;
        $this->correo = (string) $proveedor->correo;
        $this->ciudad = (string) $proveedor->ciudad;
        $this->formProveedor = true;
    }

    public function actualizarProveedor(GuardarProveedor $accion): void
    {
        $proveedor = $accion->actualizar($this->actor(), Proveedor::query()->findOrFail($this->editandoId),
            $this->only(['nombre', 'documento', 'telefono', 'correo', 'ciudad']));
        $this->formProveedor = false;
        $this->editandoId = null;
        $this->avisar(__('Datos de :nombre actualizados.', ['nombre' => $proveedor->nombre]));
    }

    public function crearProveedor(GuardarProveedor $accion): void
    {
        $proveedor = $accion->crear($this->actor(), $this->only(['nombre', 'documento', 'telefono', 'correo', 'ciudad']));
        $this->formProveedor = false;
        $this->avisar(__('Proveedor :nombre registrado.', ['nombre' => $proveedor->nombre]));
    }

    public function cerrar(): void
    {
        $this->formProveedor = false;
        $this->resetValidation();
    }

    public function render(): View
    {
        $actor = $this->actor();

        return view('livewire.contactos.proveedores', [
            'proveedores' => ListadoProveedores::consulta((int) $actor->empresa_id, $this->buscar)->paginate(25),
            'resumen' => ListadoProveedores::resumen((int) $actor->empresa_id, Periodo::mesActual()),
            'empresa' => $actor->empresa?->nombre,
        ]);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
