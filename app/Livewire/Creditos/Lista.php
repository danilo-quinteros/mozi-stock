<?php

namespace App\Livewire\Creditos;

use App\Domain\Creditos\Consultas\ListadoCreditos;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Ventas a crédito de la empresa. Todo usuario de la empresa las ve para poder cobrar
 * cualquier crédito en su sucursal (decisión del propietario, 23/10/2026).
 */
#[Title('Créditos')]
class Lista extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    #[Url(except: 'todos')]
    public string $estado = 'todos';

    public function mount(): void
    {
        $this->authorize('viewAny', Pedido::class);

        if (! in_array($this->estado, ListadoCreditos::ESTADOS, true)) {
            $this->estado = 'todos';
        }
    }

    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['buscar', 'estado'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = Auth::user();
        $empresaId = (int) $actor->empresa_id;
        // El vendedor ve los créditos de todas las sucursales; el administrador, la fijada en el menú.
        $sucursalId = $actor->esAdministrador() ? $actor->sucursalActivaId() : null;

        return view('livewire.creditos.lista', [
            'creditos' => ListadoCreditos::consulta($empresaId, $this->estado, $sucursalId, $this->buscar)->paginate(25),
            'resumen' => ListadoCreditos::resumen($empresaId, $sucursalId),
        ]);
    }
}
