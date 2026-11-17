<?php

namespace App\Livewire\Produccion;

use App\Domain\Produccion\Consultas\ListadoRecetas;
use App\Livewire\Concerns\MuestraAvisos;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Producción → Recetas (ESPECIFICACION_PRODUCCION.md § 3 y § 5): productos fabricados con su receta vigente.
 * Solo el administrador con la capacidad (ruta + gate usar-produccion).
 */
#[Title('Recetas')]
class Recetas extends Component
{
    use MuestraAvisos, WithPagination;

    #[Url(except: 'activas')]
    public string $estado = 'activas';

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    public function mount(): void
    {
        $this->authorize('usar-produccion');

        if (is_string($mensaje = session('aviso'))) {
            $this->avisar($mensaje);
        }
    }

    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['estado', 'buscar'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $estado = in_array($this->estado, ListadoRecetas::ESTADOS, true) ? $this->estado : 'activas';

        return view('livewire.produccion.recetas', [
            'recetas' => ListadoRecetas::consulta($this->buscar, $estado)->paginate(30),
            'estadoActivo' => $estado,
        ]);
    }
}
