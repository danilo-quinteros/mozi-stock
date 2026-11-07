<?php

use App\Domain\Suscripciones\Actions\ConfigurarSuscripcion;
use App\Livewire\Inicio\Guia;
use App\Models\Cliente;
use App\Models\Sucursal;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
 * Inicio: mientras la guía de configuración no termina solo se ve la guía; después, un panel
 * corto por rol con datos reales de la empresa (cobros y valor vendido separados, § 3.11).
 * Semana de prueba en La Paz: lunes 19 y miércoles 21 de octubre.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'America/La_Paz'));
    [$this->empresa, $this->admin] = empresaConAdministrador(['nombre' => 'Comercial Andina']);
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    $this->ana = User::factory()->vendedor($this->matriz)->create(['name' => 'Ana Rojas']);
    $this->beto = User::factory()->vendedor($this->matriz)->create(['name' => 'Beto Paz']);
    $this->carla = User::factory()->vendedor($this->norte)->create(['name' => 'Carla Vaca']);
    $this->leche = productoDe($this->empresa, ['nombre' => 'Leche 1L', 'precio_consumidor' => '20.00', 'stock_minimo' => '5']);
    $this->pan = productoDe($this->empresa, ['nombre' => 'Pan integral', 'precio_consumidor' => '10.00']);
    $this->cafe = productoDe($this->empresa, ['nombre' => 'Café molido', 'precio_consumidor' => '50.00', 'stock_minimo' => '3']);

    $this->actingAs($this->admin);
    cargarStock($this->admin, $this->matriz, $this->leche, '20');
    cargarStock($this->admin, $this->matriz, $this->pan, '100');
    cargarStock($this->admin, $this->matriz, $this->cafe, '4');
    cargarStock($this->admin, $this->norte, $this->leche, '4');
    cargarStock($this->admin, $this->norte, $this->cafe, '10');
    $cliente = new Cliente(['nombre' => 'Carlos Mendoza', 'documento' => '4455667']);
    $cliente->save();

    // Lunes: V-00001, Leche ×3 = Bs 60 al contado, vendida por Ana en Casa Matriz.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'America/La_Paz'));
    confirmarVenta($this->admin, crearPedido($this->admin, $this->matriz,
        [['producto_id' => $this->leche->id, 'cantidad' => '3']], ['vendedor_id' => $this->ana->id]), ['metodo' => 'efectivo']);

    // Miércoles (hoy).
    $this->travelTo(CarbonImmutable::parse('2026-10-21 09:00', 'America/La_Paz'));
    // V-00002: Ana vende y cobra ella misma Pan ×5 = Bs 50 por QR.
    $this->actingAs($this->ana);
    confirmarVenta($this->ana, crearPedido($this->ana, $this->matriz, [['producto_id' => $this->pan->id, 'cantidad' => '5']]), ['metodo' => 'electronico']);
    $this->actingAs($this->admin);
    // V-00003: Café ×2 = Bs 100 a crédito sin inicial, vendedor Beto (Casa Matriz queda con 2 cafés, mínimo 3).
    confirmarVenta($this->admin, crearPedido($this->admin, $this->matriz, [['producto_id' => $this->cafe->id, 'cantidad' => '2']],
        ['vendedor_id' => $this->beto->id, 'cliente_id' => $cliente->id]), ['metodo' => 'efectivo', 'en_cuotas' => true, 'cuota_inicial' => '0']);
    // V-00004: Leche ×2 = Bs 40 al contado en Sucursal Norte (queda con 2 leches, mínimo 5).
    confirmarVenta($this->admin, crearPedido($this->admin, $this->norte,
        [['producto_id' => $this->leche->id, 'cantidad' => '2']], ['vendedor_id' => $this->carla->id]), ['metodo' => 'efectivo']);
    // Pedidos pendientes: uno en cada sucursal.
    crearPedido($this->admin, $this->matriz, [['producto_id' => $this->pan->id, 'cantidad' => '1']]);
    crearPedido($this->admin, $this->norte, [['producto_id' => $this->cafe->id, 'cantidad' => '1']]);
});

test('while the setup guide is pending the home page shows only the guide; the menu still warns about the plan', function () {
    app(ConfigurarSuscripcion::class)->ejecutar(User::factory()->superadmin()->create(), $this->empresa, '149', '2026-10-24');
    $this->empresa->forceFill(['telefono' => null])->save();

    $this->get(route('dashboard'))->assertOk()
        ->assertSee('¡Bienvenido a MoziStock!')->assertSeeLivewire(Guia::class)
        ->assertDontSee('Cobrado hoy')->assertDontSee('Valor vendido hoy')->assertDontSee('Reponer pronto')
        ->assertDontSee('Más vendidos')->assertDontSee('Estado de tu espacio')->assertDontSee('Usuarios activos')
        ->assertDontSee('en o bajo su stock mínimo')->assertDontSee('Tu suscripción vence')
        ->assertSee('Por vencer');
});

test('once the guide is done the administrator sees today, credits, stock to reorder and best sellers', function () {
    guiaTerminada($this->empresa);

    $this->get(route('dashboard'))->assertOk()
        ->assertDontSee('¡Bienvenido a MoziStock!')->assertDontSee('Estado de tu espacio')
        ->assertSee('Así va hoy tu negocio en todas las sucursales.')
        ->assertSee('Cobrado hoy')->assertSee('Bs 90,00')->assertSee('Efectivo Bs 40,00 · Electrónico Bs 50,00')
        ->assertSee('Valor vendido hoy')->assertSee('Bs 190,00')->assertSee('3 ventas · no es ingreso')
        ->assertSee('Por cobrar a hoy')->assertSee('1 cliente con saldo')
        ->assertSee('Cobros de los últimos 7 días')->assertSee('Bs 150,00')
        ->assertSeeInOrder(['V-00004', 'V-00003', 'V-00002', 'V-00001'])->assertSee('Saldo Bs 100,00')
        // Primero lo que tiene menos existencias frente a su mínimo: Leche en Norte (2 de 5) antes que Café en Casa Matriz (2 de 3).
        ->assertSeeInOrder(['Reponer pronto', 'Leche 1L', 'Sucursal Norte', 'Café molido', 'Casa Matriz', 'Más vendidos'])
        // Empate en Bs 100: se ordena por nombre.
        ->assertSeeInOrder(['Más vendidos', 'Café molido', 'Bs 100,00', 'Leche 1L', 'Bs 100,00', 'Pan integral', 'Bs 50,00'])
        ->assertSee(route('reportes'), false)->assertSee(route('pos'), false);
});

test('with a branch fixed in the menu the administrator sees only that branch', function () {
    guiaTerminada($this->empresa);

    $this->withSession([User::SESION_SUCURSAL => $this->norte->id])->get(route('dashboard'))->assertOk()
        ->assertSee('Así va hoy la sucursal')->assertSee('Sucursal Norte')
        ->assertSee('Efectivo Bs 40,00 · Electrónico Bs 0,00')->assertSee('1 venta · no es ingreso')
        ->assertSee('V-00004')->assertDontSee('V-00003')->assertDontSee('V-00002')
        ->assertSee('Quedan 2')->assertDontSee('Café molido');
});

test('a seller sees only their own sales and collections and the stock of their branch', function () {
    $this->actingAs($this->ana)->get(route('dashboard'))->assertOk()
        ->assertSee('Así va tu día en la sucursal')->assertSee('Casa Matriz')
        ->assertSee('Tus ventas hoy')->assertSee('1 venta · no es ingreso')
        ->assertSee('Cobrado por ti hoy')->assertSee('Bs 50,00')
        ->assertSee('Pedidos pendientes')
        ->assertSee('Tus ventas de los últimos 7 días')->assertSee('Bs 110,00')
        ->assertSee('V-00002')->assertSee('V-00001')->assertDontSee('V-00003')->assertDontSee('V-00004')
        ->assertSeeInOrder(['Reponer pronto', 'Café molido', 'Más vendidos'])
        ->assertDontSee('Cobrado hoy')->assertDontSee('Por cobrar a hoy')->assertDontSee(route('reportes'), false)
        ->assertDontSee('Sucursal Norte');
});

test('the home page never shows figures from another company', function () {
    guiaTerminada($this->empresa);
    [$empresaB, $adminB] = empresaConAdministrador(['nombre' => 'Empresa B']);
    guiaTerminada($empresaB);
    $this->actingAs($adminB);
    $sucursalB = Sucursal::factory()->create(['empresa_id' => $empresaB->id]);
    $productoB = productoDe($empresaB, ['nombre' => 'Producto Secreto B', 'precio_consumidor' => '777.00', 'stock_minimo' => '9']);
    cargarStock($adminB, $sucursalB, $productoB, '3');
    confirmarVenta($adminB, crearPedido($adminB, $sucursalB, [['producto_id' => $productoB->id, 'cantidad' => '1']]), ['metodo' => 'efectivo']);

    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
        ->assertDontSee('Producto Secreto B')->assertDontSee('Bs 777,00')->assertSee('Bs 190,00');
    $this->actingAs($this->ana)->get(route('dashboard'))->assertOk()->assertDontSee('Producto Secreto B');
    $this->actingAs($adminB)->get(route('dashboard'))->assertOk()
        ->assertSee('Producto Secreto B')->assertSee('Bs 777,00')->assertDontSee('Leche 1L')->assertDontSee('Bs 190,00');
});
