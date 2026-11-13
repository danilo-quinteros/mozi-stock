<?php

namespace App\Domain\Ventas\Actions;

use App\Domain\Auditoria\Auditor;
use App\Domain\Caja\CajaOperacion;
use App\Domain\Creditos\Actions\RegistrarCuentaCobrar;
use App\Domain\Despachos\HistorialEntrega;
use App\Domain\Facturacion\Actions\RegistrarFacturaVenta;
use App\Domain\Facturacion\DatosFactura;
use App\Domain\Inventario\LibroStock;
use App\Domain\Tenancy\CapacidadesEmpresa;
use App\Domain\Tenancy\ResolverSucursal;
use App\Domain\Ventas\CalculadoraPedido;
use App\Domain\Ventas\RegistradorCobros;
use App\Domain\Ventas\ValidadorPago;
use App\Enums\Capacidad;
use App\Enums\EstadoEntrega;
use App\Enums\EstadoPedido;
use App\Enums\TipoOperacionInventario;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Convierte un pedido pendiente en venta (especificación § 5.2), todo en una transacción:
 * bloquea el pedido, revalida totales con la calculadora, valida el pago, descuenta stock
 * una sola vez (con bloqueo de saldos), crea la venta inmutable y su detalle, registra solo
 * el dinero recibido y deja el saldo como crédito, con un vencimiento opcional (fecha única; el plan
 * de cuotas se define después en el historial de pagos). Confirmar dos veces devuelve la misma venta.
 * Si la empresa factura con SIAT, la factura se registra en la misma transacción y se envía al SIN
 * después del commit; una factura bloqueada (total que no cuadra, falta configuración) no impide la venta.
 *
 * Fuera del POS (Pedidos y ventas) el cobro inicial se trata como un abono (§ 10.4, decisión del
 * propietario del 01/11/2026): el efectivo exige un turno abierto de la sucursal que el usuario opere
 * o, solo para el administrador, fuera de caja; lo electrónico y el crédito sin cuota inicial no lo exigen.
 * El POS ya llega con su caja y su turno.
 *
 * Estado de entrega (ESPECIFICACION_DESPACHOS.md § 7, ESPECIFICACION_COCINA.md § 7): con la capacidad
 * «despachos» la venta nace Por despachar, y con «cocina» En cocina, salvo que quien vende marque
 * «Entregado» ($entregado; nunca se supone). Con «cocina» guarda también el «Nombre o mesa» opcional.
 * Sin ninguna de las dos nace Entregado, sin quién ni evento, exactamente como antes. Pago, stock y caja
 * no cambian.
 *
 * Productos sin control de stock (ESPECIFICACION_PRODUCTOS_SIN_STOCK.md § 4.1): sus marcas se leen en una
 * consulta con bloqueo compartido y sus líneas no van a LibroStock (sin movimiento ni validación de saldo).
 * La venta conserva siempre su operación de inventario: número V, operacion_inventario_id e idempotencia;
 * sin líneas con stock, la operación queda como cabecera con total_lineas = 0.
 */
final class ConfirmarVenta
{
    public function __construct(
        private readonly LibroStock $libro,
        private readonly ResolverSucursal $resolverSucursal,
        private readonly RegistradorCobros $cobros,
        private readonly CajaOperacion $cajas,
        private readonly Auditor $auditor,
        private readonly RegistrarFacturaVenta $facturas,
        private readonly CapacidadesEmpresa $capacidades,
        private readonly HistorialEntrega $historial,
    ) {}

    /**
     * @param  array{metodo: string|null, en_cuotas: bool, cuota_inicial: string|null, efectivo: string|null, electronico: string|null, clave: string, vencimiento?: string|null, externo?: bool, turno_id?: int|null}  $pago
     * @param  DatosFactura|null  $factura  datos fiscales confirmados por el vendedor; null: modo automático con datos propuestos, o sin factura en modo manual
     * @param  bool  $entregado  casilla «Entregado» al vender; solo cuenta con la capacidad «despachos» o «cocina»
     * @param  string|null  $nombreMesa  «Nombre o mesa» (hasta 40 caracteres); solo se guarda con la capacidad «cocina»
     */
    public function ejecutar(
        User $actor,
        Pedido $pedido,
        array $pago,
        ?DatosFactura $factura = null,
        ?int $cajaId = null,
        ?int $turnoId = null,
        bool $entregado = false,
        ?string $nombreMesa = null,
    ): Venta {
        Gate::forUser($actor)->authorize('view', $pedido);

        if ($pedido->estado === EstadoPedido::Vendido && ($venta = $this->ventaDe($pedido)) !== null) {
            return $venta;
        }

        Gate::forUser($actor)->authorize('update', $pedido);

        if (! Str::isUuid($pago['clave'])) {
            throw ValidationException::withMessages(['pago_metodo' => __('La confirmación no tiene una clave válida. Recarga la página.')]);
        }

        // Cocina § 5.7: sin la capacidad no existe el campo y lo recibido se ignora.
        $nombreMesa = $this->capacidades->tiene($pedido->empresa_id, Capacidad::Cocina)
            ? HistorialEntrega::texto($nombreMesa, 40, 'nombreMesa', __('«Nombre o mesa»'))
            : null;

        // Filas de saldo solo para los productos que controlan stock; la marca se vuelve a leer con bloqueo.
        $this->libro->asegurarNiveles((int) $actor->empresa_id, array_map(
            fn (int $productoId) => ['sucursal_id' => $pedido->sucursal_id, 'producto_id' => $productoId], $this->productosConStock($pedido),
        ));

        try {
            return DB::transaction(fn (): Venta => $this->confirmar($actor, $pedido->id, $pago, $factura, $cajaId, $turnoId, $entregado, $nombreMesa), attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            return $this->ventaDe($pedido) ?? throw $e;
        }
    }

    /**
     * @param  array{metodo: string|null, en_cuotas: bool, cuota_inicial: string|null, efectivo: string|null, electronico: string|null, clave: string, vencimiento?: string|null, externo?: bool, turno_id?: int|null}  $pago
     */
    private function confirmar(
        User $actor,
        int $pedidoId,
        array $pago,
        ?DatosFactura $factura,
        ?int $cajaId = null,
        ?int $turnoId = null,
        bool $entregado = false,
        ?string $nombreMesa = null,
    ): Venta {
        $pedido = Pedido::query()->whereKey($pedidoId)->lockForUpdate()->firstOrFail();

        if ($pedido->estado === EstadoPedido::Vendido) {
            return $this->ventaDe($pedido) ?? throw new RuntimeException('Pedido vendido sin venta asociada.');
        }

        if ($pedido->estado === EstadoPedido::Cancelado) {
            throw ValidationException::withMessages(['lineas' => __('El pedido está cancelado y no puede venderse.')]);
        }

        $sucursal = $this->resolverSucursal->paraOperacion($actor, $pedido->sucursal_id);

        if ($sucursal->id !== $pedido->sucursal_id) {
            throw ValidationException::withMessages(['lineas' => __('Solo puedes confirmar pedidos de tu sucursal asignada.')]);
        }

        $items = DB::table('pedido_items')
            ->join('productos', 'productos.id', '=', 'pedido_items.producto_id')
            ->join('unidades_medida', 'unidades_medida.id', '=', 'productos.unidad_medida_id')
            ->where('pedido_items.empresa_id', $pedido->empresa_id)
            ->where('pedido_items.pedido_id', $pedido->id)
            ->orderBy('pedido_items.orden')
            ->get(['pedido_items.*', 'productos.nombre', 'productos.marca', 'productos.contenido', 'productos.sku', 'productos.activo', 'unidades_medida.abreviatura']);

        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['lineas' => __('El pedido no tiene productos.')]);
        }

        if (($inactivo = $items->firstWhere('activo', false)) !== null) {
            $nombreInactivo = Producto::formatoNombreCompleto($inactivo->nombre, $inactivo->contenido, $inactivo->abreviatura);
            throw ValidationException::withMessages(['lineas' => __(':producto está inactivo; quítalo del pedido para vender.', ['producto' => $nombreInactivo])]);
        }

        $this->verificarTotales($pedido, $items);

        $cobro = ValidadorPago::inicial($pedido->total, $pago['metodo'], $pago['en_cuotas'],
            $pago['cuota_inicial'], $pago['efectivo'], $pago['electronico']);
        $saldo = Decimal::restar($pedido->total, $cobro['monto'], Decimal::ESCALA_DINERO);

        if (Decimal::comparar($saldo, '0', 2) > 0 && $pedido->cliente_id === null) {
            throw ValidationException::withMessages(['cliente_id' => __('Para vender a crédito identifica al cliente.')]);
        }

        $vencimiento = Decimal::comparar($saldo, '0', 2) > 0 ? self::vencimiento($pago['vencimiento'] ?? null) : null;

        if ($cobro['metodo'] !== null && $cajaId === null && $turnoId === null) {
            $caja = $this->cajas->resolver($actor, $pedido->sucursal_id, $cobro['efectivo'], (bool) ($pago['externo'] ?? false),
                'externo', isset($pago['turno_id']) ? (int) $pago['turno_id'] : null);
            [$cajaId, $turnoId] = [$caja['caja_id'], $caja['turno_id']];
        }

        $cambios = [];
        $controlan = $this->marcasDeControl($pedido->empresa_id, array_values($items->pluck('producto_id')->map(fn ($id) => (int) $id)->unique()->all()));

        foreach ($items->groupBy('producto_id') as $productoId => $lineas) {
            if (! ($controlan[(int) $productoId] ?? true)) {
                continue; // Sin control de stock: se vende sin movimiento (§ 4.1.2).
            }

            $primer = $lineas->first();
            $nombreItem = Producto::formatoNombreCompleto((string) $primer->nombre, $primer->contenido, $primer->abreviatura);
            $cambios[] = [
                'sucursal_id' => $pedido->sucursal_id,
                'producto_id' => (int) $productoId,
                'cantidad' => '-'.$lineas->reduce(fn (string $suma, object $l) => Decimal::sumar($suma, (string) $l->cantidad), '0'),
                'nombre' => $nombreItem,
            ];
        }

        $operacion = $this->libro->aplicar($actor, [
            'tipo' => TipoOperacionInventario::Venta,
            'motivo' => null,
            'sucursal_id' => $pedido->sucursal_id,
            'sucursal_destino_id' => null,
            'nota' => 'Pedido '.$pedido->codigo(),
            'clave' => $pago['clave'],
        ], $cambios);

        $venta = new Venta;
        $venta->empresa_id = $pedido->empresa_id;
        $venta->pedido_id = $pedido->id;
        $venta->numero = $operacion->numero;
        $venta->sucursal_id = $pedido->sucursal_id;
        $venta->caja_id = $cajaId;
        $venta->turno_caja_id = $turnoId;
        $venta->cliente_id = $pedido->cliente_id;
        $venta->vendedor_id = $pedido->vendedor_id;
        $venta->confirmado_por = $actor->id;
        $venta->operacion_inventario_id = $operacion->id;
        $venta->fecha = Carbon::now();
        $venta->subtotal = $pedido->subtotal;
        $venta->descuento_lineas = $pedido->descuento_lineas;
        $venta->descuento_global = $pedido->descuento_global;
        $venta->total = $pedido->total;
        $venta->cobrado_inicial = $cobro['monto'];
        $venta->saldo_pendiente = $saldo;
        $venta->vencimiento = $vencimiento === null ? null : Carbon::parse($vencimiento);
        $venta->devuelto = '0.00';
        $venta->reembolsado = '0.00';
        $flujo = $this->capacidades->flujoEntrega($pedido->empresa_id);
        $venta->estado_entrega = $flujo !== null && ! $entregado ? EstadoEntrega::inicial($flujo) : EstadoEntrega::Entregado;
        $venta->nombre_mesa = $flujo === Capacidad::Cocina ? $nombreMesa : null;

        if ($flujo !== null && $entregado) {
            // El cliente se lleva los productos delante de quien vende: no hace falta despacho (§ 3 regla 1),
            // ni pasar por cocina (cocina C2).
            $venta->entregado_por = $actor->id;
            $venta->entregado_en = $venta->fecha;
        }

        $venta->save();

        if ($flujo !== null) {
            $this->historial->registrar($venta, null, $venta->estado_entrega, $actor, $venta->fecha);
        }

        DB::table('venta_items')->insert($items->values()->map(fn (object $item) => [
            'empresa_id' => $venta->empresa_id,
            'venta_id' => $venta->id,
            'producto_id' => $item->producto_id,
            'orden' => $item->orden,
            // Con marca, «Paracetamol (Bayer)»: el ticket y la factura (SIAT copia esta descripción) distinguen dos marcas.
            'descripcion' => Producto::descripcionConMarca((string) $item->nombre, $item->marca),
            'sku' => $item->sku,
            'unidad' => $item->abreviatura,
            'cantidad' => $item->cantidad,
            'tipo_precio' => $item->tipo_precio,
            'precio_unitario' => $item->precio_unitario,
            'descuento_tipo' => $item->descuento_tipo,
            'descuento_valor' => $item->descuento_valor,
            'descuento' => $item->descuento,
            'subtotal' => $item->subtotal,
            'total' => $item->total,
            'created_at' => $venta->fecha,
        ])->all());

        if ($cobro['metodo'] !== null) {
            $this->cobros->registrar($actor, $venta, $pedido->sucursal_id, 'inicial', $cobro['metodo'],
                $cobro['efectivo'], $cobro['electronico'], $pago['clave']);
        }

        $pedido->estado = EstadoPedido::Vendido;
        $pedido->save();

        // Facturar no es cobrar: la factura no escribe en pagos_venta ni en movimientos_caja.
        $this->facturas->registrar($actor, $venta, $factura);

        $this->auditor->registrar('venta.confirmada', $venta, $actor, $venta->empresa_id, despues: [
            'pedido' => $pedido->codigo(), 'total' => $venta->total, 'cobrado_inicial' => $venta->cobrado_inicial,
            'saldo_pendiente' => $venta->saldo_pendiente, 'metodo' => $cobro['metodo']?->value, 'vencimiento' => $vencimiento,
        ] + ($flujo !== null ? ['estado_entrega' => $venta->estado_entrega->value] : [])
          + ($venta->nombre_mesa !== null ? ['nombre_mesa' => $venta->nombre_mesa] : []));

        return $venta;
    }

    /**
     * Vencimiento opcional del saldo a crédito: vacío o una fecha desde hoy (hora de negocio).
     *
     * @throws ValidationException
     */
    public static function vencimiento(?string $valor): ?string
    {
        $fecha = RegistrarCuentaCobrar::fecha($valor, 'pago_vencimiento');

        if ($fecha !== null && $fecha < Carbon::now(config('mozistock.business_timezone'))->format('Y-m-d')) {
            throw ValidationException::withMessages(['pago_vencimiento' => __('El vencimiento no puede ser anterior a hoy.')]);
        }

        return $fecha;
    }

    /**
     * Los totales guardados deben coincidir con un recálculo desde el detalle.
     *
     * @param  Collection<int, \stdClass>  $items
     */
    private function verificarTotales(Pedido $pedido, Collection $items): void
    {
        $calculo = CalculadoraPedido::calcular(array_values($items->map(fn (\stdClass $i) => [
            'cantidad' => (string) $i->cantidad,
            'precio_unitario' => (string) $i->precio_unitario,
            'descuento_tipo' => (string) $i->descuento_tipo,
            'descuento_valor' => (string) $i->descuento_valor,
        ])->all()), $pedido->descuento_global_tipo, $pedido->descuento_global_valor);

        if (Decimal::comparar($calculo['total'], $pedido->total, 2) !== 0) {
            throw ValidationException::withMessages(['lineas' => __('Los importes del pedido no coinciden. Vuelve a guardarlo antes de confirmar.')]);
        }
    }

    /**
     * Productos del pedido que controlan stock (lectura sin bloqueo, antes de la transacción).
     *
     * @return list<int>
     */
    private function productosConStock(Pedido $pedido): array
    {
        return array_values(DB::table('pedido_items')
            ->join('productos', 'productos.id', '=', 'pedido_items.producto_id')
            ->where('pedido_items.empresa_id', $pedido->empresa_id)
            ->where('pedido_items.pedido_id', $pedido->id)
            ->where('productos.controla_stock', true)
            ->distinct()
            ->pluck('pedido_items.producto_id')
            ->map(fn ($id) => (int) $id)
            ->all());
    }

    /**
     * Marca «Controla stock» de los productos, en una consulta y con bloqueo compartido: GuardarProducto la
     * cambia con FOR UPDATE, así una venta en curso y un cambio de casilla no se cruzan (§ 4.5).
     *
     * @param  list<int>  $productoIds
     * @return array<int, bool> producto_id => controla stock
     */
    private function marcasDeControl(int $empresaId, array $productoIds): array
    {
        return DB::table('productos')
            ->where('empresa_id', $empresaId)
            ->whereIn('id', $productoIds)
            ->orderBy('id')
            ->sharedLock()
            ->pluck('controla_stock', 'id')
            ->mapWithKeys(fn ($controla, $id) => [(int) $id => (bool) $controla])
            ->all();
    }

    private function ventaDe(Pedido $pedido): ?Venta
    {
        return Venta::query()->where('pedido_id', $pedido->id)->first();
    }
}
