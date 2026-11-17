<?php

namespace App\Livewire\Produccion;

use App\Domain\Produccion\Consultas\ListadoOrdenes;
use App\Livewire\Concerns\MuestraAvisos;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Producción → Órdenes (ESPECIFICACION_PRODUCCION.md § 3 y § 6): entrada del módulo. Abiertas (emitidas y aprobadas)
 * por fecha programada; finalizadas y anuladas de la más nueva a la más antigua. Solo el administrador con la
 * capacidad (ruta + gate usar-produccion).
 */
#[Title('Órdenes de producción')]
class Ordenes extends Component
{
    use MuestraAvisos, WithPagination;

    #[Url(except: 'abiertas')]
    public string $estado = 'abiertas';

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
        $estado = in_array($this->estado, ListadoOrdenes::ESTADOS, true) ? $this->estado : 'abiertas';

        return view('livewire.produccion.ordenes', [
            'ordenes' => ListadoOrdenes::consulta($this->buscar, $estado)->paginate(30),
            'estadoActivo' => $estado,
        ]);
    }
}
