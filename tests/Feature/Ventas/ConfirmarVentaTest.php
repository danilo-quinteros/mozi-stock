<?php

use App\Domain\Ventas\Actions\CancelarPedido;
use App\Domain\Ventas\Actions\GuardarPedido;
use App\Enums\EstadoPedido;
use App\Models\Cliente;
use App\Models\MovimientoCaja;
use App\Models\MovimientoStock;
use App\Models\OperacionInventario;
use App\Models\PagoVenta;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * Cobros, stock y ventas: T05, T06, T07, T09, T11, T15, T16, T17, T18 y T19.
 * Producto de prueba: consumidor Bs 20, minorista Bs 18, mayorista Bs 15.
 */

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id]);
    $this->producto = productoDe($this->empresa, ['nombre' => 'Aceite 1L', 'precio_consumidor' => '20.00', 'precio_minorista' => '18.00', 'precio_mayorista' => '15.00']);
    $this->actingAs($this->admin);
    cargarStock($this->admin, $this->sucursal, $this->producto, '20');
    $this->cliente = new Cliente(['nombre' => 'Carlos Mendoza', 'documento' => '4455667']);
    $this->cliente->save();
});

function cajaTotal(?string $metodo = null): string
{
    return (string) MovimientoCaja::query()->when($metodo, fn ($q) => $q->where('metodo', $metodo))->sum('monto');
}

test('T05 a quotation of 5 units affects neither stock, cash nor sales; cancelling neither', function () {
    $pedido = crearPedido($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '5']]);

    expect($pedido->codigo())->toBe('PED-00001')
        ->and($pedido->total)->toBe('100.00')
        ->and(stockDe($this->sucursal, $this->producto))->toBe('20.000')
        ->and(Venta::query()->count())->toBe(0)
        ->and(MovimientoCaja::query()->count())->toBe(0);

    app(CancelarPedido::class)->ejecutar($this->admin, $pedido);

    expect($pedido->refresh()->estado)->toBe(EstadoPedido::Cancelado)
        ->and(stockDe($this->sucursal, $this->producto))->toBe('20.000')
        ->and(MovimientoCaja::query()->count())->toBe(0)
        ->and(fn () => confirmarVenta($this->admin, $pedido, ['metodo' => 'efectivo']))->toThrow(AuthorizationException::class);
});

test('T06 confirming a sale of 5 subtracts exactly 5 at the selling branch and creates one sale', function () {
    $pedido = crearPedido($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '5']]);
    $venta = confirmarVenta($this->admin, $pedido, ['metodo' => 'efectivo']);

    expect(stockDe($this->sucursal, $this->producto))->toBe('15.000')
        ->and(Venta::query()->count())->toBe(1)
        ->and($venta->codigo())->toBe('V-00001')
        ->and($venta->items()->sole()->only(['descripcion', 'cantidad', 'precio_unitario', 'total']))
        ->toBe(['descripcion' => 'Aceite 1L', 'cantidad' => '5.000', 'precio_unitario' => '20.00', 'total' => '100.00'])
        ->and($pedido->refresh()->estado)->toBe(EstadoPedido::Vendido)
        ->and(OperacionInventario::query()->where('tipo', 'venta')->sole()->codigo())->toBe('V-00001');
});

test('T07 two sales of the last stock: the second is rejected and stock never goes negative', function () {
    $primero = crearPedido($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '15']]);
    $segundo = crearPedido($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '10']]);

    confirmarVenta($this->admin, $primero, ['metodo' => 'efectivo']);

    expect(fn () => confirmarVenta($this->admin, $segundo, ['metodo' => 'efectivo']))->toThrow(ValidationException::class);
    expect(stockDe($this->sucursal, $this->producto))->toBe('5.000')
        ->and(Venta::query()->count())->toBe(1)
        ->and($segundo->refresh()->estado)->toBe(EstadoPedido::Pendiente)
        ->and(MovimientoCaja::query()->count())->toBe(1)
        ->and(cajaTotal())->toBe('300.00');
});

test('T09 consumer is the default price and the sold price survives catalog changes', function () {
    $pedido = crearPedido($this->admin, $this->sucursal, [
        ['producto_id' => $this->producto->id, 'cantidad' => '1'],
        ['producto_id' => $this->producto->id, 'cantidad' => '1', 'tipo_precio' => 'mayorista'],
    ]);
    $items = $pedido->items()->get();

    expect($items->pluck('precio_unitario')->all())->toBe(['20.00', '15.00'])
        ->and($pedido->total)->toBe('35.00');

    // El catálogo cambia: el pedido conserva su precio cotizado al volver a guardarse.
    $this->producto->forceFill(['precio_consumidor' => '25.00'])->save();
    $pedido = app(GuardarPedido::class)->ejecutar($this->admin, $pedido, [
        'sucursal_id' => $this->sucursal->id, 'cliente_id' => null, 'vendedor_id' => null,
        'lineas' => [
            ['producto_id' => $this->producto->id, 'cantidad' => '2', 'tipo_precio' => 'consumidor', 'descuento_tipo' => 'bs', 'descuento_valor' => '0'],
            ['producto_id' => $this->producto->id, 'cantidad' => '1', 'tipo_precio' => 'mayorista', 'descuento_tipo' => 'bs', 'descuento_valor' => '0'],
        ],
        'descuento_global_tipo' => 'bs', 'descuento_global_valor' => '0', 'nota' => null, 'pago_metodo' => null,
        'pago_en_cuotas' => false, 'pago_cuota_inicial' => null, 'pago_efectivo' => null, 'pago_electronico' => null,
        'clave' => (string) Str::uuid(),
    ]);
    $venta = confirmarVenta($this->admin, $pedido, ['metodo' => 'efectivo']);
    $this->producto->forceFill(['precio_consumidor' => '30.00'])->save();

    expect($venta->refresh()->total)->toBe('55.00')
        ->and($venta->items()->pluck('precio_unitario')->all())->toBe(['20.00', '15.00']);
});

test('T11 a cash sale of Bs 200 records Bs 200 once, not twice', function () {
    $pedido = crearPedido($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '10']]);
    confirmarVenta($this->admin, $pedido, ['metodo' => 'efectivo']);

    expect(cajaTotal())->toBe('200.00')
        ->and(MovimientoCaja::query()->count())->toBe(1)
        ->and(PagoVenta::query()->sole()->only(['tipo', 'monto', 'monto_efectivo', 'monto_electronico']))
        ->toBe(['tipo' => 'inicial', 'monto' => '200.00', 'monto_efectivo' => '200.00', 'monto_electronico' => '0.00']);
});

test('T15 the originating quotation is not counted as income or sale', function () {
    crearPedido($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '10']]);

    expect(cajaTotal())->toBe('0')
        ->and((string) Venta::query()->sum('total'))->toBe('0');
});

test('T16 mixed payment 80 cash + 120 electronic without installments', function () {
    $pedido = crearPedido($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '10']]);
    $venta = confirmarVenta($this->admin, $pedido, ['metodo' => 'mixto', 'efectivo' => '80', 'electronico' => '120']);

    expect(cajaTotal())->toBe('200.00')
        ->and(cajaTotal('efectivo'))->toBe('80.00')
        ->and(cajaTotal('electronico'))->toBe('120.00')
        ->and(PagoVenta::query()->count())->toBe(1)
        ->and(PagoVenta::query()->sole()->monto)->toBe('200.00')
        ->and($venta->saldo_pendiente)->toBe('0.00');
});

test('T17 installments with initial 50 paid 20 cash + 30 electronic leave a balance of 150', function () {
    $pedido = crearPedido($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '10']], ['cliente_id' => $this->cliente->id]);
    $venta = confirmarVenta($this->admin, $pedido, ['metodo' => 'mixto', 'en_cuotas' => true, 'cuota_inicial' => '50', 'efectivo' => '20', 'electronico' => '30']);

    expect(cajaTotal())->toBe('50.00')
        ->and($venta->cobrado_inicial)->toBe('50.00')
        ->and($venta->saldo_pendiente)->toBe('150.00')
        ->and($venta->total)->toBe('200.00');
});

test('T18 an initial payment of Bs 0 confirms the credit sale without a fictitious payment', function () {
    $pedido = crearPedido($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '10']], ['cliente_id' => $this->cliente->id]);
    $venta = confirmarVenta($this->admin, $pedido, ['metodo' => 'efectivo', 'en_cuotas' => true, 'cuota_inicial' => '0']);

    expect($venta->saldo_pendiente)->toBe('200.00')
        ->and(PagoVenta::query()->count())->toBe(0)
        ->and(MovimientoCaja::query()->count())->toBe(0)
        ->and(stockDe($this->sucursal, $this->producto))->toBe('10.000');
});

test('a credit sale requires an identified customer', function () {
    $pedido = crearPedido($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '10']]);

    expect(fn () => confirmarVenta($this->admin, $pedido, ['metodo' => 'efectivo', 'en_cuotas' => true, 'cuota_inicial' => '50']))
        ->toThrow(ValidationException::class);
    expect(Venta::query()->count())->toBe(0)
        ->and(stockDe($this->sucursal, $this->producto))->toBe('20.000');
});

test('payment amounts are validated against the total or the initial installment', function (array $pago) {
    $pedido = crearPedido($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '10']], ['cliente_id' => $this->cliente->id]);

    expect(fn () => confirmarVenta($this->admin, $pedido, $pago))->toThrow(ValidationException::class);
    expect(Venta::query()->count())->toBe(0)
        ->and(MovimientoCaja::query()->count())->toBe(0)
        ->and(MovimientoStock::query()->where('cantidad', '<', 0)->count())->toBe(0);
})->with([
    'mixto que no suma el total' => [['metodo' => 'mixto', 'efectivo' => '80', 'electronico' => '100']],
    'mixto con negativo' => [['metodo' => 'mixto', 'efectivo' => '-10', 'electronico' => '210']],
    'cuota inicial mayor al total' => [['metodo' => 'efectivo', 'en_cuotas' => true, 'cuota_inicial' => '200.01']],
    'cuota inicial negativa' => [['metodo' => 'efectivo', 'en_cuotas' => true, 'cuota_inicial' => '-1']],
    'mixto que no suma la cuota' => [['metodo' => 'mixto', 'en_cuotas' => true, 'cuota_inicial' => '50', 'efectivo' => '20', 'electronico' => '20']],
    'sin método' => [['metodo' => null]],
]);

test('T19 confirming twice (double click or retry) creates one sale, one payment and one stock exit', function () {
    $pedido = crearPedido($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '5']]);
    $pago = ['metodo' => 'efectivo', 'clave' => (string) Str::uuid()];

    $primera = confirmarVenta($this->admin, $pedido, $pago);
    $segunda = confirmarVenta($this->admin, $pedido->refresh(), $pago);
    $tercera = confirmarVenta($this->admin, $pedido, ['metodo' => 'efectivo']);

    expect([$segunda->id, $tercera->id])->toBe([$primera->id, $primera->id])
        ->and(Venta::query()->count())->toBe(1)
        ->and(PagoVenta::query()->count())->toBe(1)
        ->and(MovimientoCaja::query()->count())->toBe(1)
        ->and(stockDe($this->sucursal, $this->producto))->toBe('15.000');
});

test('a confirmed sale cannot be rewritten, edited as an order nor deleted', function () {
    $pedido = crearPedido($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '5']]);
    $venta = confirmarVenta($this->admin, $pedido, ['metodo' => 'efectivo']);

    expect(fn () => $venta->forceFill(['total' => '1.00'])->save())->toThrow(LogicException::class)
        ->and(fn () => $venta->delete())->toThrow(LogicException::class)
        ->and(fn () => app(CancelarPedido::class)->ejecutar($this->admin, $pedido->refresh()))->toThrow(AuthorizationException::class)
        ->and(fn () => DB::table('ventas')->update(['saldo_pendiente' => -1]))->toThrow(QueryException::class);
});

test('sellers only confirm orders of their own branch and the price list comes from the catalog', function () {
    $otra = Sucursal::factory()->create(['empresa_id' => $this->empresa->id]);
    cargarStock($this->admin, $otra, $this->producto, '5');
    $vendedor = User::factory()->vendedor($this->sucursal)->create();
    $ajeno = crearPedido($this->admin, $otra, [['producto_id' => $this->producto->id, 'cantidad' => '1']]);

    $this->actingAs($vendedor);
    expect(fn () => confirmarVenta($vendedor, $ajeno, ['metodo' => 'efectivo']))->toThrow(AuthorizationException::class);

    // Aunque envíe otra sucursal, el vendedor cotiza en la suya y como responsable.
    $propio = crearPedido($vendedor, $otra, [['producto_id' => $this->producto->id, 'cantidad' => '1', 'precio_unitario' => '0.01']],
        ['vendedor_id' => $this->admin->id]);

    expect($propio->sucursal_id)->toBe($this->sucursal->id)
        ->and($propio->vendedor_id)->toBe($vendedor->id)
        ->and($propio->total)->toBe('20.00');
});

test('tenant isolation: products and customers of another company are rejected', function () {
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $productoB = productoDe($empresaB);
    $clienteB = new Cliente(['nombre' => 'Cliente B']);
    $clienteB->save();
    $this->actingAs($this->admin);

    expect(fn () => crearPedido($this->admin, $this->sucursal, [['producto_id' => $productoB->id, 'cantidad' => '1']]))
        ->toThrow(ValidationException::class)
        ->and(fn () => crearPedido($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '1']], ['cliente_id' => $clienteB->id]))
        ->toThrow(ValidationException::class);
});
