<?php

use App\Domain\Ventas\Actions\VenderEnPos;
use App\Enums\EstadoPedido;
use App\Livewire\Ventas\Pos;
use App\Models\Cliente;
use App\Models\MovimientoCaja;
use App\Models\MovimientoStock;
use App\Models\PagoVenta;
use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Punto de venta (pos.html): guarda y confirma en un paso con las acciones de Pedidos y ventas.
 * Producto de prueba: consumidor Bs 20, minorista Bs 18, mayorista Bs 15; 20 unidades en Casa Matriz.
 */

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->producto = productoDe($this->empresa, ['nombre' => 'Aceite 1L', 'codigo_barras' => '7771234500012',
        'precio_consumidor' => '20.00', 'precio_minorista' => '18.00', 'precio_mayorista' => '15.00']);
    $this->actingAs($this->admin);
    cargarStock($this->admin, $this->sucursal, $this->producto, '20');
    $this->cliente = new Cliente(['nombre' => 'Carlos Mendoza', 'documento' => '4455667']);
    $this->cliente->save();
});

/**
 * @param  array<string, mixed>  $pago
 * @param  array<string, mixed>  $extra
 */
function venderEnPos(User $actor, Sucursal $sucursal, array $lineas, array $pago, array $extra = []): Venta
{
    return app(VenderEnPos::class)->ejecutar($actor, $extra + [
        'sucursal_id' => $sucursal->id, 'cliente_id' => null,
        'lineas' => array_map(fn (array $l) => $l + ['tipo_precio' => 'consumidor', 'descuento_tipo' => 'bs', 'descuento_valor' => '0'], $lineas),
        'descuento_global_tipo' => 'bs', 'descuento_global_valor' => '0', 'clave' => (string) Str::uuid(),
    ], $pago + ['metodo' => 'efectivo', 'en_cuotas' => false, 'cuota_inicial' => null, 'efectivo' => null,
        'electronico' => null, 'clave' => (string) Str::uuid()]);
}

test('the POS screen sells by scanning, charges once and starts a new empty sale', function () {
    $pantalla = Livewire::test(Pos::class)
        ->assertSee('Aceite 1L')
        ->call('agregarDesdeBuscador', '7771234500012')
        ->call('agregarDesdeBuscador', '7771234500012')
        ->assertSet('lineas.0.cantidad', '2')
        ->assertSee('Bs 40,00')
        ->set('recibido', '50')
        ->assertSee('Bs 10,00');   // cambio
    $clave = $pantalla->get('clave');

    $pantalla->call('confirmar')->assertHasNoErrors();

    $venta = Venta::query()->sole();
    expect($venta->total)->toBe('40.00')
        ->and($venta->saldo_pendiente)->toBe('0.00')
        ->and($venta->pedido->estado)->toBe(EstadoPedido::Vendido)
        ->and(stockDe($this->sucursal, $this->producto))->toBe('18.000')
        ->and(MovimientoCaja::query()->pluck('monto', 'metodo')->all())->toBe(['efectivo' => '40.00']);
    $pantalla->assertSet('lineas', [])
        ->assertSet('ultimaVentaId', $venta->id)
        ->assertSee($venta->codigo());
    expect($pantalla->get('clave'))->not->toBe($clave);
});

test('the shown price list and discount are the ones stored', function () {
    Livewire::test(Pos::class)
        ->call('agregarProducto', $this->producto->id)
        ->call('sumar', 0, 1)
        ->call('sumar', 0, 1)
        ->set('lineas.0.tipo_precio', 'mayorista')
        ->set('descuento_global_tipo', 'pct')
        ->set('descuento_global_valor', '10')
        ->assertSee('Bs 40,50')   // 3 × 15 = 45 − 10 %
        ->call('elegirMetodo', 'electronico')
        ->call('confirmar')
        ->assertHasNoErrors();

    $venta = Venta::query()->sole();
    expect($venta->total)->toBe('40.50')
        ->and($venta->descuento_global)->toBe('4.50')
        ->and(DB::table('venta_items')->value('precio_unitario'))->toBe('15.00')
        ->and(MovimientoCaja::query()->pluck('monto', 'metodo')->all())->toBe(['electronico' => '40.50']);
});

test('cash received below the charge blocks the sale without touching stock or cash', function () {
    Livewire::test(Pos::class)
        ->call('agregarProducto', $this->producto->id)
        ->set('recibido', '15')
        ->assertSee('Faltan Bs 5,00 para cubrir el cobro.')
        ->call('confirmar')
        ->assertHasErrors('recibido');

    expect(Venta::query()->count())->toBe(0)
        ->and(Pedido::query()->count())->toBe(0)
        ->and(stockDe($this->sucursal, $this->producto))->toBe('20.000');
});

test('the catalog never adds more than the branch stock', function () {
    $pantalla = Livewire::test(Pos::class)->call('agregarProducto', $this->producto->id)->set('lineas.0.cantidad', '20');

    $pantalla->call('agregarProducto', $this->producto->id)
        ->assertSet('lineas.0.cantidad', '20')
        ->assertSee('No hay más existencias de Aceite 1L en esta sucursal.')
        ->call('sumar', 0, 1)
        ->assertSet('lineas.0.cantidad', '20')
        ->set('lineas.0.cantidad', '21')
        ->assertSee('Aceite 1L: stock insuficiente en esta sucursal.');
});

test('regression: credit Bs 200 with initial Bs 50 records 50 now and leaves 150 pending', function () {
    $venta = venderEnPos($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '10']],
        ['metodo' => 'mixto', 'en_cuotas' => true, 'cuota_inicial' => '50', 'efectivo' => '20', 'electronico' => '30'],
        ['cliente_id' => $this->cliente->id]);

    expect($venta->total)->toBe('200.00')
        ->and($venta->cobrado_inicial)->toBe('50.00')
        ->and($venta->saldo_pendiente)->toBe('150.00')
        ->and(PagoVenta::query()->count())->toBe(1)
        ->and((string) MovimientoCaja::query()->sum('monto'))->toBe('50.00');
});

test('a credit sale from the screen requires an identified customer', function () {
    $pantalla = Livewire::test(Pos::class)
        ->call('agregarProducto', $this->producto->id)
        ->call('alternarCredito')
        ->assertSee('Para vender a crédito identifica al cliente.')
        ->call('confirmar')
        ->assertHasErrors('cliente_id');

    expect(Pedido::query()->count())->toBe(0);

    $pantalla->call('seleccionarCliente', $this->cliente->id)->call('confirmar')->assertHasNoErrors();
    expect(Venta::query()->sole()->saldo_pendiente)->toBe('20.00')
        ->and(PagoVenta::query()->count())->toBe(0);
});

test('a failed sale leaves no pending order behind (atomic)', function (array $lineas, array $pago) {
    expect(fn () => venderEnPos($this->admin, $this->sucursal, $lineas, $pago))->toThrow(ValidationException::class);

    expect(Pedido::query()->count())->toBe(0)
        ->and(Venta::query()->count())->toBe(0)
        ->and(MovimientoCaja::query()->count())->toBe(0)
        ->and(MovimientoStock::query()->where('cantidad', '<', 0)->count())->toBe(0)
        ->and(stockDe($this->sucursal, $this->producto))->toBe('20.000');
})->with([
    'más que el stock' => fn () => [[['producto_id' => $this->producto->id, 'cantidad' => '21']], ['metodo' => 'efectivo']],
    'mixto que no suma el total' => fn () => [[['producto_id' => $this->producto->id, 'cantidad' => '10']], ['metodo' => 'mixto', 'efectivo' => '80', 'electronico' => '100']],
    'sin productos' => fn () => [[], ['metodo' => 'efectivo']],
]);

test('regression: repeating the same request (double click or retry) creates one sale, payment and stock exit', function () {
    $claves = ['clave' => (string) Str::uuid()];
    $pago = ['metodo' => 'efectivo', 'clave' => (string) Str::uuid()];
    $lineas = [['producto_id' => $this->producto->id, 'cantidad' => '5']];

    $primera = venderEnPos($this->admin, $this->sucursal, $lineas, $pago, $claves);
    $segunda = venderEnPos($this->admin, $this->sucursal, $lineas, $pago, $claves);

    expect($segunda->id)->toBe($primera->id)
        ->and(Pedido::query()->count())->toBe(1)
        ->and(Venta::query()->count())->toBe(1)
        ->and(PagoVenta::query()->count())->toBe(1)
        ->and(MovimientoCaja::query()->count())->toBe(1)
        ->and(stockDe($this->sucursal, $this->producto))->toBe('15.000');
});

test('a seller always sells at the assigned branch, even if the request says otherwise', function () {
    $otra = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    cargarStock($this->admin, $otra, $this->producto, '5');
    $vendedor = User::factory()->vendedor($this->sucursal)->create();
    $this->actingAs($vendedor);

    Livewire::test(Pos::class)
        ->assertSet('sucursal_id', (string) $this->sucursal->id)
        ->set('sucursal_id', (string) $otra->id)
        ->assertSet('sucursal_id', (string) $this->sucursal->id);

    $venta = venderEnPos($vendedor, $otra, [['producto_id' => $this->producto->id, 'cantidad' => '1']], ['metodo' => 'efectivo']);

    expect($venta->sucursal_id)->toBe($this->sucursal->id)
        ->and($venta->vendedor_id)->toBe($vendedor->id)
        ->and(stockDe($otra, $this->producto))->toBe('5.000')
        ->and(stockDe($this->sucursal, $this->producto))->toBe('19.000');
});

test('regression: companies A and B never see or sell each other products in the POS', function () {
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $productoB = productoDe($empresaB, ['nombre' => 'Producto secreto B']);
    $this->actingAs($this->admin);

    Livewire::test(Pos::class)
        ->assertSee('Aceite 1L')
        ->assertDontSee('Producto secreto B')
        ->call('agregarProducto', $productoB->id)
        ->assertSet('lineas', []);

    expect(fn () => venderEnPos($this->admin, $this->sucursal, [['producto_id' => $productoB->id, 'cantidad' => '1']], ['metodo' => 'efectivo']))
        ->toThrow(ValidationException::class);
    expect(DB::table('stock_niveles')->where('producto_id', $productoB->id)->count())->toBe(0)
        ->and(Pedido::query()->count())->toBe(0);
});

test('the POS is accessible and the sidebar can be collapsed from next to the logo', function () {
    $vendedor = User::factory()->vendedor($this->sucursal)->create();

    $this->actingAs($vendedor)->get(route('pos'))
        ->assertOk()
        ->assertDontSee('Ventas POS')
        ->assertSee('data-sidebar-toggle', false)
        ->assertSee('Punto de venta');
});
