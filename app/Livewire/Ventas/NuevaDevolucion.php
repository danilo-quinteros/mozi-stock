<?php

namespace App\Livewire\Ventas;

use App\Domain\Ventas\Actions\RegistrarDevolucion;
use App\Domain\Ventas\CalculadoraDevolucion;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Devolución de una venta, como sección contextual de Ventas (especificación § 3.9). Los
 * importes mostrados son informativos: RegistrarDevolucion los recalcula con la venta
 * bloqueada y valida cantidades y reembolso en el servidor.
 */
class NuevaDevolucion extends Component
{
    #[Locked]
    public int $ventaId;

    #[Locked]
    public string $clave = '';

    /**
     * Por línea de venta: cantidad a devolver y si vuelve al stock.
     *
     * @var array<int, array{cantidad: string, reingresa: bool}>
     */
    public array $lineas = [];

    public string $motivo = '';

    public string $metodo = 'efectivo';

    public string $efectivo = '';

    public string $electronico = '';

    public bool $confirmando = false;

    public function mount(Venta $venta): void
    {
        $this->authorize('devolver', $venta);
        $this->ventaId = $venta->id;
        $this->clave = (string) Str::uuid();

        foreach (VentaItem::query()->where('venta_id', $venta->id)->orderBy('orden')->pluck('id') as $id) {
            $this->lineas[(int) $id] = ['cantidad' => '', 'reingresa' => true];
        }
    }

    public function devolverTodo(): void
    {
        foreach ($this->filas($this->venta()) as $fila) {
            $this->lineas[$fila['item']->id]['cantidad'] = Decimal::simple($fila['disponible']);
        }
    }

    public function revisar(): void
    {
        $this->resetValidation();
        $this->validate(['motivo' => ['required', 'string', 'max:250']], [], ['motivo' => __('motivo')]);
        $this->confirmando = true;
    }

    public function confirmar(RegistrarDevolucion $accion): void
    {
        $this->confirmando = false;

        /** @var User $actor */
        $actor = Auth::user();
        $devolucion = $accion->ejecutar($actor, $this->venta(), [
            'lineas' => $this->lineas,
            'motivo' => $this->motivo,
            'metodo' => $this->metodo,
            'efectivo' => $this->efectivo,
            'electronico' => $this->electronico,
            'clave' => $this->clave,
        ]);

        $datos = ['codigo' => $devolucion->codigo(), 'importe' => Decimal::bs($devolucion->importe), 'reembolso' => Decimal::bs($devolucion->reembolso)];
        session()->flash('aviso', Decimal::comparar($devolucion->reembolso, '0', 2) > 0
            ? __(':codigo registrada: :importe devueltos, reembolso de :reembolso registrado en caja.', $datos)
            : __(':codigo registrada: :importe devueltos, sin salida de dinero.', $datos));
        $this->redirectRoute('ventas.ver', $devolucion->venta_id, navigate: false);
    }

    public function render(): View
    {
        $venta = $this->venta();
        $filas = $this->filas($venta);
        $importe = array_reduce($filas, fn (string $s, array $f) => $f['importe'] === null ? $s : Decimal::sumar($s, $f['importe'], 2), '0.00');
        $deuda = Decimal::comparar($importe, $venta->saldo_pendiente, 2) <= 0 ? $importe : $venta->saldo_pendiente;
        $mixto = Decimal::sumar(
            Decimal::normalizar($this->efectivo === '' ? '0' : $this->efectivo, 2) ?? '0',
            Decimal::normalizar($this->electronico === '' ? '0' : $this->electronico, 2) ?? '0',
            Decimal::ESCALA_DINERO,
        );

        return view('livewire.ventas.nueva-devolucion', [
            'venta' => $venta,
            'filas' => $filas,
            'importe' => $importe,
            'deuda' => $deuda,
            'reembolso' => Decimal::restar($importe, $deuda, Decimal::ESCALA_DINERO),
            'mixto' => $mixto,
            'hayLineas' => collect($filas)->contains(fn (array $f) => $f['cantidad'] !== null && Decimal::comparar($f['cantidad'], '0') > 0),
        ])->title(__('Devolución · :codigo', ['codigo' => $venta->codigo()]));
    }

    /**
     * Cada línea con lo vendido, lo ya devuelto, lo disponible y el importe de la cantidad
     * escrita (null si la cantidad no es válida).
     *
     * @return list<array{item: VentaItem, devueltas: string, disponible: string, cantidad: string|null, importe: string|null}>
     */
    private function filas(Venta $venta): array
    {
        $items = $venta->items->keyBy('id');
        $previas = RegistrarDevolucion::devueltoPorLinea($venta->empresa_id, array_values(array_map('intval', $items->keys()->all())));
        $netos = CalculadoraDevolucion::netosPorLinea($items->map(fn (VentaItem $i) => $i->total)->all(), $venta->descuento_global);
        $filas = [];

        foreach ($items as $item) {
            $devueltas = $previas[$item->id] ?? '0';
            $disponible = Decimal::restar($item->cantidad, $devueltas);
            $texto = trim($this->lineas[$item->id]['cantidad'] ?? '');
            $cantidad = $texto === '' ? '0.000' : Decimal::normalizar($texto, Decimal::ESCALA_CANTIDAD);
            $valida = $cantidad !== null && Decimal::comparar($cantidad, '0') >= 0 && Decimal::comparar($cantidad, $disponible) <= 0;
            $filas[] = [
                'item' => $item,
                'devueltas' => $devueltas,
                'disponible' => $disponible,
                'cantidad' => $valida ? $cantidad : null,
                'importe' => $valida ? CalculadoraDevolucion::importe($netos[$item->id], $item->cantidad, $devueltas, $cantidad) : null,
            ];
        }

        return $filas;
    }

    private function venta(): Venta
    {
        return Venta::query()->with(['items', 'cliente:id,nombre', 'sucursal:id,nombre'])->findOrFail($this->ventaId);
    }
}
