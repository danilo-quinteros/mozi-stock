<?php

namespace App\Livewire\Produccion;

use App\Domain\Inventario\DatosLote;
use App\Domain\Inventario\LibroLotes;
use App\Domain\Produccion\Actions\FinalizarOrden;
use App\Domain\Produccion\Escalado;
use App\Domain\Produccion\LineasOrden;
use App\Enums\EstadoOrdenProduccion;
use App\Models\OrdenProduccion;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Registrar lo producido de una orden Aprobada (ESPECIFICACION_PRODUCCION.md § 6.3): cantidad obtenida con su merma o
 * excedente, consumo real precargado con lo descontado al aprobar (editable por línea) y vencimiento del lote del
 * producto (vacío: lo escribe quien finaliza). Toda la regla vive en FinalizarOrden.
 */
#[Title('Registrar lo producido')]
class OrdenFinalizar extends Component
{
    #[Locked]
    public int $ordenId;

    public string $cantidad_obtenida = '';

    /** @var array<int|string, string> producto_id => consumo real */
    public array $consumos = [];

    public string $vencimiento = '';

    public function mount(OrdenProduccion $orden): void
    {
        $this->authorize('usar-produccion');
        abort_unless($orden->estado === EstadoOrdenProduccion::Aprobada, 404);
        $this->ordenId = $orden->id;
        $this->cantidad_obtenida = Decimal::simple((string) $orden->cantidad_planificada);

        foreach (LineasOrden::deOrden((int) $orden->empresa_id, $orden->id) as $linea) {
            $this->consumos[$linea['producto_id']] = Decimal::simple($linea['cantidad_teorica']);
        }
    }

    public function finalizar(FinalizarOrden $accion): void
    {
        $this->resetErrorBag();
        $orden = $accion->ejecutar($this->actor(), $this->orden(), [
            'cantidad_obtenida' => $this->cantidad_obtenida,
            'consumos' => $this->consumos,
            'vencimiento' => $this->vencimiento,
        ]);
        session()->flash('aviso', __('Orden :codigo finalizada: ingresaron :cantidad al stock.', [
            'codigo' => $orden->codigo(), 'cantidad' => Decimal::formatear((string) $orden->cantidad_obtenida),
        ]));
        $this->redirectRoute('produccion.ordenes.ver', ['orden' => $orden->id], navigate: false);
    }

    public function render(): View
    {
        $orden = $this->orden()->load(['producto' => fn ($q) => $q->with('unidad:id,abreviatura'), 'sucursal:id,nombre']);
        $obtenida = Decimal::normalizar($this->cantidad_obtenida, Decimal::ESCALA_CANTIDAD);
        $conLote = $orden->producto->controla_vencimiento && app(LibroLotes::class)->activo((int) $orden->empresa_id);

        return view('livewire.produccion.orden-finalizar', [
            'orden' => $orden,
            'lineas' => LineasOrden::deOrden((int) $orden->empresa_id, $orden->id),
            'variacion' => $obtenida !== null && Decimal::comparar($obtenida, '0') > 0 ? Escalado::variacion((string) $orden->cantidad_planificada, $obtenida) : null,
            'conLote' => $conLote,
            'hoy' => DatosLote::hoy(),
        ])->title(__('Registrar lo producido · :codigo', ['codigo' => $orden->codigo()]));
    }

    private function orden(): OrdenProduccion
    {
        return OrdenProduccion::query()->findOrFail($this->ordenId);
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
