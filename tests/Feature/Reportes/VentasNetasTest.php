<?php

use App\Domain\Reportes\Consultas\VentasPeriodo;
use App\Domain\Reportes\Periodo;
use App\Domain\Ventas\Actions\RegistrarDevolucion;
use App\Livewire\Reportes\Panel;
use App\Models\Cliente;
use App\Models\Devolucion;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Reporte de ventas con devoluciones del período y venta neta. Las devoluciones cuentan en la
 * fecha en que se registran, aunque la venta sea de otro período: un período cerrado no cambia.
 * Semana de prueba en La Paz: lunes 19 a miércoles 21 de octubre.
 */

function momentoVentasNetas(string $momento): void
{
    test()->travelTo(CarbonImmutable::parse($momento, 'America/La_Paz'));
}

function devolverUnidades(User $admin, Venta $venta, string $cantidad, ?string $metodo = 'efectivo'): Devolucion
{
    $linea = VentaItem::query()->where('venta_id', $venta->id)->orderBy('orden')->value('id');

    return app(RegistrarDevolucion::class)->ejecutar($admin, $venta, [
        'lineas' => [$linea => ['cantidad' => $cantidad, 'reingresa' => true]],
        'motivo' => 'Producto defectuoso', 'metodo' => $metodo, 'efectivo' => null, 'electronico' => null,
        'externo' => true, 'clave' => (string) Str::uuid(),
    ]);
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    $this->producto = productoDe($this->empresa, ['nombre' => 'Leche 1L', 'precio_consumidor' => '20.00']);
    $this->actingAs($this->admin);
    momentoVentasNetas('2026-10-19 08:00');
    cargarStock($this->admin, $this->matriz, $this->producto, '50');
    cargarStock($this->admin, $this->norte, $this->producto, '50');

    // Lunes: venta al contado de Bs 200 en Casa Matriz.
    momentoVentasNetas('2026-10-19 10:00');
    $this->venta = confirmarVenta($this->admin, crearPedido($this->admin, $this->matriz,
        [['producto_id' => $this->producto->id, 'cantidad' => '10']]), ['metodo' => 'efectivo']);
});

test('a return counts on its own day: the closed day keeps its figures and the net sale is sold minus returned', function () {
    momentoVentasNetas('2026-10-20 16:00');
    devolverUnidades($this->admin, $this->venta, '1');   // Bs 20 el martes

    $lunes = VentasPeriodo::totales($this->empresa->id, Periodo::dia('2026-10-19'));
    $martes = VentasPeriodo::totales($this->empresa->id, Periodo::dia('2026-10-20'));
    $semana = VentasPeriodo::totales($this->empresa->id, Periodo::rango('2026-10-19', '2026-10-21'));

    expect($lunes)->toMatchArray(['valor_vendido' => '200.00', 'cantidad' => 1, 'devoluciones' => '0.00', 'cantidad_devoluciones' => 0, 'venta_neta' => '200.00'])
        ->and($martes)->toMatchArray(['valor_vendido' => '0.00', 'cantidad' => 0, 'devoluciones' => '20.00', 'cantidad_devoluciones' => 1, 'venta_neta' => '-20.00'])
        ->and($semana)->toMatchArray(['valor_vendido' => '200.00', 'devoluciones' => '20.00', 'venta_neta' => '180.00'])
        ->and($this->venta->refresh()->total)->toBe('200.00');
});

test('a credit return also lowers the net sale, although it moves no money', function () {
    momentoVentasNetas('2026-10-20 09:00');
    $cliente = new Cliente(['nombre' => 'Carlos Mendoza', 'documento' => '4455667']);
    $cliente->save();
    $credito = confirmarVenta($this->admin, crearPedido($this->admin, $this->matriz,
        [['producto_id' => $this->producto->id, 'cantidad' => '5']], ['cliente_id' => $cliente->id]),
        ['metodo' => 'efectivo', 'en_cuotas' => true, 'cuota_inicial' => '0']);
    $devolucion = devolverUnidades($this->admin, $credito, '2', null);

    expect($devolucion->deuda_reducida)->toBe('40.00')->and($devolucion->reembolso)->toBe('0.00')
        ->and(VentasPeriodo::totales($this->empresa->id, Periodo::dia('2026-10-20')))
        ->toMatchArray(['valor_vendido' => '100.00', 'devoluciones' => '40.00', 'venta_neta' => '60.00']);
});

test('the sales tab shows the net sale and lists the returns of the period with their original sale', function () {
    momentoVentasNetas('2026-10-20 16:00');
    devolverUnidades($this->admin, $this->venta, '1');

    Livewire::test(Panel::class)
        ->set('pestana', 'ventas')->set('desde', '2026-10-20')->set('hasta', '2026-10-20')
        ->assertSee('Devoluciones del período')->assertSee('Venta neta')->assertSee('Bs −20,00')
        ->assertSee('Sin ventas confirmadas en el período.')
        ->set('vista', 'devoluciones')
        ->assertSee('DEV-00001')->assertSee('V-00001')->assertSee('Vendida el 19/10/2026')->assertSee('Producto defectuoso')
        ->set('desde', '2026-10-19')->set('hasta', '2026-10-19')
        ->assertDontSee('DEV-00001')->assertSee('Sin devoluciones en el período.');

    // Lunes: la venta sigue con su valor y avisa lo devuelto a hoy, sin reescribirse.
    Livewire::test(Panel::class)->set('pestana', 'ventas')->set('desde', '2026-10-19')->set('hasta', '2026-10-19')
        ->assertSee('V-00001')->assertSee('Bs 200,00')->assertSee('Devuelto a hoy Bs 20,00');
});

test('the return CSV uses the same filters and the sales CSV labels the returned amount as of today', function () {
    momentoVentasNetas('2026-10-20 16:00');
    devolverUnidades($this->admin, $this->venta, '1');

    $devoluciones = $this->get(route('reportes.exportar', ['pestana' => 'ventas', 'vista' => 'devoluciones', 'desde' => '2026-10-20', 'hasta' => '2026-10-20']));
    $devoluciones->assertOk()->assertDownload('devoluciones-2026-10-20_2026-10-20.csv');
    expect($devoluciones->streamedContent())->toContain('DEV-00001;V-00001;19/10/2026;"Casa Matriz"')->toContain(';20.00;0.00;20.00;Efectivo');

    $vacio = $this->get(route('reportes.exportar', ['pestana' => 'ventas', 'vista' => 'devoluciones', 'desde' => '2026-10-19', 'hasta' => '2026-10-19']))->streamedContent();
    expect($vacio)->not->toContain('DEV-00001');

    $ventas = $this->get(route('reportes.exportar', ['pestana' => 'ventas', 'desde' => '2026-10-19', 'hasta' => '2026-10-19']))->streamedContent();
    expect($ventas)->toContain('Devuelto a hoy')->toContain(';200.00;20.00;');
});

test('returns follow the branch filter and never show another company', function () {
    momentoVentasNetas('2026-10-20 16:00');
    devolverUnidades($this->admin, $this->venta, '1');

    [$empresaB, $adminB] = empresaConAdministrador(['nombre' => 'Empresa B']);
    $this->actingAs($adminB);
    $sucursalB = Sucursal::factory()->create(['empresa_id' => $empresaB->id]);
    $productoB = productoDe($empresaB, ['precio_consumidor' => '35.00']);
    cargarStock($adminB, $sucursalB, $productoB, '5');
    $ventaB = confirmarVenta($adminB, crearPedido($adminB, $sucursalB, [['producto_id' => $productoB->id, 'cantidad' => '2']]), ['metodo' => 'efectivo']);
    devolverUnidades($adminB, $ventaB, '1');

    $hoy = Periodo::dia('2026-10-20');
    expect(VentasPeriodo::totales($this->empresa->id, $hoy)['devoluciones'])->toBe('20.00')
        ->and(VentasPeriodo::totales($this->empresa->id, $hoy, $this->norte->id)['devoluciones'])->toBe('0.00')
        ->and(VentasPeriodo::totales($this->empresa->id, $hoy, $sucursalB->id)['devoluciones'])->toBe('0.00')
        ->and(VentasPeriodo::totales($empresaB->id, $hoy)['devoluciones'])->toBe('35.00');

    $this->actingAs($this->admin);
    Livewire::test(Panel::class)->set('pestana', 'ventas')->set('vista', 'devoluciones')
        ->set('desde', '2026-10-20')->set('hasta', '2026-10-20')
        ->assertSee('Bs 20,00')->assertDontSee('Bs 35,00')->assertDontSee('Empresa B');
});
