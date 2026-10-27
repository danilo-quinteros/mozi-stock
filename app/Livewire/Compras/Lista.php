<?php

namespace App\Livewire\Compras;

use App\Domain\Compras\Consultas\ListadoCompras;
use App\Domain\Reportes\Periodo;
use App\Livewire\Concerns\MuestraAvisos;
use App\Livewire\Concerns\UsaSucursalActiva;
use App\Models\Compra;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Compras')]
class Lista extends Component
{
    use MuestraAvisos, UsaSucursalActiva, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    #[Url(except: '')]
    public string $sucursal = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Compra::class);
        $this->aplicarSucursalActiva();
    }

    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['buscar', 'sucursal'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = Auth::user();
        $empresaId = (int) $actor->empresa_id;

        return view('livewire.compras.lista', [
            'compras' => ListadoCompras::consulta($empresaId, $this->buscar, ctype_digit($this->sucursal) ? (int) $this->sucursal : null)->paginate(25),
            'mes' => ListadoCompras::totales($empresaId, Periodo::mesActual()),
            'registradas' => Compra::query()->count(),
            'proveedoresActivos' => Proveedor::query()->where('activo', true)->count(),
            'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }
}
