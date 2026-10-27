<?php

namespace App\Livewire\Pedidos;

use App\Domain\Ventas\Consultas\ListadoPedidos;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Pedidos y ventas')]
class Lista extends Component
{
    use MuestraAvisos, WithPagination;

    #[Url(except: 'todos')]
    public string $pestana = 'todos';

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    #[Url(except: '')]
    public string $estado = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Pedido::class);

        if (! in_array($this->pestana, ListadoPedidos::PESTANAS, true)) {
            $this->pestana = 'todos';
        }

        if (is_string($mensaje = session('aviso'))) {
            $this->avisar($mensaje);
        }
    }

    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['pestana', 'buscar', 'estado'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = Auth::user();
        $base = ListadoPedidos::consulta($actor);
        $conteo = (clone $base)->reorder()->toBase()
            ->selectRaw("sum(case when estado = 'pendiente' then 1 else 0 end) as pendientes")
            ->selectRaw("sum(case when estado = 'vendido' then 1 else 0 end) as vendidos")
            ->selectRaw("sum(case when estado = 'cancelado' then 1 else 0 end) as cancelados")
            ->first();

        return view('livewire.pedidos.lista', [
            'pedidos' => ListadoPedidos::consulta($actor, $this->pestana, $this->buscar, $this->estado)->paginate(25),
            'conteo' => $conteo,
        ]);
    }
}
