<?php

namespace App\Livewire\Pedidos;

use App\Domain\Facturacion\DeEmpresa;
use App\Domain\Ventas\Consultas\ListadoPedidos;
use App\Enums\Capacidad;
use App\Enums\EstadoFactura;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Pedido;
use App\Models\SiatFactura;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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

        if (! in_array($this->pestana, $this->pestanas(), true)) {
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
        $despachos = Gate::allows('capacidad', Capacidad::Despachos);

        // La pestaña Despachos solo existe con la capacidad; si se apagó con la pestaña abierta, vuelve a Todos.
        if ($this->pestana === 'despachos' && ! $despachos) {
            $this->pestana = 'todos';
        }

        $base = ListadoPedidos::consulta($actor);
        $conteo = (clone $base)->reorder()->toBase()
            ->selectRaw("sum(case when estado = 'pendiente' then 1 else 0 end) as pendientes")
            ->selectRaw("sum(case when estado = 'vendido' then 1 else 0 end) as vendidos")
            ->selectRaw("sum(case when estado = 'cancelado' then 1 else 0 end) as cancelados")
            ->first();

        // En la pestaña Despachos la lista es el componente hijo: no se consulta la de pedidos.
        $pedidos = $this->pestana === 'despachos' ? null
            : ListadoPedidos::consulta($actor, $this->pestana, $this->buscar, $this->estado)->paginate(25);

        return view('livewire.pedidos.lista', [
            'pedidos' => $pedidos,
            'conteo' => $conteo,
            'despachos' => $despachos,
            'facturas' => $pedidos === null ? [] : $this->facturasImprimibles($actor, $pedidos->getCollection()->map(fn (Pedido $pedido): ?int => $pedido->venta?->id)->filter()->values()->all()),
        ]);
    }

    /**
     * Factura vigente y validada por el SIN de cada venta de la página (una consulta), para imprimirla.
     *
     * @param  array<int, int>  $ventaIds
     * @return array<int, int> venta_id => factura_id
     */
    private function facturasImprimibles(User $actor, array $ventaIds): array
    {
        if ($ventaIds === []) {
            return [];
        }

        return DeEmpresa::consulta(SiatFactura::class, (int) $actor->empresa_id)
            ->whereIn('venta_id', $ventaIds)
            ->whereNotNull('venta_vigente')
            ->where('estado', EstadoFactura::Validada->value)
            ->whereNotNull('xml')
            ->pluck('id', 'venta_id')
            ->all();
    }

    /**
     * @return list<string>
     */
    private function pestanas(): array
    {
        return Gate::allows('capacidad', Capacidad::Despachos)
            ? [...ListadoPedidos::PESTANAS, 'despachos']
            : ListadoPedidos::PESTANAS;
    }
}
