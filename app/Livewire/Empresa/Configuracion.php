<?php

namespace App\Livewire\Empresa;

use App\Domain\Tenancy\Actions\ActualizarEmpresa;
use App\Domain\Tenancy\DatosEmpresa;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Configuración')]
class Configuracion extends Component
{
    use MuestraAvisos;

    /** Solo el formulario, dentro del popup de la guía de configuración del Inicio. */
    #[Locked]
    public bool $embebido = false;

    public string $nombre = '';

    public string $razon_social = '';

    public string $email = '';

    public string $telefono = '';

    public string $nit = '';

    public function mount(): void
    {
        $empresa = $this->empresa();
        $this->authorize('update', $empresa);
        $this->nombre = $empresa->nombre;
        $this->razon_social = (string) $empresa->razon_social;
        $this->email = (string) $empresa->email;
        $this->telefono = (string) $empresa->telefono;
        $this->nit = (string) $empresa->nit;
    }

    public function guardar(ActualizarEmpresa $accion): void
    {
        $datos = $this->validate(DatosEmpresa::reglas(), [], DatosEmpresa::atributos());
        $accion->ejecutar($this->actor(), $this->empresa(), DatosEmpresa::normalizar($datos));
        $this->avisar(__('Datos de la empresa guardados.'));
    }

    public function render(): View
    {
        return view('livewire.empresa.configuracion', ['empresa' => $this->empresa()]);
    }

    /**
     * Siempre la empresa del usuario autenticado; no se recibe ningún ID del navegador.
     */
    private function empresa(): Empresa
    {
        return Empresa::query()->findOrFail($this->actor()->empresa_id);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
