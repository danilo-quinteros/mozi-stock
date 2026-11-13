<?php

use App\Domain\Catalogo\Actions\GuardarProducto;
use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Enums\Capacidad;
use App\Enums\Rubro;
use App\Enums\TipoOperacionInventario;
use App\Livewire\Catalogo\ProductoFormulario;
use App\Models\Lote;
use App\Models\OperacionInventario;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Stock inicial al crear un producto (decisión del propietario, 10/11/2026): una entrada «Inventario inicial» en la
 * sucursal elegida, en la misma transacción que el alta. No es una compra: no toca caja, compras ni cuentas por pagar.
 * Las combinaciones de tallas y colores se prueban en TallasColoresTest.
 */

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    foreach (UnidadMedida::BASE as $base) {
        $unidad = new UnidadMedida($base);
        $unidad->empresa_id = $this->empresa->id;
        $unidad->save();
    }
    $this->unidad = UnidadMedida::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->where('admite_decimales', false)->orderBy('id')->firstOrFail();
    $this->kilo = UnidadMedida::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->where('abreviatura', 'kg')->sole();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    $this->actingAs($this->admin);
});

/** Formulario «Nuevo producto» listo para guardar, con stock inicial marcado. */
function siFormulario(array $campos = []): Testable
{
    $pantalla = Livewire::test(ProductoFormulario::class)
        ->set('nombre', 'Escoba')->set('unidad_medida_id', (string) test()->unidad->id)->set('precio_consumidor', '20')
        ->set('con_stock_inicial', true);

    foreach ($campos as $campo => $valor) {
        $pantalla->set($campo, $valor);
    }

    return $pantalla;
}

test('the form is ordered by sections and offers the initial stock only when creating a product that controls stock', function () {
    Livewire::test(ProductoFormulario::class)
        ->assertSeeInOrder(['Datos del producto', 'Precios de venta', 'Inventario', 'Cargar stock inicial', 'Guardar producto'])
        ->assertSeeHtml('data-test="producto-stock-inicial"')
        ->assertDontSeeHtml('data-test="producto-stock-inicial-campos"')
        ->set('con_stock_inicial', true)
        ->assertSeeHtml('data-test="producto-stock-inicial-campos"')->assertSeeHtml('id="stock_cantidad"')
        // Con dos sucursales nadie queda elegida por defecto.
        ->assertSet('stock_sucursal_id', '')
        // Sin control de stock no hay stock que cargar: la casilla desaparece y se desmarca.
        ->set('controla_stock', false)
        ->assertSet('con_stock_inicial', false)->assertDontSeeHtml('data-test="producto-stock-inicial"');

    // Con una sola sucursal activa ya queda elegida.
    DB::table('sucursales')->where('id', $this->norte->id)->update(['activa' => false]);
    Livewire::test(ProductoFormulario::class)->assertSet('stock_sucursal_id', (string) $this->matriz->id);

    // Al editar no se ofrece: el stock de un producto existente cambia con compras, ajustes y traspasos.
    $producto = productoDe($this->empresa, ['nombre' => 'Trapeador']);
    Livewire::test(ProductoFormulario::class, ['producto' => $producto])
        ->assertDontSeeHtml('data-test="producto-stock-inicial"')
        ->assertSeeHtml('data-test="producto-stock-sucursales"')
        ->set('con_stock_inicial', true)->set('stock_sucursal_id', (string) $this->matriz->id)->set('stock_cantidad', '9')
        ->set('precio_consumidor', '30')->call('guardar')->assertHasNoErrors();

    expect(OperacionInventario::query()->count())->toBe(0)->and(stockDe($this->matriz, $producto))->toBe('0.000');
});

test('creating a product with initial stock records one «Inventario inicial» entry in the chosen branch and nothing in cash or purchases', function () {
    siFormulario(['stock_sucursal_id' => (string) $this->norte->id, 'stock_cantidad' => '24', 'sku' => 'ESC-01'])
        ->call('guardar')->assertHasNoErrors()->assertRedirect(route('productos.index'));

    $producto = Producto::query()->sole();
    $operacion = OperacionInventario::query()->sole();

    expect(stockDe($this->norte, $producto))->toBe('24.000')
        ->and(stockDe($this->matriz, $producto))->toBe('0.000')
        ->and($operacion->tipo)->toBe(TipoOperacionInventario::Entrada)
        ->and($operacion->motivo)->toBe('inventario_inicial')
        ->and($operacion->sucursal_id)->toBe($this->norte->id)
        ->and($operacion->usuario_id)->toBe($this->admin->id)
        ->and($operacion->nota)->toBe('Stock inicial de Escoba')
        ->and(DB::table('movimientos_stock')->where('operacion_id', $operacion->id)->get(['producto_id', 'cantidad', 'saldo_anterior', 'saldo_posterior'])
            ->map(fn ($m) => (array) $m)->all())
        ->toBe([['producto_id' => $producto->id, 'cantidad' => '24.000', 'saldo_anterior' => '0.000', 'saldo_posterior' => '24.000']])
        ->and(DB::table('movimientos_caja')->count())->toBe(0)
        ->and(DB::table('compras')->count())->toBe(0)
        ->and(DB::table('cuentas_pagar')->count())->toBe(0)
        ->and(session('aviso'))->toBe('Producto ESC-01 creado con su stock inicial en Sucursal Norte.');
});

test('an invalid initial stock saves nothing: neither the product nor the entry', function () {
    auth()->logout();
    $otra = Sucursal::factory()->create(['empresa_id' => empresaConAdministrador()[0]->id, 'nombre' => 'Sucursal ajena']);
    $this->actingAs($this->admin);

    // Sin sucursal ni cantidad.
    siFormulario()->call('guardar')->assertHasErrors(['stock_sucursal_id' => 'required', 'stock_cantidad' => 'required']);
    // Sucursal de otra empresa: el id del navegador no concede acceso.
    siFormulario(['stock_sucursal_id' => (string) $otra->id, 'stock_cantidad' => '5'])->call('guardar')->assertHasErrors(['stock_sucursal_id']);
    // Cero o negativo.
    siFormulario(['stock_sucursal_id' => (string) $this->matriz->id, 'stock_cantidad' => '0'])->call('guardar')->assertHasErrors(['stock_inicial']);
    siFormulario(['stock_sucursal_id' => (string) $this->matriz->id, 'stock_cantidad' => '-3'])->call('guardar')->assertHasErrors(['stock_cantidad']);
    // Decimales en un producto que se vende por unidades: el producto ya estaba guardado en la transacción y se deshace.
    siFormulario(['stock_sucursal_id' => (string) $this->matriz->id, 'stock_cantidad' => '1,5'])->call('guardar')
        ->assertHasErrors(['stock_inicial'])->assertSee('se mide en unidades enteras');

    expect(Producto::query()->count())->toBe(0)
        ->and(OperacionInventario::query()->count())->toBe(0)
        ->and(DB::table('stock_niveles')->count())->toBe(0);

    // Por peso (kg sin contenido) sí admite decimales.
    siFormulario(['nombre' => 'Queso Criollo', 'unidad_medida_id' => (string) $this->kilo->id, 'stock_sucursal_id' => (string) $this->matriz->id, 'stock_cantidad' => '12,750'])
        ->call('guardar')->assertHasNoErrors();
    expect(stockDe($this->matriz, Producto::query()->sole()))->toBe('12.750');
});

test('saving the same form twice creates one product and one entry', function () {
    $pantalla = siFormulario(['stock_sucursal_id' => (string) $this->matriz->id, 'stock_cantidad' => '10']);
    $clave = $pantalla->get('claveStock');

    $pantalla->call('guardar')->assertHasNoErrors();
    $pantalla->call('guardar')->assertHasNoErrors()->assertRedirect(route('productos.index'));

    expect(Str::isUuid($clave))->toBeTrue()
        ->and(Producto::query()->count())->toBe(1)
        ->and(OperacionInventario::query()->count())->toBe(1)
        ->and(stockDe($this->matriz, Producto::query()->sole()))->toBe('10.000');

    // En el servidor, repetir la clave choca con la operación existente y deshace el segundo producto.
    $datos = ['nombre' => 'Escoba', 'unidad_medida_id' => $this->unidad->id, 'precio_consumidor' => '20', 'controla_stock' => true];
    expect(fn () => app(GuardarProducto::class)->crear($this->admin, $datos, null, ['sucursal_id' => $this->matriz->id, 'cantidad' => '10', 'clave' => $clave]))
        ->toThrow(UniqueConstraintViolationException::class);
    expect(Producto::query()->count())->toBe(1)->and(OperacionInventario::query()->count())->toBe(1);
});

test('a product without stock control cannot carry initial stock, and a seller cannot use the action', function () {
    expect(fn () => app(GuardarProducto::class)->crear($this->admin,
        ['nombre' => 'Almuerzo', 'unidad_medida_id' => $this->unidad->id, 'precio_consumidor' => '25', 'controla_stock' => false], null,
        ['sucursal_id' => $this->matriz->id, 'cantidad' => '5', 'clave' => (string) Str::uuid()]))
        ->toThrow(ValidationException::class, 'Un producto sin control de stock no lleva stock inicial.');

    $vendedor = User::factory()->vendedor($this->matriz)->create();
    expect(fn () => app(GuardarProducto::class)->crear($vendedor,
        ['nombre' => 'Escoba', 'unidad_medida_id' => $this->unidad->id, 'precio_consumidor' => '20'], null,
        ['sucursal_id' => $this->matriz->id, 'cantidad' => '5', 'clave' => (string) Str::uuid()]))
        ->toThrow(AuthorizationException::class);

    expect(Producto::query()->count())->toBe(0)->and(OperacionInventario::query()->count())->toBe(0);
});

test('a product with lot and expiry asks for the lot number and expiry of its initial stock', function () {
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::Farmacia, [Capacidad::LotesVencimientos]);
    $this->travelTo(CarbonImmutable::parse('2027-01-15 10:00', 'America/La_Paz'));

    $pantalla = siFormulario(['nombre' => 'Amoxicilina 500 mg', 'stock_sucursal_id' => (string) $this->matriz->id, 'stock_cantidad' => '30'])
        ->assertSet('controla_vencimiento', true)
        ->assertSeeHtml('id="stock_lote"')->assertSeeHtml('id="stock_vencimiento"');

    $pantalla->call('guardar')->assertHasErrors(['stock_lote']);
    expect(Producto::query()->count())->toBe(0);

    $pantalla->set('stock_lote', 'L2405')->set('stock_vencimiento', '06/2028')->call('guardar')->assertHasNoErrors();

    $producto = Producto::query()->sole();
    $lote = Lote::query()->where('producto_id', $producto->id)->sole();
    expect($producto->controla_vencimiento)->toBeTrue()
        ->and(stockDe($this->matriz, $producto))->toBe('30.000')
        ->and($lote->numero)->toBe('L2405')
        ->and(substr((string) $lote->vencimiento, 0, 10))->toBe('2028-06-30')
        ->and((string) DB::table('stock_lotes')->where('lote_id', $lote->id)->where('sucursal_id', $this->matriz->id)->value('cantidad'))->toBe('30.000');
});
