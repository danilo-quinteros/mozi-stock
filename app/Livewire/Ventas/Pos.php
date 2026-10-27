<?php

namespace App\Livewire\Ventas;

use App\Domain\Catalogo\Consultas\ListadoProductos;
use App\Domain\Facturacion\DatosFactura;
use App\Domain\Ventas\Actions\VenderEnPos;
use App\Domain\Ventas\CalculadoraPedido;
use App\Domain\Ventas\ValidadorPago;
use App\Enums\MetodoPago;
use App\Enums\ModoFacturacion;
use App\Enums\TipoPrecio;
use App\Livewire\Concerns\CapturaFactura;
use App\Livewire\Concerns\CapturaProductos;
use App\Livewire\Concerns\EligeCliente;
use App\Livewire\Concerns\MuestraAvisos;
use App\Models\Categoria;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Punto de venta (pos.html): catálogo de la sucursal, carrito y cobro en una sola pantalla.
 * Los importes que se muestran salen de la calculadora del servidor; confirmar delega en
 * VenderEnPos, que guarda y confirma con las mismas acciones que Pedidos y ventas.
 */
class Pos extends Component
{
    use CapturaFactura, CapturaProductos, EligeCliente, MuestraAvisos {
        CapturaProductos::agregarProducto as private agregarAlCarrito;
    }

    /** Productos del catálogo por página; «Ver más» suma otra página. */
    public const POR_PAGINA = 24;

    #[Locked]
    public string $clave = '';

    #[Locked]
    public string $claveConfirmacion = '';

    public ?string $sucursal_id = null;

    public ?string $categoria = null;

    #[Locked]
    public int $mostrar = self::POR_PAGINA;

    public string $descuento_global_tipo = 'bs';

    public string $descuento_global_valor = '0';

    public string $pago_metodo = 'efectivo';

    public bool $pago_en_cuotas = false;

    public string $pago_cuota_inicial = '0';

    public string $pago_efectivo = '';

    public string $pago_electronico = '';

    /** Efectivo que entrega el cliente: solo calcula el cambio, no se guarda. */
    public string $recibido = '';

    #[Locked]
    public ?int $ultimaVentaId = null;

    public function mount(): void
    {
        $this->authorize('create', Pedido::class);
        $actor = $this->actor();
        $sucursalId = $actor->sucursalActivaId()
            ?? Sucursal::query()->where('activa', true)->orderBy('nombre')->value('id');
        $this->sucursal_id = $sucursalId === null ? null : (string) $sucursalId;
        $this->nuevasClaves();
        $this->prepararFactura();
    }

    /**
     * El vendedor vende siempre en su sucursal asignada; el administrador, en una activa de su empresa.
     * VenderEnPos lo vuelve a resolver en el servidor; esto solo evita mostrar stock de otra sucursal.
     */
    public function updatedSucursalId(): void
    {
        $actor = $this->actor();
        $valida = $actor->esAdministrador() && $this->sucursal_id !== null && ctype_digit($this->sucursal_id)
            && Sucursal::query()->where('activa', true)->whereKey((int) $this->sucursal_id)->exists();

        if (! $valida) {
            $sucursalId = $actor->esVendedor() ? $actor->sucursal_id
                : Sucursal::query()->where('activa', true)->orderBy('nombre')->value('id');
            $this->sucursal_id = $sucursalId === null ? null : (string) $sucursalId;
        }
    }

    public function updatedPagoCuotaInicial(): void
    {
        $this->sugerirFactura(soloMetodo: true);
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
     * Agrega una unidad si la sucursal tiene stock para ella. ConfirmarVenta lo vuelve a validar con bloqueo.
     */
    public function agregarProducto(int $id): bool
    {
        $enCarrito = collect($this->lineas)->firstWhere('id', $id)['cantidad'] ?? '0';
        $deseada = Decimal::sumar(Decimal::normalizar((string) $enCarrito, Decimal::ESCALA_CANTIDAD) ?? '0', '1');

        if (Decimal::comparar($this->stockDe($id), $deseada) < 0) {
            $nombre = Producto::query()->whereKey($id)->value('nombre');
            $this->mensajeCaptura = $nombre === null ? __('El producto no está disponible.')
                : __('No hay más existencias de :producto en esta sucursal.', ['producto' => $nombre]);

            return false;
        }

        $agregado = $this->agregarAlCarrito($id);
        if ($agregado) {
            $nombre = Producto::query()->whereKey($id)->value('nombre') ?? __('Producto');
            $this->avisar(__(':producto agregado al carrito.', ['producto' => $nombre]), 'success');
        }

        return $agregado;
    }

    public function sumar(int $indice, int $paso): void
    {
        if (! isset($this->lineas[$indice]) || ! in_array($paso, [-1, 1], true)) {
            return;
        }

        $actual = Decimal::normalizar((string) $this->lineas[$indice]['cantidad'], Decimal::ESCALA_CANTIDAD) ?? '0';
        $nueva = Decimal::sumar($actual, (string) $paso);

        if (Decimal::comparar($nueva, '0') <= 0) {
            $this->quitarIndice($indice);

            return;
        }

        if ($paso > 0 && Decimal::comparar($this->stockDe($this->lineas[$indice]['id']), $nueva) < 0) {
            $this->mensajeCaptura = __('Stock insuficiente en esta sucursal.');

            return;
        }

        $this->lineas[$indice]['cantidad'] = Decimal::simple($nueva);
    }

    public function quitarIndice(int $indice): void
    {
        $lineas = $this->lineas;
        unset($lineas[$indice]);
        $this->lineas = array_values($lineas);
    }

    public function filtrarCategoria(?int $id = null): void
    {
        $this->categoria = $id === null ? null : (string) $id;
        $this->mostrar = self::POR_PAGINA;
    }

    public function verMas(): void
    {
        $this->mostrar += self::POR_PAGINA;
    }

    public function updatedBusqueda(): void
    {
        $this->mostrar = self::POR_PAGINA;
    }

    public function elegirMetodo(string $metodo): void
    {
        if (MetodoPago::tryFrom($metodo) !== null) {
            $this->pago_metodo = $metodo;
            $this->resetValidation(['pago_metodo', 'pago_efectivo', 'recibido']);
            $this->sugerirFactura(soloMetodo: true);
        }
    }

    public function alternarCredito(): void
    {
        $this->pago_en_cuotas = ! $this->pago_en_cuotas;
        $this->pago_cuota_inicial = '0';
        $this->reset('pago_efectivo', 'pago_electronico', 'recibido');
        $this->resetValidation();
        $this->sugerirFactura(soloMetodo: true);
    }

    public function alternarFactura(): void
    {
        $this->emitirFactura = ! $this->emitirFactura;
        $this->resetValidation();

        if ($this->emitirFactura) {
            $this->sugerirFactura();
        }
    }

    public function vaciar(): void
    {
        $this->reiniciarCaptura();
        $this->reset('descuento_global_tipo', 'descuento_global_valor', 'pago_en_cuotas', 'pago_cuota_inicial',
            'pago_efectivo', 'pago_electronico', 'recibido');
        $this->resetValidation();
    }

    public function confirmar(VenderEnPos $accion): void
    {
        $cobro = $this->resumen()['pago'];

        if ($cobro !== null && $cobro['metodo'] === MetodoPago::Efectivo && ($faltante = $this->faltante($cobro['monto'])) !== null) {
            throw ValidationException::withMessages(['recibido' => __('Faltan :importe para cubrir el cobro.', ['importe' => Decimal::bs($faltante)])]);
        }

        $venta = $accion->ejecutar($this->actor(), [
            'sucursal_id' => $this->sucursal_id === null ? null : (int) $this->sucursal_id,
            'cliente_id' => $this->cliente_id,
            'lineas' => array_map(fn (array $l) => [
                'producto_id' => $l['id'],
                'cantidad' => $l['cantidad'],
                'tipo_precio' => $l['tipo_precio'] ?? TipoPrecio::Consumidor->value,
                'descuento_tipo' => 'bs',
                'descuento_valor' => '0',
            ], $this->lineas),
            'descuento_global_tipo' => $this->descuento_global_tipo,
            'descuento_global_valor' => $this->descuento_global_valor,
            'clave' => $this->clave,
        ], [
            'metodo' => $this->pago_metodo,
            'en_cuotas' => $this->pago_en_cuotas,
            'cuota_inicial' => $this->pago_cuota_inicial,
            'efectivo' => $this->pago_efectivo,
            'electronico' => $this->pago_electronico,
            'clave' => $this->claveConfirmacion,
        ], $this->datosFactura());

        // Venta lista: el carrito queda vacío y con claves nuevas para la siguiente.
        $this->ultimaVentaId = $venta->id;
        $this->vaciar();
        $this->cliente_id = null;
        $this->nuevasClaves();
        $this->prepararFactura();
        $this->avisar(__('Venta :codigo confirmada.', ['codigo' => $venta->codigo()]));
    }

    public function render(): View
    {
        $actor = $this->actor();
        $sucursalId = $this->sucursal_id === null ? null : (int) $this->sucursal_id;
        $resumen = $this->resumen();
        $cobro = $resumen['pago'];
        $errores = $resumen['errores'];
        $saldo = $resumen['calculo'] !== null && $cobro !== null ? Decimal::restar($resumen['calculo']['total'], $cobro['monto'], 2) : null;

        if ($saldo !== null && Decimal::comparar($saldo, '0', 2) > 0 && $this->cliente_id === null) {
            $errores[] = __('Para vender a crédito identifica al cliente.');
        }

        if ($cobro !== null && $cobro['metodo'] === MetodoPago::Efectivo && ($faltante = $this->faltante($cobro['monto'])) !== null) {
            $errores[] = __('Faltan :importe para cubrir el cobro.', ['importe' => Decimal::bs($faltante)]);
        }

        $busqueda = trim($this->busqueda);
        $consulta = ListadoProductos::consulta((int) $actor->empresa_id, $sucursalId, $busqueda,
            $this->categoria === null ? null : (int) $this->categoria)->where('productos.activo', true);
        $total = (clone $consulta)->count();
        $productos = $consulta->limit($this->mostrar)->get();
        $modoFiscal = $this->configuracionFiscal()?->modo;
        $facturaActiva = $modoFiscal === ModoFacturacion::Automatica || ($modoFiscal !== null && $this->emitirFactura);

        return view('livewire.ventas.pos', [
            'productos' => $productos,
            'totalProductos' => $total,
            'categorias' => Categoria::query()->where('activa', true)
                ->whereIn('id', Producto::query()->where('activo', true)->whereNotNull('categoria_id')->select('categoria_id'))
                ->orderBy('nombre')->get(['id', 'nombre']),
            'filas' => $resumen['filas'],
            'calculo' => $resumen['calculo'],
            'pago' => $cobro,
            'saldo' => $saldo,
            'cambio' => $cobro !== null && $cobro['metodo'] === MetodoPago::Efectivo ? $this->cambio($cobro['monto']) : null,
            'erroresVivos' => array_values(array_unique($errores)),
            'administra' => $actor->esAdministrador(),
            'sucursales' => $actor->esAdministrador()
                ? Sucursal::query()->where('activa', true)->orderBy('nombre')->get(['id', 'nombre'])
                : Sucursal::query()->whereKey($actor->sucursal_id)->get(['id', 'nombre']),
            'cliente' => $this->clienteActual(),
            'clientesEncontrados' => $this->clientesEncontrados(),
            'modoFiscal' => $modoFiscal,
            'fiscal' => $facturaActiva ? $this->contextoFiscal($resumen['calculo']['total'] ?? '0') : null,
            'ultimaVenta' => $this->ultimaVentaId === null ? null : Venta::query()->find($this->ultimaVentaId),
        ])->title(__('Punto de venta'));
    }

    /**
     * Filas del carrito, cálculo y cobro con la misma calculadora y validador que la confirmación.
     *
     * @return array{filas: list<array<string, mixed>>, calculo: array<string, mixed>|null, pago: array{metodo: MetodoPago|null, monto: string, efectivo: string, electronico: string}|null, errores: list<string|array<mixed>>}
     */
    private function resumen(): array
    {
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
            $precio = $producto->{$tipo->columna()};
            $cantidad = Decimal::normalizar((string) $linea['cantidad'], Decimal::ESCALA_CANTIDAD);
            $stock = $datos['stock'][$producto->id] ?? '0';

            if ($precio === null) {
                $errores[] = __(':producto no tiene precio :lista.', ['producto' => $producto->nombre, 'lista' => mb_strtolower($tipo->etiqueta())]);
            } elseif ($cantidad === null || Decimal::comparar($cantidad, '0') <= 0) {
                $errores[] = __(':producto: revisa la cantidad.', ['producto' => $producto->nombre]);
            } else {
                $lineasCalculo[$indice] = ['cantidad' => $cantidad, 'precio_unitario' => (string) $precio,
                    'descuento_tipo' => 'bs', 'descuento_valor' => '0'];

                if (Decimal::comparar($stock, $cantidad) < 0) {
                    $errores[] = __(':producto: stock insuficiente en esta sucursal.', ['producto' => $producto->nombre]);
                }
            }

            $filas[] = compact('indice', 'producto', 'precio', 'stock', 'cantidad', 'tipo');
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
                $errores = array_values(array_merge($errores, $e->validator->errors()->all()));
            }
        }

        return ['filas' => $filas, 'calculo' => $calculo, 'pago' => $pago, 'errores' => $errores];
    }

    /** Importe que falta cuando el efectivo recibido no cubre el cobro; null si alcanza o no se indicó. */
    private function faltante(string $cobro): ?string
    {
        $recibido = blank($this->recibido) ? null : Decimal::normalizar($this->recibido, Decimal::ESCALA_DINERO);

        return $recibido !== null && Decimal::comparar($recibido, $cobro, 2) < 0
            ? Decimal::restar($cobro, $recibido, Decimal::ESCALA_DINERO) : null;
    }

    private function cambio(string $cobro): ?string
    {
        $recibido = blank($this->recibido) ? null : Decimal::normalizar($this->recibido, Decimal::ESCALA_DINERO);

        return $recibido === null || Decimal::comparar($recibido, $cobro, 2) < 0
            ? null : Decimal::restar($recibido, $cobro, Decimal::ESCALA_DINERO);
    }

    private function stockDe(int $productoId): string
    {
        if ($this->sucursal_id === null) {
            return '0';
        }

        return (string) (DB::table('stock_niveles')
            ->where('empresa_id', $this->actor()->empresa_id)
            ->where('sucursal_id', (int) $this->sucursal_id)
            ->where('producto_id', $productoId)
            ->value('cantidad') ?? '0');
    }

    protected function clienteElegido(): void
    {
        $this->sugerirFactura();
    }

    /** Cada venta empieza sin factura en modo manual y con factura en modo automático. */
    private function prepararFactura(): void
    {
        $this->emitirFactura = $this->configuracionFiscal()?->modo === ModoFacturacion::Automatica;
        $this->sugerirFactura();
    }

    /**
     * Propone los datos fiscales (cliente y método de pago SIN) mientras la factura está activa.
     * Con $soloMetodo conserva lo que el vendedor ya escribió del comprador.
     */
    private function sugerirFactura(bool $soloMetodo = false): void
    {
        $configuracion = $this->configuracionFiscal();

        if ($configuracion === null || ! $this->emitirFactura) {
            return;
        }

        // Crédito sin cuota inicial: no hay cobro ahora («pago posterior»).
        $inicial = Decimal::normalizar($this->pago_cuota_inicial, Decimal::ESCALA_DINERO) ?? '0';
        $sinCobro = $this->pago_en_cuotas && Decimal::comparar($inicial, '0', 2) === 0;
        $metodo = DatosFactura::metodoSugerido((int) $this->actor()->empresa_id,
            $sinCobro ? null : MetodoPago::tryFrom($this->pago_metodo), $this->pago_en_cuotas, $configuracion->metodo_pago_electronico);

        if ($soloMetodo) {
            $this->factura['metodo_pago'] = $metodo === null ? '' : (string) $metodo;

            return;
        }

        $this->factura = DatosFactura::sugeridos($this->clienteActual(), $metodo);
    }

    private function nuevasClaves(): void
    {
        $this->clave = (string) Str::uuid();
        $this->claveConfirmacion = (string) Str::uuid();
    }

    private function actor(): User
    {
        /** @var User */
        return Auth::user();
    }
}
