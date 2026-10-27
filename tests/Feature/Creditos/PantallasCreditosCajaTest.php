<?php

use App\Livewire\Caja\Arqueo;
use App\Livewire\Creditos\Historial;
use App\Livewire\Creditos\Lista;
use App\Livewire\Reportes\Panel;
use App\Models\Cliente;
use App\Models\PagoVenta;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'America/La_Paz'));
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    $this->producto = productoDe($this->empresa, ['nombre' => 'Leche 1L', 'precio_consumidor' => '20.00']);
    $this->actingAs($this->admin);
    cargarStock($this->admin, $this->matriz, $this->producto, '100');
    $this->cliente = new Cliente(['nombre' => 'Carlos Mendoza', 'documento' => '4455667']);
    $this->cliente->save();

    // Lunes: crédito Bs 200 con inicial 50 (mixto 20 + 30) y una venta al contado de Bs 100.
    $this->credito = confirmarVenta($this->admin, crearPedido($this->admin, $this->matriz,
        [['producto_id' => $this->producto->id, 'cantidad' => '10']], ['cliente_id' => $this->cliente->id]),
        ['metodo' => 'mixto', 'en_cuotas' => true, 'cuota_inicial' => '50', 'efectivo' => '20', 'electronico' => '30']);
    $this->contado = confirmarVenta($this->admin, crearPedido($this->admin, $this->matriz,
        [['producto_id' => $this->producto->id, 'cantidad' => '5']]), ['metodo' => 'efectivo']);
});

test('the credits list shows sold, collected and balance and only credit sales', function () {
    Livewire::test(Lista::class)
        ->assertSee($this->credito->codigo())->assertDontSee($this->contado->codigo())
        ->assertSee('Bs 200,00')->assertSee('Bs 150,00')->assertSee('Ver historial y abonar')
        ->set('buscar', '4455667')->assertSee($this->credito->codigo())
        ->set('buscar', '')->set('estado', 'pagados')->assertDontSee($this->credito->codigo());
});

test('an installment is registered from the history page and the form renews its key', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 16:00', 'America/La_Paz'));

    $pantalla = Livewire::test(Historial::class, ['venta' => $this->credito])
        ->assertSee('Cuota inicial')->assertSee('Bs 150,00')
        ->set('monto', '30')
        ->call('registrar')
        ->assertHasNoErrors()
        ->assertSee('Abono de Bs 30,00 registrado.')
        ->assertSee('Bs 120,00');

    $clave = $pantalla->get('clave');
    $pantalla->set('metodo', 'mixto')->set('efectivo', '20')->set('electronico', '30')
        ->assertSee('Bs 50,00')
        ->call('registrar')
        ->assertHasNoErrors();

    expect($pantalla->get('clave'))->not->toBe($clave)
        ->and($this->credito->refresh()->saldo_pendiente)->toBe('70.00')
        ->and(PagoVenta::query()->where('tipo', 'abono')->count())->toBe(2);
});

test('the history page rejects an installment above the balance and closes when paid', function () {
    Livewire::test(Historial::class, ['venta' => $this->credito])
        ->set('monto', '150.01')->call('registrar')
        ->assertHasErrors('monto')
        ->set('monto', '150')->call('registrar')
        ->assertHasNoErrors()
        ->assertSee('Crédito pagado')
        ->assertDontSee('Registrar abono</button>', false);

    expect($this->credito->refresh()->saldo_pendiente)->toBe('0.00');
});

test('a seller collects a credit of another branch at the own branch even if the request is manipulated', function () {
    $vendedor = User::factory()->vendedor($this->norte)->create();
    $this->actingAs($vendedor);

    Livewire::test(Lista::class)->assertSee($this->credito->codigo());
    Livewire::test(Historial::class, ['venta' => $this->credito])
        ->assertSet('sucursal_id', (string) $this->norte->id)
        ->set('sucursal_id', (string) $this->matriz->id)
        ->set('metodo', 'electronico')->set('monto', '40')
        ->call('registrar')
        ->assertHasNoErrors();

    $abono = PagoVenta::query()->where('tipo', 'abono')->sole();
    expect($abono->sucursal_id)->toBe($this->norte->id)
        ->and($abono->recibido_por)->toBe($vendedor->id);

    // Caja y reportes son solo para administradores.
    $this->get(route('caja'))->assertForbidden();
    $this->get(route('reportes'))->assertForbidden();
    $this->get(route('caja.exportar'))->assertForbidden();
    $this->get(route('reportes.exportar'))->assertForbidden();
});

test('T12/T16 the cash count of the day separates cash and electronic and excludes the unpaid balance', function () {
    Livewire::test(Arqueo::class)
        ->assertSet('fecha', '2026-10-19')
        ->assertSee('Bs 120,00')   // efectivo: 20 + 100
        ->assertSee('Bs 30,00')    // electrónico
        ->assertSee('Bs 150,00')   // total cobrado, no 300
        ->set('metodo', 'electronico')
        ->assertSee($this->credito->codigo())
        ->assertDontSee($this->contado->codigo());
});

test('T20 the next day an installment appears only in that day cash count', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'America/La_Paz'));
    Livewire::test(Historial::class, ['venta' => $this->credito])->set('monto', '30')->call('registrar');

    Livewire::test(Arqueo::class)->set('fecha', '2026-10-20')
        ->assertSee('Abono · '.$this->credito->codigo())->assertDontSee('Cobro inicial');
    Livewire::test(Arqueo::class)->set('fecha', '2026-10-19')
        ->assertDontSee('Abono · ');
});

test('T23 report CSV exports match the screen filters and totals', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'America/La_Paz'));
    Livewire::test(Historial::class, ['venta' => $this->credito])->set('monto', '30')->call('registrar');

    $cobros = $this->get(route('reportes.exportar', ['pestana' => 'cobros', 'desde' => '2026-10-19', 'hasta' => '2026-10-20']))->streamedContent();
    $soloMartes = $this->get(route('reportes.exportar', ['pestana' => 'cobros', 'desde' => '2026-10-20', 'hasta' => '2026-10-20']))->streamedContent();
    $ventas = $this->get(route('reportes.exportar', ['pestana' => 'ventas', 'desde' => '2026-10-19', 'hasta' => '2026-10-19']))->streamedContent();
    $arqueo = $this->get(route('caja.exportar', ['fecha' => '2026-10-19', 'metodo' => 'efectivo']))->streamedContent();

    expect(substr_count($cobros, "\n"))->toBe(4)   // encabezado + inicial + contado + abono
        ->and($soloMartes)->toContain('Abono;'.$this->credito->codigo())->not->toContain('Cuota inicial')
        ->and($ventas)->toContain($this->credito->codigo().';"Casa Matriz";"Carlos Mendoza"')
        ->and($ventas)->toContain(';200.00;0.00;80.00;120.00')   // vendido, devuelto, cobrado, saldo
        ->and($arqueo)->toContain(';20.00;0.00;20.00')->toContain(';100.00;0.00;100.00')->not->toContain(';30.00');

    Livewire::test(Panel::class)->set('desde', '2026-10-19')->set('hasta', '2026-10-20')
        ->assertSee('Bs 180,00')     // cobros reales del período: 50 + 100 + 30
        ->assertSee('Bs 300,00')     // valor vendido, métrica separada
        ->assertSee('Bs 120,00');    // pendiente por cobrar a hoy
});

test('report tabs for inventory and credits work and other companies stay invisible', function () {
    [$empresaB, $adminB] = empresaConAdministrador(['nombre' => 'Empresa B']);
    $this->actingAs($adminB);
    $sucursalB = Sucursal::factory()->create(['empresa_id' => $empresaB->id]);
    $productoB = productoDe($empresaB, ['nombre' => 'Producto Secreto B']);
    cargarStock($adminB, $sucursalB, $productoB, '3');
    $this->actingAs($this->admin);

    Livewire::test(Panel::class)
        ->set('pestana', 'inventario')->assertSee('Leche 1L')->assertSee('85')->assertDontSee('Producto Secreto B')
        ->set('pestana', 'creditos')->assertSee($this->credito->codigo())->assertSee('Bs 150,00');

    $this->actingAs($adminB);
    Livewire::test(Panel::class)->assertSee('Bs 0,00')->set('pestana', 'creditos')->assertDontSee($this->credito->codigo());
    $this->get(route('creditos.historial', $this->credito))->assertNotFound();
});

test('the home page shows collected today apart from sold today', function () {
    $this->get(route('dashboard'))->assertOk()
        ->assertSee('Cobrado hoy')->assertSee('Bs 150,00')
        ->assertSee('Valor vendido hoy')->assertSee('Bs 300,00');
});

test('paid credits stay visible by default and the tabs filter completed and pending', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'America/La_Paz'));
    Livewire::test(Historial::class, ['venta' => $this->credito])->set('monto', '150')->call('registrar')->assertHasNoErrors();

    Livewire::test(Lista::class)
        ->assertSet('estado', 'todos')->assertSee($this->credito->codigo())->assertSee('Pagado')
        ->assertSee('Completados')
        ->set('estado', 'pagados')->assertSee($this->credito->codigo())
        ->set('estado', 'pendientes')->assertDontSee($this->credito->codigo());

    Livewire::test(Historial::class, ['venta' => $this->credito])->assertOk()->assertSee('Crédito pagado');
});

test('the credit print view breaks down each payment by method and date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 16:00', 'America/La_Paz'));
    Livewire::test(Historial::class, ['venta' => $this->credito])->set('monto', '30')->call('registrar');

    $this->get(route('creditos.imprimir', $this->credito))->assertOk()
        ->assertSee('Estado de crédito')->assertSee('Carlos Mendoza')
        ->assertSee('Cuota inicial')->assertSee('19/10/2026')->assertSee('20/10/2026')
        ->assertSee('Bs 20,00')->assertSee('Bs 30,00')->assertSee('Bs 80,00')->assertSee('Bs 120,00');

    [, $ajeno] = empresaConAdministrador();
    $this->actingAs($ajeno)->get(route('creditos.imprimir', $this->credito))->assertNotFound();
});

test('the cash detail print view lists the day collections and outflows', function () {
    $this->get(route('caja.imprimir', ['fecha' => '2026-10-19']))->assertOk()
        ->assertSee('Detalle de caja')->assertSee('19/10/2026')->assertSee('Cobros del día')->assertSee('Salidas del día')
        ->assertSee('Bs 150,00');

    $vendedor = User::factory()->vendedor($this->matriz)->create();
    $this->actingAs($vendedor)->get(route('caja.imprimir'))->assertForbidden();
});
