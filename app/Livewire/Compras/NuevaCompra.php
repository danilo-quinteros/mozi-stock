<?php

namespace App\Livewire\Compras;

use App\Domain\Compras\Actions\RegistrarCompra;
use App\Domain\Compras\CalculadoraCompra;
use App\Livewire\Concerns\CapturaProductos;
use App\Models\Compra;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Nueva compra pagada (compra-nueva.html V3). Los importes en pantalla son informativos:
 * RegistrarCompra recalcula el total y valida el pago en el servidor.
 */
#[Title('Nueva compra')]
class NuevaCompra extends Component
{
    use CapturaProductos;

    public ?string $proveedor_id = null;

    public ?string $sucursal_id = null;

    public string $referencia = '';

    public string $metodo = 'efectivo';

    public string $efectivo = '';

    public string $electronico = '';

    public bool $confirmando = false;

    /**
     * Clave de idempotencia de esta pantalla: un doble envío no registra dos compras.
     */
    #[Locked]
    public string $clave = '';

    public function mount(): void
    {
        $this->authorize('create', Compra::class);
        $this->clave = (string) Str::uuid();
        $primera = Auth::user()?->sucursalActivaId() ?? Sucursal::query()->where('activa', true)->orderBy('nombre')->value('id');
        $this->sucursal_id = $primera === null ? null : (string) $primera;
    }

    /**
     * @return array{id: int, cantidad: string, costo: string}
     */
    protected function nuevaLinea(int $productoId): array
    {
        return ['id' => $productoId, 'cantidad' => '1', 'costo' => ''];
    }

    public function revisar(): void
    {
        $this->resetValidation();
        $this->validate([
            'sucursal_id' => ['required'],
            'referencia' => ['nullable', 'string', 'max:150'],
            'metodo' => ['required', 'in:efectivo,electronico,mixto'],
            'lineas' => ['required', 'array', 'min:1'],
        ], [
            'lineas.required' => __('Agrega al menos un producto.'),
            'lineas.min' => __('Agrega al menos un producto.'),
        ], ['sucursal_id' => __('sucursal'), 'metodo' => __('medio de pago')]);

        $this->confirmando = true;
    }

    public function confirmar(RegistrarCompra $accion): void
    {
        $this->confirmando = false;
        $lineas = [];

        foreach ($this->lineas as $linea) {
            $lineas[$linea['id']] = ['cantidad' => $linea['cantidad'], 'costo' => $linea['costo'] ?? ''];
        }

        /** @var User $actor */
        $actor = Auth::user();
        $compra = $accion->ejecutar($actor, [
            'sucursal_id' => $this->sucursal_id === null ? null : (int) $this->sucursal_id,
            'proveedor_id' => blank($this->proveedor_id) ? null : (int) $this->proveedor_id,
            'referencia' => $this->referencia,
            'lineas' => $lineas,
            'metodo' => $this->metodo,
            'efectivo' => $this->efectivo,
            'electronico' => $this->electronico,
            'clave' => $this->clave,
        ]);

        session()->flash('aviso', __(':codigo registrada: stock ingresado y pago de :importe en caja.', ['codigo' => $compra->codigo(), 'importe' => Decimal::bs($compra->total)]));
        $this->redirectRoute('compras.ver', $compra, navigate: false);
    }

    public function render(): View
    {
        $datos = $this->datosDeLineas(null);
        $filas = [];
        $unidades = '0';
        $total = '0.00';
        $valido = true;

        foreach ($this->lineas as $indice => $linea) {
            $producto = $datos['productos']->get($linea['id']);

            if ($producto === null) {
                continue;
            }

            $cantidad = Decimal::normalizar($linea['cantidad'], Decimal::ESCALA_CANTIDAD);
            $costo = Decimal::normalizar($linea['costo'] ?? '', Decimal::ESCALA_DINERO);
            $subtotal = $cantidad === null || $costo === null || Decimal::comparar($cantidad, '0') <= 0 || Decimal::comparar($costo, '0', 2) < 0
                ? null : CalculadoraCompra::subtotal($cantidad, $costo);
            $valido = $valido && $subtotal !== null;
            $unidades = $cantidad === null ? $unidades : Decimal::sumar($unidades, $cantidad);
            $total = $subtotal === null ? $total : Decimal::sumar($total, $subtotal, 2);
            $filas[] = compact('indice', 'producto', 'cantidad', 'costo', 'subtotal');
        }

        $mixto = Decimal::sumar(
            Decimal::normalizar($this->efectivo === '' ? '0' : $this->efectivo, 2) ?? '0',
            Decimal::normalizar($this->electronico === '' ? '0' : $this->electronico, 2) ?? '0',
            Decimal::ESCALA_DINERO,
        );

        return view('livewire.compras.nueva-compra', [
            'sucursales' => Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre']),
            'proveedores' => Proveedor::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'filas' => $filas,
            'unidades' => $unidades,
            'total' => $total,
            'valido' => $valido,
            'mixto' => $mixto,
        ]);
    }
}
