<?php

namespace App\Livewire\CuentasPagar;

use App\Domain\CuentasPagar\Consultas\CuentasPorPagar;
use App\Models\CuentaPagar;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Cuentas por pagar (§ 15): obligaciones de compras, egresos y manuales con lo pagado, el
 * saldo y el vencimiento. Solo administradores.
 */
#[Title('Cuentas por pagar')]
class Lista extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    #[Url(except: 'todas')]
    public string $estado = 'todas';

    #[Url(except: '')]
    public string $sucursal = '';

    #[Url(except: '')]
    public string $proveedor = '';

    #[Url(except: '')]
    public string $origen = '';

    #[Url(except: '')]
    public string $desde = '';

    #[Url(except: '')]
    public string $hasta = '';

    public function mount(): void
    {
        $this->authorize('viewAny', CuentaPagar::class);

        if (! in_array($this->estado, CuentasPorPagar::ESTADOS, true)) {
            $this->estado = 'todas';
        }

        $activa = $this->actor()->sucursalActivaId();

        if ($activa !== null && $this->sucursal === '') {
            $this->sucursal = (string) $activa;
        }
    }

    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['buscar', 'estado', 'sucursal', 'proveedor', 'origen', 'desde', 'hasta'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $empresaId = (int) $this->actor()->empresa_id;
        $sucursalId = ctype_digit($this->sucursal) ? (int) $this->sucursal : null;
        $fecha = fn (string $valor) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) ? $valor : null;

        return view('livewire.cuentas-pagar.lista', [
            'cuentas' => CuentasPorPagar::consulta($empresaId, $this->estado, $sucursalId, ctype_digit($this->proveedor) ? (int) $this->proveedor : null,
                $this->origen, $this->buscar, $fecha($this->desde), $fecha($this->hasta))->paginate(25),
            'resumen' => CuentasPorPagar::resumen($empresaId, $sucursalId),
            'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre']),
            'proveedores' => Proveedor::query()->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
