<?php

namespace App\Livewire\Cocina;

use App\Domain\Despachos\Consultas\PedidosEnCocina;
use App\Livewire\Concerns\GestionaEntregas;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Panel lateral «En cocina» (ESPECIFICACION_COCINA.md § 4.3): pedidos de la sucursal del más antiguo al más
 * nuevo, con el tiempo en cocina y la alerta de demora. «Entregar» es un solo clic con el aviso «Deshacer»
 * de 10 segundos (GestionaEntregas, igual que el detalle de venta); «Ver» muestra el pedido completo sin
 * salir del panel. Mientras está abierto se actualiza cada 5 segundos (wire:poll.visible).
 *
 * Nada se edita aquí: las acciones autorizan, bloquean la venta y comprueban su estado. El vendedor ve solo
 * su sucursal aunque pida otra; el administrador elige sucursal, empezando por la del menú lateral.
 */
class Panel extends Component
{
    use GestionaEntregas, MuestraAvisos;

    public bool $abierto = false;

    /** Filtro del administrador: id de sucursal o '' para todas. Se valida en cada render. */
    public string $sucursal = '';

    /** Pedido abierto con «Ver». */
    #[Locked]
    public ?int $verVentaId = null;

    #[On('abrir-panel-cocina')]
    public function abrir(): void
    {
        $actor = $this->actor();
        $this->verVentaId = null;
        $this->abierto = app(PedidosEnCocina::class)->visible($actor);
        $this->sucursal = $actor->esAdministrador() ? (string) ($actor->sucursalActivaId() ?? '') : '';
    }

    public function cerrar(): void
    {
        $this->abierto = false;
        $this->verVentaId = null;
    }

    public function ver(int $ventaId): void
    {
        $venta = Venta::query()->findOrFail($ventaId);
        $this->authorize('view', $venta);
        $this->verVentaId = $venta->id;
    }

    public function volver(): void
    {
        $this->verVentaId = null;
    }

    public function updatedSucursal(): void
    {
        $this->verVentaId = null;
    }

    /** Tras entregar o deshacer: vuelve a la lista y el botón recuenta sin esperar su actualización. */
    protected function entregaActualizada(): void
    {
        $this->verVentaId = null;
        $this->dispatch('cocina-actualizada');
    }

    public function render(): View
    {
        $actor = $this->actor();
        $datos = ['deshacer' => $this->avisoDeshacer(), 'abierto' => false];

        // Si el vendedor cerró su turno o la empresa apagó cocina, el panel se cierra solo.
        if (! $this->abierto || ! app(PedidosEnCocina::class)->visible($actor)) {
            $this->cerrar();

            return view('livewire.cocina.panel', $datos);
        }

        $sucursalId = PedidosEnCocina::sucursalDe($actor, ctype_digit($this->sucursal) ? (int) $this->sucursal : null);
        $this->sucursal = $actor->esAdministrador() ? (string) ($sucursalId ?? '') : '';
        $minutos = (int) Empresa::query()->whereKey($actor->empresa_id)->value('minutos_alerta_cocina');
        $ahora = now();
        $detalle = $this->detalle($actor);

        return view('livewire.cocina.panel', [
            ...$datos,
            'abierto' => true,
            'ahora' => $ahora,
            'minutos' => $minutos,
            'conteo' => PedidosEnCocina::conteo($actor, $sucursalId, $minutos, $ahora),
            'pedidos' => $detalle === null ? PedidosEnCocina::lista($actor, $sucursalId) : new Collection,
            'detalle' => $detalle,
            'sucursales' => $actor->esAdministrador()
                ? Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre'])
                : new Collection,
            'todasLasSucursales' => $actor->esAdministrador() && $sucursalId === null,
        ]);
    }

    /**
     * Pedido abierto con «Ver»: todos los productos pendientes, la nota, el vendedor y el pago informativo.
     * Si ya no se puede ver (otra sucursal, otra empresa), se vuelve a la lista.
     */
    private function detalle(User $actor): ?Venta
    {
        if ($this->verVentaId === null) {
            return null;
        }

        $venta = Venta::query()
            ->with(['items:id,venta_id,orden,descripcion,unidad,cantidad', 'pedido:id,nota', 'vendedor:id,name', 'sucursal:id,nombre', 'entregador:id,name'])
            ->find($this->verVentaId);

        if ($venta === null || Gate::forUser($actor)->denies('view', $venta)) {
            $this->verVentaId = null;

            return null;
        }

        PedidosEnCocina::cargarLineas(new Collection([$venta]), (int) $actor->empresa_id);

        return $venta;
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
