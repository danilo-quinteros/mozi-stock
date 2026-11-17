<?php

namespace App\Livewire\Inventario;

use App\Domain\Inventario\Consultas\ReporteVencimientos;
use App\Livewire\Concerns\MuestraAvisos;
use App\Livewire\Concerns\RegularizaLotes;
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

/**
 * Reporte «Vencimientos» (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 4 y § 9.3), bajo Inventario. Solo con la capacidad
 * (middleware de la ruta) y con la misma Policy que el historial de inventario (§ 8): el administrador.
 *
 * Desde «Sin fecha» se regulariza: el stock del lote de regularización de un producto en una sucursal pasa a uno o
 * más lotes reales con su número y vencimiento (RegularizaLotes → RegularizarLote, un ajuste con nota obligatoria).
 */
#[Title('Vencimientos')]
class Vencimientos extends Component
{
    use MuestraAvisos, RegularizaLotes, UsaSucursalActiva, WithPagination;

    #[Url(except: 'todos')]
    public string $estado = 'todos';

    #[Url(as: 'sucursal', except: '')]
    public string $sucursal = '';

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    public function mount(): void
    {
        $this->authorize('viewAny', OperacionInventario::class);
        $this->aplicarSucursalActiva();
    }

    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['estado', 'sucursal', 'buscar'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return array{estado: string, sucursal_id: int|null, buscar: string}
     */
    public function filtros(): array
    {
        return [
            'estado' => in_array($this->estado, ReporteVencimientos::ESTADOS, true) ? $this->estado : 'todos',
            'sucursal_id' => ctype_digit($this->sucursal) ? (int) $this->sucursal : null,
            'buscar' => $this->buscar,
        ];
    }

    public function render(): View
    {
        $empresaId = (int) $this->actor()->empresa_id;
        $filtros = $this->filtros();

        return view('livewire.inventario.vencimientos', [
            'lotes' => ReporteVencimientos::consulta($empresaId, $filtros)->paginate(30),
            'resumen' => ReporteVencimientos::resumen($empresaId, $filtros['sucursal_id']),
            'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre']),
            'filtrosActivos' => $filtros,
            'regularizando' => $this->regularizando(),
        ]);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
