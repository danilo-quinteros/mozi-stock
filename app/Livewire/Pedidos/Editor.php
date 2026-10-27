<?php

namespace App\Livewire\Pedidos;

use App\Domain\Facturacion\DatosFactura;
use App\Domain\Ventas\Actions\CancelarPedido;
use App\Domain\Ventas\Actions\ConfirmarVenta;
use App\Domain\Ventas\Actions\GuardarPedido;
use App\Domain\Ventas\CalculadoraPedido;
use App\Domain\Ventas\ValidadorPago;
use App\Enums\EstadoPedido;
use App\Enums\ModoFacturacion;
use App\Enums\Rol;
use App\Enums\TipoPrecio;
use App\Livewire\Concerns\CapturaFactura;
use App\Livewire\Concerns\CapturaProductos;
use App\Livewire\Concerns\EligeCliente;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Pantalla única para cotizar y confirmar ventas (pedidos.html). Los importes que se
 * muestran salen de la misma calculadora del servidor; guardar y confirmar delegan en
 * las acciones de dominio, que vuelven a validar todo.
 */
class Editor extends Component
{
    use CapturaFactura, CapturaProductos, EligeCliente, MuestraAvisos;

    #[Locked]
    public ?int $pedidoId = null;

    #[Locked]
    public string $clave = '';

    #[Locked]
    public string $claveConfirmacion = '';

    public ?string $sucursal_id = null;

    public ?string $vendedor_id = null;

    public string $descuento_global_tipo = 'bs';

    public string $descuento_global_valor = '0';

    public string $nota = '';

    public string $pago_metodo = 'efectivo';

    public bool $pago_en_cuotas = false;

    public string $pago_cuota_inicial = '0';

    public string $pago_efectivo = '';

    public string $pago_electronico = '';

    public bool $confirmando = false;

    public bool $confirmandoCancelacion = false;

    /**
     * Precios con que ya se cotizó cada producto y lista ("producto:lista" => precio).
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $cotizados = [];

    public function mount(?Pedido $pedido = null): void
    {
        $actor = $this->actor();
        $this->clave = (string) Str::uuid();
        $this->claveConfirmacion = (string) Str::uuid();

        if ($pedido === null || ! $pedido->exists) {
            $this->authorize('create', Pedido::class);
            $sucursalId = $actor->sucursalActivaId()
                ?? Sucursal::query()->where('activa', true)->orderBy('nombre')->value('id');
            $this->sucursal_id = $sucursalId === null ? null : (string) $sucursalId;
            $this->vendedor_id = (string) $actor->id;

            if (is_string($mensaje = session('aviso'))) {
                $this->avisar($mensaje);
            }

            return;
        }

        $this->authorize('view', $pedido);

        if ($pedido->estado === EstadoPedido::Vendido && $pedido->venta !== null) {
            $this->redirectRoute('ventas.ver', $pedido->venta, navigate: false);

            return;
        }

        $this->cargar($pedido);

        if (is_string($mensaje = session('aviso'))) {
            $this->avisar($mensaje);
        }
    }

    /**
     * @return array{id: int, cantidad: string, tipo_precio?: string, descuento_tipo?: string, descuento_valor?: string}
     */
    protected function nuevaLinea(int $productoId): array
    {
        return ['id' => $productoId, 'cantidad' => '1', 'tipo_precio' => TipoPrecio::Consumidor->value,
            'descuento_tipo' => 'bs', 'descuento_valor' => '0'];
    }

    /**
     * Quita una línea concreta: el mismo producto puede ir en dos listas de precio.
     */
    public function quitarIndice(int $indice): void
    {
        $lineas = $this->lineas;
        unset($lineas[$indice]);
        $this->lineas = array_values($lineas);
    }

    public function elegirMetodo(string $metodo): void
    {
        if (in_array($metodo, ['efectivo', 'electronico', 'mixto'], true)) {
            $this->pago_metodo = $metodo;
        }
    }

    public function guardar(GuardarPedido $accion): void
    {
        $nuevo = $this->pedidoId === null;
        $pedido = $this->guardarPedido($accion);

        if ($nuevo) {
            session()->flash('aviso', __('Cotización :codigo guardada. No afecta inventario ni caja.', ['codigo' => $pedido->codigo()]));
            $this->redirectRoute('pedidos.editar', $pedido, navigate: false);

            return;
        }

        $this->avisar(__('Cotización :codigo actualizada.', ['codigo' => $pedido->codigo()]));
    }

    public function imprimir(GuardarPedido $accion): void
    {
        $pedido = $this->guardarPedido($accion);
        $this->redirectRoute('pedidos.imprimir', $pedido, navigate: false);
    }

    /**
     * Guarda el pedido y revisa el pago antes de abrir la confirmación.
     */
    public function revisar(GuardarPedido $accion): void
    {
        $pedido = $this->guardarPedido($accion);
        $cobro = ValidadorPago::inicial($pedido->total, $this->pago_metodo, $this->pago_en_cuotas,
            $this->pago_cuota_inicial, $this->pago_efectivo, $this->pago_electronico);

        $conSaldo = Decimal::comparar(Decimal::restar($pedido->total, $cobro['monto'], 2), '0', 2) > 0;

        if ($conSaldo && $pedido->cliente_id === null) {
            throw ValidationException::withMessages(['cliente_id' => __('Para vender a crédito identifica al cliente.')]);
        }

        if (($configuracion = $this->configuracionFiscal()) !== null) {
            // Propuesta de datos fiscales: el vendedor la revisa en la confirmación.
            $metodo = DatosFactura::metodoSugerido($pedido->empresa_id, $cobro['metodo'], $conSaldo, $configuracion->metodo_pago_electronico);
            $this->factura = DatosFactura::sugeridos($pedido->cliente_id === null ? null : Cliente::query()->find($pedido->cliente_id), $metodo);
            $this->emitirFactura = $configuracion->modo === ModoFacturacion::Automatica;
            $this->resetValidation();
        }

        $this->confirmando = true;
    }

    public function confirmar(ConfirmarVenta $accion): void
    {
        // Los datos fiscales se validan con el modal abierto, para que el vendedor vea el error en su campo.
        $datosFactura = $this->datosFactura();
        $this->confirmando = false;
        $venta = $accion->ejecutar($this->actor(), Pedido::query()->findOrFail($this->pedidoId), [
            'metodo' => $this->pago_metodo,
            'en_cuotas' => $this->pago_en_cuotas,
            'cuota_inicial' => $this->pago_cuota_inicial,
            'efectivo' => $this->pago_efectivo,
            'electronico' => $this->pago_electronico,
            'clave' => $this->claveConfirmacion,
        ], $datosFactura);

        session()->flash('aviso', __('Venta :codigo confirmada.', ['codigo' => $venta->codigo()]));
        $this->redirectRoute('ventas.ver', $venta, navigate: false);
    }

    public function cancelarPedido(CancelarPedido $accion): void
    {
        $pedido = $accion->ejecutar($this->actor(), Pedido::query()->findOrFail($this->pedidoId));
        session()->flash('aviso', __('Pedido :codigo cancelado. No se movió stock ni dinero.', ['codigo' => $pedido->codigo()]));
        $this->redirectRoute('pedidos.index', navigate: false);
    }

    public function render(): View
    {
        $actor = $this->actor();
        $pedido = $this->pedidoId === null ? null : Pedido::query()->with('cliente')->find($this->pedidoId);
        $sucursalId = $this->sucursal_id === null ? null : (int) $this->sucursal_id;
        $datos = $this->datosDeLineas($sucursalId);
        $filas = [];
        $lineasCalculo = [];
        $errores = [];

        foreach ($this->lineas as $indice => $linea) {
            $producto = $datos['productos']->get($linea['id']);

            if ($producto === null) {
                continue;
            }

            $tipo = TipoPrecio::tryFrom((string) ($linea['tipo_precio'] ?? '')) ?? TipoPrecio::Consumidor;
            $precio = $this->cotizados[$producto->id.':'.$tipo->value] ?? $producto->{$tipo->columna()};
            $cantidad = Decimal::normalizar((string) $linea['cantidad'], Decimal::ESCALA_CANTIDAD);
            $valorDescuento = trim((string) ($linea['descuento_valor'] ?? ''));
            $descuento = Decimal::normalizar($valorDescuento === '' ? '0' : $valorDescuento, Decimal::ESCALA_DINERO);

            if ($precio === null) {
                $errores[] = __(':producto no tiene precio :lista.', ['producto' => $producto->nombre, 'lista' => mb_strtolower($tipo->etiqueta())]);
            } elseif ($cantidad === null || Decimal::comparar($cantidad, '0') <= 0) {
                $errores[] = __(':producto: revisa la cantidad.', ['producto' => $producto->nombre]);
            } elseif ($descuento === null) {
                $errores[] = __(':producto: revisa el descuento.', ['producto' => $producto->nombre]);
            } else {
                $lineasCalculo[$indice] = ['cantidad' => $cantidad, 'precio_unitario' => (string) $precio,
                    'descuento_tipo' => (string) ($linea['descuento_tipo'] ?? 'bs'), 'descuento_valor' => $descuento];
            }

            $stock = $datos['stock'][$producto->id] ?? '0';
            $filas[] = compact('indice', 'producto', 'precio', 'stock', 'cantidad') + ['tipo' => $tipo];
        }

        $calculo = null;
        $pago = null;

        if ($errores === [] && $lineasCalculo !== []) {
            try {
                $global = Decimal::normalizar(blank($this->descuento_global_valor) ? '0' : $this->descuento_global_valor, 2)
                    ?? throw ValidationException::withMessages(['descuento_global_valor' => __('Revisa el descuento global.')]);
                $calculo = CalculadoraPedido::calcular(array_values($lineasCalculo), $this->descuento_global_tipo, $global);
                $calculo['por_indice'] = array_combine(array_keys($lineasCalculo), $calculo['lineas']);
                $pago = ValidadorPago::inicial($calculo['total'], $this->pago_metodo, $this->pago_en_cuotas,
                    $this->pago_cuota_inicial, $this->pago_efectivo, $this->pago_electronico);
            } catch (ValidationException $e) {
                $errores = array_merge($errores, $e->validator->errors()->all());
            }
        }

        return view('livewire.pedidos.editor', [
            'pedido' => $pedido,
            'soloLectura' => $pedido !== null && ! $pedido->estaPendiente(),
            'filas' => $filas,
            'calculo' => $calculo,
            'pago' => $pago,
            'erroresVivos' => $errores,
            'administra' => $actor->esAdministrador(),
            'sucursales' => $actor->esAdministrador()
                ? Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre'])
                : Sucursal::query()->whereKey($actor->sucursal_id)->get(['id', 'nombre']),
            'vendedores' => $actor->esAdministrador() ? User::query()
                ->where('empresa_id', $actor->empresa_id)->where('activo', true)
                ->where(fn (Builder $q) => $q->where('rol', Rol::Administrador)
                    ->orWhere(fn (Builder $q) => $q->where('rol', Rol::Vendedor)->where('sucursal_id', $sucursalId)))
                ->orderBy('name')->get(['id', 'name']) : collect(),
            'cliente' => $this->clienteActual(),
            'clientesEncontrados' => $this->clientesEncontrados(),
            'sugerencias' => $this->sugerencias(),
            'fiscal' => $this->confirmando && $calculo !== null ? $this->contextoFiscal($calculo['total']) : null,
        ])->title($pedido === null ? __('Nuevo pedido') : $pedido->codigo());
    }

    private function guardarPedido(GuardarPedido $accion): Pedido
    {
        $existente = $this->pedidoId === null ? null : Pedido::query()->findOrFail($this->pedidoId);
        $pedido = $accion->ejecutar($this->actor(), $existente, [
            'sucursal_id' => $this->sucursal_id === null ? null : (int) $this->sucursal_id,
            'cliente_id' => $this->cliente_id,
            'vendedor_id' => blank($this->vendedor_id) ? null : (int) $this->vendedor_id,
            'lineas' => array_map(fn (array $l) => [
                'producto_id' => $l['id'],
                'cantidad' => $l['cantidad'],
                'tipo_precio' => $l['tipo_precio'] ?? TipoPrecio::Consumidor->value,
                'descuento_tipo' => $l['descuento_tipo'] ?? 'bs',
                'descuento_valor' => $l['descuento_valor'] ?? '0',
            ], $this->lineas),
            'descuento_global_tipo' => $this->descuento_global_tipo,
            'descuento_global_valor' => $this->descuento_global_valor,
            'nota' => $this->nota,
            'pago_metodo' => $this->pago_metodo,
            'pago_en_cuotas' => $this->pago_en_cuotas,
            'pago_cuota_inicial' => $this->pago_cuota_inicial,
            'pago_efectivo' => $this->pago_efectivo,
            'pago_electronico' => $this->pago_electronico,
            'clave' => $this->clave,
        ]);
        $this->cargar($pedido);

        return $pedido;
    }

    private function cargar(Pedido $pedido): void
    {
        $items = $pedido->items()->get();
        $this->pedidoId = $pedido->id;
        $this->sucursal_id = (string) $pedido->sucursal_id;
        $this->cliente_id = $pedido->cliente_id;
        $this->vendedor_id = (string) $pedido->vendedor_id;
        $this->descuento_global_tipo = $pedido->descuento_global_tipo;
        $this->descuento_global_valor = Decimal::simple($pedido->descuento_global_valor);
        $this->nota = (string) $pedido->nota;
        $this->pago_metodo = $pedido->pago_metodo->value ?? 'efectivo';
        $this->pago_en_cuotas = $pedido->pago_en_cuotas;
        $this->pago_cuota_inicial = $pedido->pago_cuota_inicial === null ? '0' : Decimal::simple($pedido->pago_cuota_inicial);
        $this->pago_efectivo = $pedido->pago_efectivo === null ? '' : Decimal::simple($pedido->pago_efectivo);
        $this->pago_electronico = $pedido->pago_electronico === null ? '' : Decimal::simple($pedido->pago_electronico);
        $this->lineas = array_values($items->map(fn ($item) => [
            'id' => $item->producto_id,
            'cantidad' => Decimal::simple($item->cantidad),
            'tipo_precio' => $item->tipo_precio->value,
            'descuento_tipo' => $item->descuento_tipo,
            'descuento_valor' => Decimal::simple($item->descuento_valor),
        ])->all());
        $this->cotizados = $items->mapWithKeys(fn ($item) => [$item->producto_id.':'.$item->tipo_precio->value => $item->precio_unitario])->all();
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
