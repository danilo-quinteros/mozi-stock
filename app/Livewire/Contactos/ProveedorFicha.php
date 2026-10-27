<?php

namespace App\Livewire\Contactos;

use App\Domain\Compras\Actions\GuardarProveedor;
use App\Domain\Compras\Consultas\ListadoCompras;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Proveedor;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Ficha del proveedor: datos de contacto y compras asociadas.
 */
class ProveedorFicha extends Component
{
    use MuestraAvisos, WithPagination;

    #[Locked]
    public int $proveedorId;

    public bool $formProveedor = false;

    public bool $confirmandoEstado = false;

    public string $nombre = '';

    public string $documento = '';

    public string $telefono = '';

    public string $correo = '';

    public string $ciudad = '';

    public function mount(Proveedor $proveedor): void
    {
        $this->authorize('view', $proveedor);
        $this->proveedorId = $proveedor->id;
    }

    public function editar(): void
    {
        $proveedor = $this->proveedor();
        $this->authorize('update', $proveedor);
        $this->resetValidation();
        $this->nombre = $proveedor->nombre;
        $this->documento = (string) $proveedor->documento;
        $this->telefono = (string) $proveedor->telefono;
        $this->correo = (string) $proveedor->correo;
        $this->ciudad = (string) $proveedor->ciudad;
        $this->formProveedor = true;
    }

    public function guardar(GuardarProveedor $accion): void
    {
        $accion->actualizar($this->actor(), $this->proveedor(), $this->only(['nombre', 'documento', 'telefono', 'correo', 'ciudad']));
        $this->formProveedor = false;
        $this->avisar(__('Datos del proveedor actualizados.'));
    }

    public function cambiarEstado(GuardarProveedor $accion): void
    {
        $proveedor = $accion->cambiarEstado($this->actor(), $this->proveedor(), ! $this->proveedor()->activo);
        $this->confirmandoEstado = false;
        $this->avisar($proveedor->activo ? __('Proveedor activado.') : __('Proveedor desactivado: ya no aparece en nuevas compras.'));
    }

    public function cerrar(): void
    {
        $this->formProveedor = false;
        $this->confirmandoEstado = false;
        $this->resetValidation();
    }

    public function render(): View
    {
        $proveedor = $this->proveedor();
        $compras = ListadoCompras::consulta((int) $this->actor()->empresa_id, '', null, $proveedor->id);

        $totalComprado = Decimal::redondear((string) (clone $compras)->reorder()->sum('c.total'));

        return view('livewire.contactos.proveedor-ficha', [
            'proveedor' => $proveedor,
            'compras' => $compras->paginate(15),
            'totalComprado' => $totalComprado,
        ])->title($proveedor->nombre);
    }

    private function proveedor(): Proveedor
    {
        return Proveedor::query()->findOrFail($this->proveedorId);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
