<?php

use App\Livewire\Pedidos\Editor;
use App\Livewire\Pedidos\Lista;
use App\Livewire\Ventas\Detalle;
use App\Models\Cliente;
use App\Models\MovimientoCaja;
use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Livewire\Livewire;

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->producto = productoDe($this->empresa, ['nombre' => 'Arroz 5kg', 'codigo_barras' => '7771234500012',
        'precio_consumidor' => '39.00', 'precio_minorista' => '36.00', 'precio_mayorista' => '34.00']);
    $this->actingAs($this->admin);
    cargarStock($this->admin, $this->sucursal, $this->producto, '50');
});

test('T09 scanning adds the consumer price and switching a line to wholesale changes only that line', function () {
    Livewire::test(Editor::class)
        ->call('agregarDesdeBuscador', '7771234500012')
        ->call('agregarDesdeBuscador', '7771234500012')
        ->assertSet('lineas.0.cantidad', '2')
        ->assertSet('lineas.0.tipo_precio', 'consumidor')
        ->assertSee('Bs 78,00')
        ->set('lineas.0.tipo_precio', 'mayorista')
        ->assertSee('Bs 68,00');
});

test('T10 the totals shown are the totals stored', function () {
    $pantalla = Livewire::test(Editor::class)
        ->call('agregarProducto', $this->producto->id)
        ->set('lineas.0.cantidad', '3')
        ->set('lineas.0.descuento_valor', '10')
        ->set('lineas.0.descuento_tipo', 'pct')
        ->set('descuento_global_valor', '5.30')
        ->assertSee('Bs 100,00')   // 117 − 11,70 − 5,30
        ->call('guardar');

    $pedido = Pedido::query()->sole();
    expect($pedido->total)->toBe('100.00')
        ->and($pedido->descuento_lineas)->toBe('11.70')
        ->and($pedido->descuento_global)->toBe('5.30');
    $pantalla->assertRedirect(route('pedidos.editar', $pedido));
});

test('a discount above the line amount is shown as an error and cannot be saved', function () {
    Livewire::test(Editor::class)
        ->call('agregarProducto', $this->producto->id)
        ->set('lineas.0.descuento_valor', '40')
        ->assertSee('Un descuento no puede superar el importe')
        ->call('guardar')
        ->assertHasErrors('lineas.0.descuento_valor');

    expect(Pedido::query()->count())->toBe(0);
});

test('the full flow: quote, review and confirm a mixed sale from the screen', function () {
    $pantalla = Livewire::test(Editor::class)
        ->call('agregarProducto', $this->producto->id)
        ->set('lineas.0.cantidad', '5')
        ->call('elegirMetodo', 'mixto')
        ->set('pago_efectivo', '95')
        ->set('pago_electronico', '100')
        ->call('revisar')
        ->assertSet('confirmando', true)
        ->assertSee('Confirmar venta')
        ->call('confirmar');

    $venta = Venta::query()->sole();
    $pantalla->assertRedirect(route('ventas.ver', $venta));

    expect($venta->total)->toBe('195.00')
        ->and($venta->saldo_pendiente)->toBe('0.00')
        ->and(stockDe($this->sucursal, $this->producto))->toBe('45.000')
        ->and(MovimientoCaja::query()->pluck('monto', 'metodo')->all())->toBe(['efectivo' => '95.00', 'electronico' => '100.00']);
});

test('the review rejects a mixed payment that does not add up and a credit without customer', function () {
    Livewire::test(Editor::class)
        ->call('agregarProducto', $this->producto->id)
        ->call('elegirMetodo', 'mixto')
        ->set('pago_efectivo', '10')->set('pago_electronico', '10')
        ->call('revisar')
        ->assertHasErrors('pago_efectivo')
        ->assertSet('confirmando', false)
        ->call('elegirMetodo', 'efectivo')
        ->set('pago_en_cuotas', true)
        ->set('pago_cuota_inicial', '10')
        ->call('revisar')
        ->assertHasErrors('cliente_id')
        ->assertSet('confirmando', false);

    expect(Venta::query()->count())->toBe(0);
});

test('a customer is registered from the order and a credit sale confirmed with initial Bs 0', function () {
    $pantalla = Livewire::test(Editor::class)
        ->call('agregarProducto', $this->producto->id)
        ->set('buscarCliente', 'Carlos')
        ->call('nuevoCliente')
        ->assertSet('clienteNombre', 'Carlos')
        ->set('clienteNombre', 'Carlos Mendoza')->set('clienteDocumento', '4455667')
        ->call('crearCliente')
        ->assertHasNoErrors()
        ->set('pago_en_cuotas', true)
        ->set('pago_cuota_inicial', '0')
        ->call('revisar')
        ->assertSet('confirmando', true)
        ->call('confirmar');

    $venta = Venta::query()->sole();
    expect(Cliente::query()->sole()->documento)->toBe('4455667')
        ->and($venta->cliente_id)->toBe(Cliente::query()->sole()->id)
        ->and($venta->saldo_pendiente)->toBe('39.00')
        ->and(MovimientoCaja::query()->count())->toBe(0);

    Livewire::test(Detalle::class, ['venta' => $venta])
        ->assertSee('Valor vendido')->assertSee('Bs 39,00')
        ->assertSee('Cobrado')->assertSee('Bs 0,00')
        ->assertSee('Sin cobros todavía');
});

test('opening a sold order shows the sale; cancelled orders are read only', function () {
    $vendido = crearPedido($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '1']]);
    $venta = confirmarVenta($this->admin, $vendido, ['metodo' => 'efectivo']);

    $this->get(route('pedidos.editar', $vendido))->assertRedirect(route('ventas.ver', $venta));

    $cancelado = crearPedido($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '1']]);
    Livewire::test(Editor::class, ['pedido' => $cancelado])->call('cancelarPedido')->assertRedirect(route('pedidos.index'));

    $this->get(route('pedidos.editar', $cancelado))->assertOk()->assertSee('Pedido cancelado')->assertDontSee('Confirmar venta');
});

test('the list separates quotations, sales and credit and its CSV keeps sold value apart from collected money', function () {
    $cliente = new Cliente(['nombre' => 'Ana Gutiérrez']);
    $cliente->save();
    crearPedido($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '1']]);
    $credito = crearPedido($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '2']], ['cliente_id' => $cliente->id]);
    confirmarVenta($this->admin, $credito, ['metodo' => 'efectivo', 'en_cuotas' => true, 'cuota_inicial' => '20']);

    Livewire::test(Lista::class)
        ->assertSee('PED-00001')->assertSee('V-00001')
        ->set('pestana', 'cotizaciones')->assertSee('PED-00001')->assertDontSee('V-00001')
        ->set('pestana', 'credito')->assertSee('V-00001')->assertSee('Saldo Bs 58,00')->assertDontSee('Sin cobro · cotización');

    $csv = $this->get(route('pedidos.exportar', ['pestana' => 'credito']))->assertOk()->streamedContent();
    expect($csv)->toContain('"Total vendido";Cobrado;"Saldo pendiente"')
        ->toContain(';V-00001;')
        ->toContain(';78.00;20.00;58.00')
        ->not->toContain('PED-00001;;');
});

test('T02/T03 sellers only see and open orders of their own branch; other companies see nothing', function () {
    $otra = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    $ajeno = crearPedido($this->admin, $otra, [['producto_id' => $this->producto->id, 'cantidad' => '1']]);
    $propio = crearPedido($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '2']]);
    $vendedor = User::factory()->vendedor($this->sucursal)->create();

    $this->actingAs($vendedor);
    Livewire::test(Lista::class)->assertSee($propio->codigo())->assertDontSee($ajeno->codigo());
    $this->get(route('pedidos.editar', $ajeno))->assertForbidden();
    $this->get(route('pedidos.imprimir', $ajeno))->assertForbidden();
    expect($this->get(route('pedidos.exportar'))->streamedContent())->not->toContain($ajeno->codigo());

    [, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $this->get(route('pedidos.editar', $propio))->assertNotFound();
    $this->get(route('pedidos.imprimir', $propio))->assertNotFound();
    Livewire::test(Lista::class)->assertDontSee($propio->codigo());
});

test('the printable quotation is not an invoice and does not affect stock', function () {
    $pedido = crearPedido($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '2']]);

    $this->get(route('pedidos.imprimir', $pedido))->assertOk()
        ->assertSee('Cotización')->assertSee('PED-00001')->assertSee('Bs 78,00')
        ->assertSee('no es factura ni comprobante fiscal');

    expect(stockDe($this->sucursal, $this->producto))->toBe('50.000');
});

test('the inventory history shows the automatic sale exit', function () {
    $pedido = crearPedido($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '3']]);
    confirmarVenta($this->admin, $pedido, ['metodo' => 'efectivo']);

    $this->get(route('inventario.movimientos', ['tipo' => 'venta']))->assertOk()
        ->assertSee('V-00001')->assertSee('Pedido PED-00001')->assertSee('−3');
});
