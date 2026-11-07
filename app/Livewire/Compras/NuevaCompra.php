<?php

namespace App\Livewire\Compras;

use App\Domain\Compras\Actions\RegistrarCompra;
use App\Domain\Compras\CalculadoraCompra;
use App\Livewire\Concerns\CapturaProductos;
use App\Livewire\Concerns\EligeCaja;
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
 * Nueva compra (compra-nueva.html V3 y Cuentas por pagar § 13): al contado, a crédito o con
 * pago parcial; con saldo se abre su cuenta por pagar. Los importes en pantalla son
 * informativos: RegistrarCompra recalcula el total y valida el pago en el servidor.
 */
#[Title('Nueva compra')]
class NuevaCompra extends Component
{
    use CapturaProductos, EligeCaja;

    public ?string $proveedor_id = null;

    public ?string $sucursal_id = null;

    public string $referencia = '';

    public string $condicion = 'contado';

    public string $pagado = '';

    public string $vencimiento = '';

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
        $this->elegirCajaPorDefecto();
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
            'proveedor_id' => ['required'],
            'referencia' => ['nullable', 'string', 'max:150'],
            'condicion' => ['required', 'in:contado,credito,parcial'],
            'metodo' => ['required', 'in:efectivo,electronico,mixto'],
            'vencimiento' => [$this->condicion === 'contado' ? 'nullable' : 'required'],
            'lineas' => ['required', 'array', 'min:1'],
        ], [
            'lineas.required' => __('Agrega al menos un producto.'),
            'lineas.min' => __('Agrega al menos un producto.'),
            'proveedor_id.required' => __('Selecciona el proveedor de la compra.'),
            'vencimiento.required' => __('La fecha de vencimiento es obligatoria cuando queda saldo pendiente.'),
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
        $compra = $accion->ejecutar($actor, $this->datosCaja() + [
            'sucursal_id' => $this->sucursal_id === null ? null : (int) $this->sucursal_id,
            'proveedor_id' => blank($this->proveedor_id) ? null : (int) $this->proveedor_id,
            'referencia' => $this->referencia,
            'lineas' => $lineas,
            'condicion' => $this->condicion,
            'pagado' => $this->pagado,
            'vencimiento' => $this->vencimiento === '' ? null : $this->vencimiento,
            'metodo' => $this->metodo,
            'efectivo' => $this->efectivo,
            'electronico' => $this->electronico,
            'clave' => $this->clave,
        ]);

        session()->flash('aviso', $compra->condicion === 'contado'
            ? __(':codigo registrada: stock ingresado y pago de :importe.', ['codigo' => $compra->codigo(), 'importe' => Decimal::bs($compra->total)])
            : __(':codigo registrada: stock ingresado, pagado :pagado y :saldo en cuentas por pagar.', ['codigo' => $compra->codigo(),
                'pagado' => Decimal::bs($compra->pagado), 'saldo' => Decimal::bs(Decimal::restar($compra->total, $compra->pagado, 2))]));
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
            'aPagar' => match ($this->condicion) {
                'contado' => $total,
                'parcial' => Decimal::normalizar($this->pagado, 2) ?? '0.00',
                default => '0.00',
            },
        ]);
    }
}
