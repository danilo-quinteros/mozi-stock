<?php

namespace App\Livewire\Empresa;

use App\Domain\Tenancy\Actions\GuardarSucursal;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Sucursales')]
class Sucursales extends Component
{
    use MuestraAvisos;

    /** Solo el formulario de la sucursal principal, dentro del popup de la guía de configuración del Inicio. */
    #[Locked]
    public bool $embebido = false;

    public bool $formularioAbierto = false;

    #[Locked]
    public ?int $editandoId = null;

    #[Locked]
    public ?int $confirmandoEstadoId = null;

    public string $nombre = '';

    public string $codigo = '';

    public string $direccion = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Sucursal::class);

        if ($this->embebido) {
            $principal = Sucursal::query()->where('activa', true)->orderBy('id')->first();
            $principal === null ? $this->crear() : $this->editar($principal->id);
        }
    }

    public function crear(): void
    {
        $this->authorize('create', Sucursal::class);
        $this->resetValidation();
        $this->reset('editandoId', 'nombre', 'codigo', 'direccion');
        $this->formularioAbierto = true;
    }

    public function editar(int $id): void
    {
        // El scope de empresa responde 404 para sucursales de otra empresa.
        $sucursal = Sucursal::query()->findOrFail($id);
        $this->authorize('update', $sucursal);
        $this->resetValidation();
        $this->editandoId = $sucursal->id;
        $this->nombre = $sucursal->nombre;
        $this->codigo = $sucursal->codigo;
        $this->direccion = (string) $sucursal->direccion;
        $this->formularioAbierto = true;
    }

    public function guardar(GuardarSucursal $accion): void
    {
        $actor = $this->actor();
        $datos = $this->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'codigo' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('sucursales', 'codigo')->where('empresa_id', $actor->empresa_id)->ignore($this->editandoId)],
            'direccion' => ['nullable', 'string', 'max:255'],
        ], [
            'codigo.regex' => __('Usa solo letras, números y guiones.'),
            'codigo.unique' => __('Ya existe una sucursal con ese código en tu empresa.'),
        ]);

        if ($this->editandoId === null) {
            $sucursal = $accion->crear($actor, $datos);
            $this->avisar(__('Sucursal registrada.'));
        } else {
            $sucursal = $accion->actualizar($actor, Sucursal::query()->findOrFail($this->editandoId), $datos);
            $this->avisar(__('Sucursal actualizada.'));
        }

        if ($this->embebido) {
            // El popup sigue abierto con la sucursal guardada; lo cierra la guía.
            $this->editandoId = $sucursal->id;

            return;
        }

        $this->cerrarFormulario();
    }

    public function cerrarFormulario(): void
    {
        $this->formularioAbierto = false;
        $this->reset('editandoId', 'nombre', 'codigo', 'direccion');
        $this->resetValidation();
    }

    public function confirmarEstado(int $id): void
    {
        $sucursal = Sucursal::query()->findOrFail($id);
        $this->authorize('update', $sucursal);
        $this->resetValidation();
        $this->confirmandoEstadoId = $sucursal->id;
    }

    public function cambiarEstado(GuardarSucursal $accion): void
    {
        if ($this->confirmandoEstadoId === null) {
            return;
        }

        $actual = Sucursal::query()->findOrFail($this->confirmandoEstadoId);
        $sucursal = $accion->cambiarEstado($this->actor(), $actual, ! $actual->activa);
        $this->confirmandoEstadoId = null;
        $this->avisar($sucursal->activa ? __('Sucursal activada.') : __('Sucursal desactivada.'));
    }

    public function cancelarEstado(): void
    {
        $this->confirmandoEstadoId = null;
        $this->resetValidation();
    }

    public function render(): View
    {
        $sucursales = Sucursal::query()
            ->withCount(['usuarios' => fn ($q) => $q->where('activo', true)])
            ->orderByDesc('activa')
            ->orderBy('nombre')
            ->get();

        return view('livewire.empresa.sucursales', [
            'sucursales' => $sucursales,
            'confirmando' => $sucursales->firstWhere('id', $this->confirmandoEstadoId),
        ]);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
