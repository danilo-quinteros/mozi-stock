<?php

namespace App\Livewire\Compras;

use App\Domain\Compras\Actions\RegistrarCompra;
use App\Domain\Compras\CalculadoraCompra;
use App\Domain\Inventario\DatosLote;
use App\Domain\Inventario\LibroLotes;
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
use Illuminate\Validation\ValidationException;
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
     * Cada fila lleva un uid: en productos con vencimiento el mismo producto puede ir en varias líneas, una por lote
     * (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 4). En los demás productos, lote y vencimiento se ignoran.
     *
     * @return array{id: int, cantidad: string, costo: string, uid: string, lote: string, vencimiento: string}
     */
    protected function nuevaLinea(int $productoId): array
    {
        return ['id' => $productoId, 'cantidad' => '1', 'costo' => '', 'uid' => 'l'.Str::lower(Str::random(10)), 'lote' => '', 'vencimiento' => ''];
    }

    /**
     * «Otro lote»: agrega debajo una línea del mismo producto con el mismo costo y el lote en blanco.
     */
    public function otroLote(string $uid): void
    {
        $indice = array_search($uid, array_column($this->lineas, 'uid'), true);

        if (! is_int($indice) || ! isset($this->productosConLote()[$this->lineas[$indice]['id']])) {
            return;
        }

        $linea = $this->nuevaLinea($this->lineas[$indice]['id']);
        $linea['costo'] = $this->lineas[$indice]['costo'] ?? '';
        array_splice($this->lineas, $indice + 1, 0, [$linea]);
    }

    /**
     * Productos de la tabla que se compran por lote: marcados y con la capacidad activa (como en el servidor).
     *
     * @return array<int, true>
     */
    private function productosConLote(): array
    {
        return app(LibroLotes::class)->productosConLote((int) $this->actor()->empresa_id, array_column($this->lineas, 'id'));
    }

    /**
     * Al corregir el lote o el vencimiento de una línea se quita su error (y el aviso general, si ya no quedan).
     */
    public function updated(string $propiedad): void
    {
        if (preg_match('/^lineas\.\d+\.(lote|vencimiento)$/', $propiedad) !== 1) {
            return;
        }

        $this->resetErrorBag($propiedad);

        if (! $this->getErrorBag()->hasAny(['lineas.*.lote', 'lineas.*.vencimiento'])) {
            $this->resetErrorBag('lineas');
        }
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
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

        // Lote y vencimiento por línea (§ 5.1). RegistrarCompra lo vuelve a validar en el servidor.
        $conLote = $this->productosConLote();
        $errores = [];

        foreach ($this->lineas as $indice => $linea) {
            if (! isset($conLote[$linea['id']])) {
                continue;
            }

            $numero = DatosLote::numero($linea['lote'] ?? '');

            if ($numero === null) {
                $errores["lineas.{$indice}.lote"] = __('Escribe el número de lote.');
            } elseif (mb_strlen($numero) > DatosLote::MAX_NUMERO) {
                $errores["lineas.{$indice}.lote"] = __('Hasta :maximo caracteres.', ['maximo' => DatosLote::MAX_NUMERO]);
            }

            if (DatosLote::vencimiento($linea['vencimiento'] ?? '') === null) {
                $errores["lineas.{$indice}.vencimiento"] = __('Escribe dd/mm/aaaa o mm/aaaa.');
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores + ['lineas' => __('Completa el lote y el vencimiento de los productos marcados.')]);
        }

        $this->confirmando = true;
    }

    public function confirmar(RegistrarCompra $accion): void
    {
        $this->confirmando = false;
        $lineas = array_map(fn (array $linea) => [
            'producto_id' => $linea['id'], 'cantidad' => $linea['cantidad'], 'costo' => $linea['costo'] ?? '',
            'lote' => $linea['lote'] ?? null, 'vencimiento' => $linea['vencimiento'] ?? null,
        ], $this->lineas);

        $actor = $this->actor();
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
        $conLote = $this->lineas === [] ? [] : $this->productosConLote();
        $filas = [];
        $vencidos = [];
        $unidades = '0';
        $total = '0.00';
        $valido = true;

        foreach ($this->lineas as $indice => $linea) {
            $producto = $datos['productos']->get($linea['id']);

            if ($producto === null) {
                continue;
            }

            $clave = (string) $this->claveLinea($linea);
            $lote = isset($conLote[$producto->id]);
            $vence = $lote ? DatosLote::vencimiento($linea['vencimiento'] ?? '') : null;
            $vencido = DatosLote::vencido($vence);

            if ($vencido) {
                // § 5.1.4: se puede ingresar, pero queda bloqueado para la venta; se avisa antes de confirmar.
                $vencidos[] = $producto->nombre.' · '.__('lote :lote', ['lote' => trim($linea['lote'] ?? '')]).' · '.DatosLote::formatear($vence);
            }

            $cantidad = Decimal::normalizar($linea['cantidad'], Decimal::ESCALA_CANTIDAD);
            $costo = Decimal::normalizar($linea['costo'] ?? '', Decimal::ESCALA_DINERO);
            $subtotal = $cantidad === null || $costo === null || Decimal::comparar($cantidad, '0') <= 0 || Decimal::comparar($costo, '0', 2) < 0
                ? null : CalculadoraCompra::subtotal($cantidad, $costo);
            $valido = $valido && $subtotal !== null;
            $unidades = $cantidad === null ? $unidades : Decimal::sumar($unidades, $cantidad);
            $total = $subtotal === null ? $total : Decimal::sumar($total, $subtotal, 2);
            $filas[] = compact('indice', 'clave', 'producto', 'cantidad', 'costo', 'subtotal', 'lote', 'vence', 'vencido')
                + ['numeroLote' => trim($linea['lote'] ?? '')];
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
            'vencidos' => $vencidos,
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
