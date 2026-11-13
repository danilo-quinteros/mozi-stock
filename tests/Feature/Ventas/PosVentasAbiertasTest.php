<?php

use App\Domain\Caja\Actions\AbrirTurnoCaja;
use App\Livewire\Ventas\Pos;
use App\Models\Cliente;
use App\Models\MovimientoCaja;
use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\Venta;
use Livewire\Livewire;

/*
 * POS con varias ventas abiertas (barra inferior de pestañas): cada pestaña guarda su carrito, cliente y cobro
 * hasta confirmarla o cancelarla. Cambiar de pestaña no toca stock ni caja; solo confirmar lo hace.
 */

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->aceite = productoDe($this->empresa, ['nombre' => 'Aceite 1L', 'codigo_barras' => '7771234500012', 'precio_consumidor' => '20.00']);
    $this->arroz = productoDe($this->empresa, ['nombre' => 'Arroz 5kg', 'codigo_barras' => '7771234500029', 'precio_consumidor' => '35.50']);
    $this->actingAs($this->admin);
    cargarStock($this->admin, $this->sucursal, $this->aceite, '20');
    cargarStock($this->admin, $this->sucursal, $this->arroz, '10');
    $this->cliente = new Cliente(['nombre' => 'Carlos Mendoza', 'documento' => '4455667']);
    $this->cliente->save();
    app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->sucursal->cajaPrincipal(), '100.00');
});

test('a second sale can be opened and the first one comes back intact', function () {
    $pantalla = Livewire::test(Pos::class)
        ->call('agregarProducto', $this->aceite->id)
        ->call('sumar', 0, 1)
        ->call('seleccionarCliente', $this->cliente->id)
        ->call('elegirMetodo', 'electronico');
    $primera = $pantalla->get('ventaActiva');
    $clavePrimera = $pantalla->get('clave');

    $pantalla->call('nuevaVenta')
        ->assertSet('lineas', [])
        ->assertSet('cliente_id', null)
        ->assertSet('pago_metodo', 'efectivo')
        ->assertCount('ventas', 2)
        ->assertSee('Venta 2')
        ->assertSee('Carlos Mendoza · 2 art. · Bs 40,00')   // la venta en espera, en su pestaña
        ->assertDispatched('pos-venta-cambiada');
    expect($pantalla->get('clave'))->not->toBe($clavePrimera);

    $pantalla->call('agregarProducto', $this->arroz->id)
        ->call('cambiarVenta', $primera)
        ->assertSet('ventaActiva', $primera)
        ->assertSet('lineas.0.id', $this->aceite->id)
        ->assertSet('lineas.0.cantidad', '2')
        ->assertSet('cliente_id', $this->cliente->id)
        ->assertSet('pago_metodo', 'electronico')
        ->assertSet('clave', $clavePrimera);

    // Abrir y cambiar pestañas nunca vende ni descuenta stock.
    expect(Venta::query()->count())->toBe(0)
        ->and(Pedido::query()->count())->toBe(0)
        ->and(MovimientoCaja::query()->count())->toBe(0)
        ->and(stockDe($this->sucursal, $this->aceite))->toBe('20.000')
        ->and(stockDe($this->sucursal, $this->arroz))->toBe('10.000');
});

test('confirming one open sale charges only its products and returns to the waiting sale', function () {
    $pantalla = Livewire::test(Pos::class)->call('agregarProducto', $this->aceite->id);
    $primera = $pantalla->get('ventaActiva');

    $pantalla->call('nuevaVenta')
        ->call('agregarProducto', $this->arroz->id)
        ->set('recibido', '50')
        ->call('confirmar')
        ->assertHasNoErrors()
        ->assertCount('ventas', 1)
        ->assertSet('ventaActiva', $primera)
        ->assertSet('lineas.0.id', $this->aceite->id)
        ->assertSet('recibido', '');

    $venta = Venta::query()->sole();
    expect($venta->total)->toBe('35.50')
        ->and(stockDe($this->sucursal, $this->arroz))->toBe('9.000')
        ->and(stockDe($this->sucursal, $this->aceite))->toBe('20.000')
        ->and(MovimientoCaja::query()->pluck('monto', 'metodo')->all())->toBe(['efectivo' => '35.50']);

    // La venta que esperaba se confirma después con sus propias claves.
    $pantalla->call('confirmar')->assertHasNoErrors()->assertSet('lineas', []);
    expect(Venta::query()->count())->toBe(2)
        ->and(Venta::query()->latest('id')->value('total'))->toBe('20.00')
        ->and(stockDe($this->sucursal, $this->aceite))->toBe('19.000');
});

test('cancelling an open sale discards its cart without touching stock or cash', function () {
    $pantalla = Livewire::test(Pos::class)->call('agregarProducto', $this->aceite->id);
    $primera = $pantalla->get('ventaActiva');
    $pantalla->call('nuevaVenta')->call('agregarProducto', $this->arroz->id);
    $segunda = $pantalla->get('ventaActiva');

    // Cancelar una en espera no cambia la venta que se está atendiendo.
    $pantalla->call('cerrarVenta', $primera)
        ->assertCount('ventas', 1)
        ->assertSet('ventaActiva', $segunda)
        ->assertSet('lineas.0.id', $this->arroz->id);

    // Cancelar la última deja una venta nueva y vacía.
    $pantalla->call('cerrarVenta', $segunda)
        ->assertCount('ventas', 1)
        ->assertSet('lineas', [])
        ->assertSee('Venta 1');

    expect(Venta::query()->count())->toBe(0)
        ->and(Pedido::query()->count())->toBe(0)
        ->and(MovimientoCaja::query()->count())->toBe(0)
        ->and(stockDe($this->sucursal, $this->aceite))->toBe('20.000');
});

test('open sales survive reloading the POS but are private to each user and company', function () {
    $pantalla = Livewire::test(Pos::class)->call('agregarProducto', $this->aceite->id);
    $primera = $pantalla->get('ventaActiva');
    $pantalla->call('nuevaVenta')->call('agregarProducto', $this->arroz->id);
    $segunda = $pantalla->get('ventaActiva');

    Livewire::test(Pos::class)
        ->assertCount('ventas', 2)
        ->assertSet('ventaActiva', $segunda)
        ->assertSet('lineas.0.id', $this->arroz->id)
        ->call('cambiarVenta', $primera)
        ->assertSet('lineas.0.id', $this->aceite->id);

    // Otra empresa (y otro usuario) empieza con una sola venta vacía.
    [$otra, $adminOtra] = empresaConAdministrador();
    $this->actingAs($adminOtra);
    Sucursal::factory()->create(['empresa_id' => $otra->id]);

    Livewire::test(Pos::class)
        ->assertCount('ventas', 1)
        ->assertSet('lineas', [])
        ->assertDontSee('Aceite 1L');
});

test('there is a limit of open sales and unknown tabs are ignored', function () {
    $pantalla = Livewire::test(Pos::class);

    for ($i = 1; $i < Pos::MAX_VENTAS; $i++) {
        $pantalla->call('nuevaVenta');
    }

    $pantalla->assertCount('ventas', Pos::MAX_VENTAS)
        ->call('nuevaVenta')
        ->assertCount('ventas', Pos::MAX_VENTAS)
        ->assertSee('Puedes tener hasta '.Pos::MAX_VENTAS.' ventas abiertas.');

    $activa = $pantalla->get('ventaActiva');
    $pantalla->call('cambiarVenta', 'no-existe')->assertSet('ventaActiva', $activa)
        ->call('cerrarVenta', 'no-existe')->assertCount('ventas', Pos::MAX_VENTAS);
});
