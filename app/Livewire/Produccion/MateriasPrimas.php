<?php

namespace App\Livewire\Produccion;

use App\Domain\Catalogo\Consultas\ListadoProductos;
use App\Domain\Produccion\Consultas\ListadoMateriasPrimas;
use App\Livewire\Concerns\MuestraAvisos;
use App\Livewire\Concerns\UsaSucursalActiva;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Producción → Materias primas (ESPECIFICACION_PRODUCCION.md § 3 y § 4): listado con stock de la sucursal, mínimo,
 * máximo, costo promedio y avisos; accesos a nueva materia prima, ingreso de materia prima (compra filtrada) y
 * sugerencia de compra. Solo el administrador con la capacidad (ruta + gate usar-produccion).
 */
#[Title('Materias primas')]
class MateriasPrimas extends Component
{
    use MuestraAvisos, UsaSucursalActiva, WithPagination;

    #[Url(except: 'todas')]
    public string $filtro = 'todas';

    #[Url(as: 'sucursal', except: '')]
    public string $sucursal = '';

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    public function mount(): void
    {
        $this->authorize('usar-produccion');
        $this->aplicarSucursalActiva();

        if (is_string($mensaje = session('aviso'))) {
            $this->avisar($mensaje);
        }
    }

    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['filtro', 'sucursal', 'buscar'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $actor = $this->actor();
        $empresaId = (int) $actor->empresa_id;
        $sucursal = ListadoProductos::sucursalParaVista($actor, $this->sucursal);
        $filtro = in_array($this->filtro, ListadoMateriasPrimas::FILTROS, true) ? $this->filtro : 'todas';

        return view('livewire.produccion.materias-primas', [
            'materias' => ListadoMateriasPrimas::consulta($empresaId, $sucursal?->id, $this->buscar, $filtro)->paginate(30),
            'resumen' => ListadoMateriasPrimas::resumen($empresaId, $sucursal?->id),
            'sucursalVista' => $sucursal,
            'sucursales' => Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']),
            'filtroActivo' => $filtro,
        ]);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
