<?php

namespace App\Domain\Ventas\Actions;

use App\Domain\Tenancy\Numerador;
use App\Domain\Tenancy\ResolverSucursal;
use App\Domain\Ventas\CalculadoraPedido;
use App\Enums\EstadoPedido;
use App\Enums\MetodoPago;
use App\Enums\Rol;
use App\Enums\TipoPrecio;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Scopes\EmpresaScope;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Crea o actualiza un pedido pendiente (cotización). No toca stock ni dinero.
 * Los precios salen del catálogo, nunca del navegador; una línea que ya existía con el
 * mismo producto y lista conserva el precio con que se cotizó.
 */
final class GuardarPedido
{
    public const MAX_LINEAS = 300;

    public function __construct(
        private readonly ResolverSucursal $resolverSucursal,
        private readonly Numerador $numerador,
    ) {}

    /**
     * @param  array{sucursal_id: int|null, cliente_id: int|null, vendedor_id: int|null, lineas: list<array<string, mixed>>, descuento_global_tipo: string, descuento_global_valor: string|null, nota: string|null, pago_metodo: string|null, pago_en_cuotas: bool, pago_cuota_inicial: string|null, pago_efectivo: string|null, pago_electronico: string|null, clave: string}  $datos
     */
    public function ejecutar(User $actor, ?Pedido $pedido, array $datos): Pedido
    {
        $empresaId = (int) $actor->empresa_id;

        if ($pedido === null) {
            Gate::forUser($actor)->authorize('create', Pedido::class);

            if (! Str::isUuid($datos['clave'])) {
                throw ValidationException::withMessages(['lineas' => __('El pedido no tiene una clave válida. Recarga la página.')]);
            }

            // Un doble envío del mismo formulario nuevo actualiza el pedido ya creado.
            $pedido = Pedido::withoutGlobalScope(EmpresaScope::class)
                ->where('empresa_id', $empresaId)->where('clave_idempotencia', $datos['clave'])->first();
        }

        if ($pedido !== null) {
            Gate::forUser($actor)->authorize('update', $pedido);
        }

        $sucursal = $this->resolverSucursal->paraOperacion($actor, $datos['sucursal_id']);
        $clienteId = $this->cliente($empresaId, $datos['cliente_id']);
        $vendedorId = $this->vendedor($actor, $pedido, $datos['vendedor_id'], $sucursal->id);
        $lineas = $this->lineas($empresaId, $pedido, $datos['lineas']);
        $globalTipo = $datos['descuento_global_tipo'];
        $globalValor = $this->importe($datos['descuento_global_valor'], 'descuento_global_valor') ?? '0.00';
        $calculo = CalculadoraPedido::calcular($lineas, $globalTipo, $globalValor);
        $intencion = $this->intencion($datos);

        try {
            return DB::transaction(function () use ($actor, $empresaId, $pedido, $datos, $sucursal, $clienteId, $vendedorId, $lineas, $globalTipo, $globalValor, $calculo, $intencion): Pedido {
                if ($pedido !== null) {
                    $pedido = Pedido::query()->whereKey($pedido->id)->lockForUpdate()->firstOrFail();

                    if (! $pedido->estaPendiente()) {
                        throw ValidationException::withMessages(['lineas' => __('Este pedido ya fue :estado y no puede modificarse.', ['estado' => mb_strtolower($pedido->estado->etiqueta())])]);
                    }
                } else {
                    $pedido = new Pedido;
                    $pedido->empresa_id = $empresaId;
                    $pedido->numero = $this->numerador->siguiente($empresaId, 'PED');
                    $pedido->registrado_por = $actor->id;
                    $pedido->estado = EstadoPedido::Pendiente;
                    $pedido->clave_idempotencia = $datos['clave'];
                }

                $pedido->sucursal_id = $sucursal->id;
                $pedido->cliente_id = $clienteId;
                $pedido->vendedor_id = $vendedorId;
                $pedido->subtotal = $calculo['subtotal'];
                $pedido->descuento_lineas = $calculo['descuento_lineas'];
                $pedido->descuento_global_tipo = $globalTipo;
                $pedido->descuento_global_valor = $globalValor;
                $pedido->descuento_global = $calculo['descuento_global'];
                $pedido->total = $calculo['total'];
                $nota = trim((string) $datos['nota']);
                $pedido->nota = $nota === '' ? null : mb_substr($nota, 0, 800);
                $pedido->forceFill($intencion);
                $pedido->save();

                DB::table('pedido_items')->where('empresa_id', $empresaId)->where('pedido_id', $pedido->id)->delete();
                $ahora = Carbon::now();
                $filas = [];

                foreach ($lineas as $indice => $linea) {
                    $filas[] = [
                        'empresa_id' => $empresaId,
                        'pedido_id' => $pedido->id,
                        'producto_id' => $linea['producto_id'],
                        'orden' => $indice + 1,
                        'cantidad' => $linea['cantidad'],
                        'tipo_precio' => $linea['tipo_precio'],
                        'precio_unitario' => $linea['precio_unitario'],
                        'descuento_tipo' => $linea['descuento_tipo'],
                        'descuento_valor' => $linea['descuento_valor'],
                        'descuento' => $calculo['lineas'][$indice]['descuento'],
                        'subtotal' => $calculo['lineas'][$indice]['subtotal'],
                        'total' => $calculo['lineas'][$indice]['total'],
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ];
                }

                DB::table('pedido_items')->insert($filas);

                return $pedido;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            return Pedido::withoutGlobalScope(EmpresaScope::class)
                ->where('empresa_id', $empresaId)->where('clave_idempotencia', $datos['clave'])->first() ?? throw $e;
        }
    }

    private function cliente(int $empresaId, ?int $clienteId): ?int
    {
        if ($clienteId === null) {
            return null;
        }

        $existe = DB::table('clientes')->where('empresa_id', $empresaId)->where('id', $clienteId)->where('activo', true)->exists();

        return $existe ? $clienteId : throw ValidationException::withMessages(['cliente_id' => __('Selecciona un cliente de tu empresa.')]);
    }

    /**
     * El vendedor queda como responsable de sus pedidos; el administrador puede asignar
     * a otro administrador o a un vendedor activo de la sucursal del pedido.
     */
    private function vendedor(User $actor, ?Pedido $pedido, ?int $solicitado, int $sucursalId): int
    {
        if ($actor->esVendedor()) {
            return $pedido->vendedor_id ?? $actor->id;
        }

        $vendedorId = $solicitado ?? $pedido->vendedor_id ?? $actor->id;
        $valido = DB::table('users')
            ->where('empresa_id', $actor->empresa_id)
            ->where('id', $vendedorId)
            ->where('activo', true)
            ->where(fn ($q) => $q->where('rol', Rol::Administrador->value)
                ->orWhere(fn ($q) => $q->where('rol', Rol::Vendedor->value)->where('sucursal_id', $sucursalId)))
            ->exists();

        return $valido ? $vendedorId : throw ValidationException::withMessages(['vendedor_id' => __('Elige un vendedor activo de la sucursal del pedido.')]);
    }

    /**
     * @param  list<array<string, mixed>>  $entrada
     * @return list<array{producto_id: int, cantidad: string, tipo_precio: string, precio_unitario: string, descuento_tipo: string, descuento_valor: string}>
     */
    private function lineas(int $empresaId, ?Pedido $pedido, array $entrada): array
    {
        if ($entrada === []) {
            throw ValidationException::withMessages(['lineas' => __('Agrega al menos un producto.')]);
        }

        if (count($entrada) > self::MAX_LINEAS) {
            throw ValidationException::withMessages(['lineas' => __('Un pedido admite como máximo :maximo líneas.', ['maximo' => self::MAX_LINEAS])]);
        }

        $ids = array_map(fn (array $l) => (int) ($l['producto_id'] ?? 0), $entrada);
        $productos = DB::table('productos')
            ->join('unidades_medida', 'unidades_medida.id', '=', 'productos.unidad_medida_id')
            ->where('productos.empresa_id', $empresaId)
            ->where('productos.activo', true)
            ->whereIn('productos.id', $ids)
            ->get(['productos.id', 'productos.nombre', 'productos.contenido', 'productos.tipo_venta', 'productos.precio_consumidor', 'productos.precio_minorista',
                'productos.precio_mayorista', 'unidades_medida.admite_decimales', 'unidades_medida.abreviatura'])
            ->keyBy('id');
        $cotizados = $pedido === null ? collect() : DB::table('pedido_items')
            ->where('empresa_id', $empresaId)->where('pedido_id', $pedido->id)
            ->get(['producto_id', 'tipo_precio', 'precio_unitario'])
            ->keyBy(fn (object $i) => $i->producto_id.':'.$i->tipo_precio);

        $lineas = [];

        foreach ($entrada as $indice => $linea) {
            $producto = $productos->get($ids[$indice]);
            $campo = "lineas.{$indice}";

            if ($producto === null) {
                throw ValidationException::withMessages(['lineas' => __('Un producto del pedido no existe o está inactivo.')]);
            }

            $nombreProducto = Producto::formatoNombreCompleto($producto->nombre, $producto->contenido, $producto->abreviatura);
            $cantidad = Decimal::normalizar(is_scalar($linea['cantidad'] ?? null) ? (string) $linea['cantidad'] : '', Decimal::ESCALA_CANTIDAD);
            $esEntera = ($producto->tipo_venta ?? 'unidad') === 'unidad' || ! $producto->admite_decimales;

            if ($cantidad === null || Decimal::comparar($cantidad, '0') <= 0) {
                throw ValidationException::withMessages(["{$campo}.cantidad" => __(':producto: la cantidad debe ser mayor que cero.', ['producto' => $nombreProducto])]);
            }

            if ($esEntera && ! Decimal::esEntero($cantidad)) {
                throw ValidationException::withMessages(["{$campo}.cantidad" => __(':producto: se vende en unidades enteras.', ['producto' => $nombreProducto])]);
            }

            $tipo = TipoPrecio::tryFrom((string) ($linea['tipo_precio'] ?? ''))
                ?? throw ValidationException::withMessages(["{$campo}.tipo_precio" => __('Elige Consumidor, Minorista o Mayorista.')]);
            $precio = $cotizados->get($producto->id.':'.$tipo->value)->precio_unitario ?? $producto->{$tipo->columna()};

            if ($precio === null) {
                throw ValidationException::withMessages(["{$campo}.tipo_precio" => __(':producto no tiene precio :lista.', ['producto' => $nombreProducto, 'lista' => mb_strtolower($tipo->etiqueta())])]);
            }

            $descuentoTipo = (string) ($linea['descuento_tipo'] ?? 'bs');
            $lineas[] = [
                'producto_id' => (int) $producto->id,
                'cantidad' => $cantidad,
                'tipo_precio' => $tipo->value,
                'precio_unitario' => (string) $precio,
                'descuento_tipo' => $descuentoTipo,
                'descuento_valor' => $this->importe($linea['descuento_valor'] ?? null, "{$campo}.descuento_valor") ?? '0.00',
            ];
        }

        return $lineas;
    }

    /**
     * Intención de pago guardada con la cotización. Solo precarga la confirmación.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function intencion(array $datos): array
    {
        return [
            'pago_metodo' => MetodoPago::tryFrom((string) $datos['pago_metodo']),
            'pago_en_cuotas' => (bool) $datos['pago_en_cuotas'],
            'pago_cuota_inicial' => $this->importe($datos['pago_cuota_inicial'], 'pago_cuota_inicial'),
            'pago_efectivo' => $this->importe($datos['pago_efectivo'], 'pago_efectivo'),
            'pago_electronico' => $this->importe($datos['pago_electronico'], 'pago_electronico'),
        ];
    }

    private function importe(mixed $valor, string $campo): ?string
    {
        if ($valor === null || trim((string) $valor) === '') {
            return null;
        }

        $importe = is_scalar($valor) ? Decimal::normalizar((string) $valor, Decimal::ESCALA_DINERO) : null;

        if ($importe === null || Decimal::comparar($importe, '0', Decimal::ESCALA_DINERO) < 0) {
            throw ValidationException::withMessages([$campo => __('Ingresa un importe válido, mayor o igual a 0 y con hasta 2 decimales.')]);
        }

        return $importe;
    }
}
