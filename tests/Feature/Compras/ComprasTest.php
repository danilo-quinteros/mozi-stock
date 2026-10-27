<?php

use App\Domain\Compras\Actions\RegistrarCompra;
use App\Domain\Reportes\Consultas\Cobros;
use App\Domain\Reportes\Periodo;
use App\Livewire\Compras\Lista;
use App\Livewire\Compras\NuevaCompra;
use App\Livewire\Inventario\Movimientos;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\Egreso;
use App\Models\MovimientoCaja;
use App\Models\OperacionInventario;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Compras pagadas (§ 3.7, T22 y prueba V3 n.º 6): el stock entra una vez, el dinero sale una
 * sola vez de caja y nunca se duplica como egreso ni como ajuste.
 */

function comprar(User $admin, Sucursal $sucursal, array $lineas, array $extra = []): Compra
{
    return app(RegistrarCompra::class)->ejecutar($admin, $extra + [
        'sucursal_id' => $sucursal->id, 'proveedor_id' => null, 'referencia' => null, 'lineas' => $lineas,
        'metodo' => 'efectivo', 'efectivo' => null, 'electronico' => null, 'clave' => (string) Str::uuid(),
    ]);
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->arroz = productoDe($this->empresa, ['nombre' => 'Arroz 5kg']);
    $this->aceite = productoDe($this->empresa, ['nombre' => 'Aceite 1L']);
    $this->proveedor = new Proveedor(['nombre' => 'Distribuidora Oriente']);
    $this->proveedor->empresa_id = $this->empresa->id;
    $this->proveedor->save();
    $this->actingAs($this->admin);
});

test('T22 a paid purchase of Bs 500 adds stock and reports a single cash outflow of Bs 500', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 11:00', 'America/La_Paz'));
    $compra = comprar($this->admin, $this->sucursal, [
        $this->arroz->id => ['cantidad' => '10', 'costo' => '28.50'],   // 285,00
        $this->aceite->id => ['cantidad' => '20', 'costo' => '10.75'],  // 215,00
    ], ['proveedor_id' => $this->proveedor->id, 'referencia' => 'Factura 123']);

    expect($compra->total)->toBe('500.00')
        ->and($compra->codigo())->toBe('COM-00001')
        ->and(stockDe($this->sucursal, $this->arroz))->toBe('10.000')
        ->and(stockDe($this->sucursal, $this->aceite))->toBe('20.000')
        ->and(CompraItem::query()->orderBy('orden')->pluck('subtotal')->all())->toBe(['285.00', '215.00']);

    $caja = MovimientoCaja::query()->sole();
    expect($caja->sentido)->toBe('egreso')
        ->and($caja->metodo)->toBe('efectivo')
        ->and($caja->monto)->toBe('500.00')
        ->and($caja->origen_tipo)->toBe('compra')
        ->and($caja->origen_id)->toBe($compra->id);

    // V3-6: ni egreso manual ni ajuste; una sola operación de inventario tipo compra.
    expect(Egreso::query()->count())->toBe(0)
        ->and(OperacionInventario::query()->pluck('tipo')->map->value->all())->toBe(['compra']);

    $dia = Cobros::resumen($this->empresa->id, Periodo::dia('2026-10-22'));
    expect($dia['egresos'])->toBe('500.00')->and($dia['total'])->toBe('0.00')->and($dia['neto'])->toBe('-500.00')
        ->and(Cobros::resumen($this->empresa->id, Periodo::dia('2026-10-21'))['egresos'])->toBe('0.00');
});

test('mixed payment must add up exactly to the total and keeps one row per method', function () {
    $lineas = [$this->arroz->id => ['cantidad' => '2', 'costo' => '50']];

    expect(fn () => comprar($this->admin, $this->sucursal, $lineas, ['metodo' => 'mixto', 'efectivo' => '40', 'electronico' => '50']))
        ->toThrow(ValidationException::class);
    expect(Compra::query()->count())->toBe(0)->and(stockDe($this->sucursal, $this->arroz))->toBe('0.000');

    $compra = comprar($this->admin, $this->sucursal, $lineas, ['metodo' => 'mixto', 'efectivo' => '40', 'electronico' => '60']);

    expect($compra->monto_efectivo)->toBe('40.00')->and($compra->monto_electronico)->toBe('60.00')
        ->and(MovimientoCaja::query()->orderBy('metodo')->pluck('monto', 'metodo')->all())->toBe(['efectivo' => '40.00', 'electronico' => '60.00']);
});

test('T19 repeating the same purchase key registers it only once', function () {
    $clave = (string) Str::uuid();
    $lineas = [$this->arroz->id => ['cantidad' => '5', 'costo' => '10']];
    $primera = comprar($this->admin, $this->sucursal, $lineas, ['clave' => $clave]);
    $segunda = comprar($this->admin, $this->sucursal, $lineas, ['clave' => $clave]);

    expect($segunda->id)->toBe($primera->id)
        ->and(Compra::query()->count())->toBe(1)
        ->and(stockDe($this->sucursal, $this->arroz))->toBe('5.000')
        ->and(MovimientoCaja::query()->count())->toBe(1);
});

test('an invalid line saves nothing at all', function () {
    expect(fn () => comprar($this->admin, $this->sucursal, [
        $this->arroz->id => ['cantidad' => '5', 'costo' => '10'],
        $this->aceite->id => ['cantidad' => '3', 'costo' => '-1'],
    ]))->toThrow(ValidationException::class);

    expect(fn () => comprar($this->admin, $this->sucursal, [$this->arroz->id => ['cantidad' => '0', 'costo' => '10']]))
        ->toThrow(ValidationException::class);

    // Total Bs 0: no es una compra pagada (para eso existe Ajustes → Entrada).
    expect(fn () => comprar($this->admin, $this->sucursal, [$this->arroz->id => ['cantidad' => '5', 'costo' => '0']]))
        ->toThrow(ValidationException::class);

    expect(Compra::query()->count())->toBe(0)
        ->and(OperacionInventario::query()->count())->toBe(0)
        ->and(MovimientoCaja::query()->count())->toBe(0)
        ->and(stockDe($this->sucursal, $this->arroz))->toBe('0.000');
});

test('T02 products, suppliers and branches of another company are rejected', function () {
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $sucursalB = Sucursal::factory()->create(['empresa_id' => $empresaB->id]);
    $productoB = productoDe($empresaB);
    $proveedorB = new Proveedor(['nombre' => 'Proveedor B']);
    $proveedorB->save();
    $compraB = comprar($adminB, $sucursalB, [$productoB->id => ['cantidad' => '1', 'costo' => '9']]);
    $this->actingAs($this->admin);

    expect(fn () => comprar($this->admin, $this->sucursal, [$productoB->id => ['cantidad' => '1', 'costo' => '9']]))->toThrow(ValidationException::class)
        ->and(fn () => comprar($this->admin, $this->sucursal, [$this->arroz->id => ['cantidad' => '1', 'costo' => '9']], ['proveedor_id' => $proveedorB->id]))->toThrow(ValidationException::class)
        ->and(fn () => comprar($this->admin, $sucursalB, [$this->arroz->id => ['cantidad' => '1', 'costo' => '9']]))->toThrow(ValidationException::class);

    expect(Compra::query()->count())->toBe(0);
    $this->get(route('compras.ver', $compraB->id))->assertNotFound();
    Livewire::test(Lista::class)->assertDontSee('COM-00001');
    expect($this->get(route('compras.exportar'))->streamedContent())->not->toContain('COM-00001');
});

test('sellers cannot register or see purchases', function () {
    $vendedor = User::factory()->vendedor($this->sucursal)->create();
    $this->actingAs($vendedor);

    expect(fn () => comprar($vendedor, $this->sucursal, [$this->arroz->id => ['cantidad' => '1', 'costo' => '9']]))
        ->toThrow(AuthorizationException::class);
    $this->get(route('compras.index'))->assertForbidden();
    $this->get(route('compras.crear'))->assertForbidden();
    $this->get(route('compras.exportar'))->assertForbidden();
    $this->get(route('proveedores.index'))->assertForbidden();
});

test('the new purchase screen confirms once even when the button is pressed twice', function () {
    $pantalla = Livewire::test(NuevaCompra::class)
        ->set('proveedor_id', (string) $this->proveedor->id)
        ->call('agregarProducto', $this->arroz->id)
        ->call('agregarProducto', $this->arroz->id)
        ->set('lineas.0.costo', '12,50')
        ->assertSee('Bs 25,00')
        ->call('revisar')
        ->assertSet('confirmando', true);

    $pantalla->call('confirmar')->assertRedirect(route('compras.ver', Compra::query()->sole()));
    $pantalla->call('confirmar');

    expect(Compra::query()->count())->toBe(1)
        ->and(Compra::query()->sole()->total)->toBe('25.00')
        ->and(stockDe($this->sucursal, $this->arroz))->toBe('2.000')
        ->and(MovimientoCaja::query()->count())->toBe(1);

    $this->get(route('compras.ver', Compra::query()->sole()))->assertOk()->assertSee('COM-00001')->assertSee('Distribuidora Oriente');
    Livewire::test(Movimientos::class)->assertSee('Compra')->assertSee('COM-00001');
    Livewire::test(Lista::class)->assertSee('COM-00001')->assertSee('Bs 25,00');
});

test('purchase CSV uses the same filters as the screen', function () {
    $norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    comprar($this->admin, $this->sucursal, [$this->arroz->id => ['cantidad' => '1', 'costo' => '10']], ['proveedor_id' => $this->proveedor->id]);
    comprar($this->admin, $norte, [$this->aceite->id => ['cantidad' => '1', 'costo' => '20']]);

    $todas = $this->get(route('compras.exportar'))->streamedContent();
    $norteCsv = $this->get(route('compras.exportar', ['sucursal' => $norte->id]))->streamedContent();
    $porProducto = $this->get(route('compras.exportar', ['q' => 'Arroz']))->streamedContent();

    expect(substr_count($todas, "\n"))->toBe(3)
        ->and($norteCsv)->toContain('COM-00002')->not->toContain('COM-00001')
        ->and($porProducto)->toContain('COM-00001')->toContain('"Distribuidora Oriente"')->not->toContain('COM-00002');
});

test('the purchase print view shows its lines and is isolated by company', function () {
    $compra = comprar($this->admin, $this->sucursal, [$this->arroz->id => ['cantidad' => '2', 'costo' => '28.50']],
        ['proveedor_id' => $this->proveedor->id]);

    $this->get(route('compras.imprimir', $compra))->assertOk()
        ->assertSee('COM-00001')->assertSee('Arroz 5kg')->assertSee('Distribuidora Oriente')->assertSee('Bs 57,00');

    [, $ajeno] = empresaConAdministrador();
    $this->actingAs($ajeno)->get(route('compras.imprimir', $compra))->assertNotFound();
});
