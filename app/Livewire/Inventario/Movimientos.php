<?php

namespace App\Livewire\Inventario;

use App\Domain\Inventario\Consultas\HistorialMovimientos;
use App\Enums\TipoOperacionInventario;
use App\Livewire\Concerns\MuestraAvisos;
use App\Livewire\Concerns\UsaSucursalActiva;
use App\Models\OperacionInventario;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Ajustes y traspasos')]
class Movimientos extends Component
{
    use MuestraAvisos, UsaSucursalActiva, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    #[Url(except: '')]
    public string $tipo = '';

    #[Url(as: 'sucursal', except: '')]
    public string $sucursal = '';

    public function mount(): void
    {
        $this->authorize('viewAny', OperacionInventario::class);
        $this->aplicarSucursalActiva();

        if (is_string($mensaje = session('aviso'))) {
            $this->avisar($mensaje);
        }
    }

    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['buscar', 'tipo', 'sucursal'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return array{buscar: string, tipo: string, sucursal_id: int|null}
     */
    public function filtros(): array
    {
        return [
            'buscar' => $this->buscar,
            'tipo' => in_array($this->tipo, array_column(TipoOperacionInventario::cases(), 'value'), true) ? $this->tipo : '',
            'sucursal_id' => ctype_digit($this->sucursal) ? (int) $this->sucursal : null,
        ];
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = Auth::user();

        return view('livewire.inventario.movimientos', [
            'movimientos' => HistorialMovimientos::consulta((int) $actor->empresa_id, $this->filtros())->paginate(30),
            'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }
}
