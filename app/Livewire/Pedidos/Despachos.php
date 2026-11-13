<?php

namespace App\Livewire\Pedidos;

use App\Domain\Despachos\Consultas\ListadoDespachos;
use App\Enums\Capacidad;
use App\Enums\EstadoEntrega;
use App\Livewire\Concerns\ExigeCapacidad;
use App\Livewire\Concerns\GestionaEntregas;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Pestaña «Despachos» de Pedidos y ventas (ESPECIFICACION_DESPACHOS.md § 4): ventas por estado de entrega
 * con filtros de sucursal, cliente y fecha, y las acciones de cada fila según el permiso del usuario.
 * El vendedor ve solo su sucursal asignada; la consulta lo fuerza aunque la petición pida otra.
 */
class Despachos extends Component
{
    use ExigeCapacidad, GestionaEntregas, MuestraAvisos, WithPagination;

    #[Url(as: 'entrega', except: 'por_despachar')]
    public string $estado = 'por_despachar';

    #[Url(as: 'suc', except: '')]
    public string $sucursal = '';

    #[Url(as: 'cliente', except: '')]
    public string $cliente = '';

    #[Url(except: '')]
    public string $desde = '';

    #[Url(except: '')]
    public string $hasta = '';

    #[Locked]
    public bool $sucursalFija = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Pedido::class);
        $this->exigirCapacidad(Capacidad::Despachos);

        if (! in_array(EstadoEntrega::tryFrom($this->estado), EstadoEntrega::delFlujo(Capacidad::Despachos), true)) {
            $this->estado = EstadoEntrega::PorDespachar->value;
        }

        $actor = $this->actor();
        $activa = $actor->esAdministrador() ? $actor->sucursalActivaId() : null;

        if ($activa !== null) {
            $this->sucursal = (string) $activa;
            $this->sucursalFija = true;
        }
    }

    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['estado', 'sucursal', 'cliente', 'desde', 'hasta'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $this->exigirCapacidad(Capacidad::Despachos);
        $actor = $this->actor();
        $filtros = $this->filtros($actor);

        return view('livewire.pedidos.despachos', [
            'ventas' => ListadoDespachos::consulta($actor, ['estado' => $this->estado] + $filtros)
                ->with(['despachador:id,name', 'entregador:id,name'])
                ->withCount('items')
                ->paginate(25),
            'conteos' => ListadoDespachos::conteos($actor, $filtros),
            'sucursales' => $actor->esAdministrador() ? Sucursal::query()->orderBy('nombre')->get(['id', 'nombre']) : collect(),
            'administra' => $actor->esAdministrador(),
            'dialogo' => $this->dialogoEntrega(),
            'exportar' => array_filter([
                'entrega' => $this->estado,
                'suc' => $actor->esAdministrador() ? $this->sucursal : '',
                'cliente' => trim($this->cliente),
                'desde' => $filtros['desde'] ?? '',
                'hasta' => $filtros['hasta'] ?? '',
            ], fn (string $valor) => $valor !== ''),
        ]);
    }

    /**
     * @return array{sucursal_id: int|null, cliente: string, desde?: string, hasta?: string}
     */
    private function filtros(User $actor): array
    {
        $fecha = fn (string $valor) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1 ? $valor : null;

        return array_filter([
            // El vendedor queda en su sucursal dentro de la consulta; este valor solo lo usa el administrador.
            'sucursal_id' => $actor->esAdministrador() && ctype_digit($this->sucursal) ? (int) $this->sucursal : null,
            'cliente' => $this->cliente,
            'desde' => $fecha($this->desde),
            'hasta' => $fecha($this->hasta),
        ], fn ($valor, string $clave) => $clave === 'sucursal_id' || $valor !== null, ARRAY_FILTER_USE_BOTH);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
