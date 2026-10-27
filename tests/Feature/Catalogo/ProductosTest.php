<?php

use App\Domain\Tenancy\Actions\CrearEmpresa;
use App\Livewire\Catalogo\ProductoFormulario;
use App\Livewire\Catalogo\Productos;
use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

// PNG real de 1x1 px: no depende de la extensión GD.
const PNG_MINIMO = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

function crearProductoDesdePantalla(array $campos): Testable
{
    $pantalla = Livewire::test(ProductoFormulario::class);

    foreach ($campos as $campo => $valor) {
        $pantalla->set($campo, $valor);
    }

    return $pantalla->call('guardar');
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    foreach (UnidadMedida::BASE as $base) {
        $unidad = new UnidadMedida($base);
        $unidad->empresa_id = $this->empresa->id;
        $unidad->save();
    }
    $this->unidad = UnidadMedida::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->where('nombre', 'Unidad')->sole();
    $this->actingAs($this->admin);
});

test('a product is created with three prices and no stock is assigned', function () {
    Sucursal::factory()->create(['empresa_id' => $this->empresa->id]);

    crearProductoDesdePantalla([
        'nombre' => 'Coca-Cola 2L', 'sku' => 'beb-001', 'codigo_barras' => '7751234567890',
        'unidad_medida_id' => (string) $this->unidad->id, 'stock_minimo' => '5',
        'precio_consumidor' => '13,00', 'precio_minorista' => '11.10', 'precio_mayorista' => '9,8',
    ])->assertHasNoErrors()->assertRedirect(route('productos.index'));

    $producto = Producto::query()->sole();
    expect($producto->sku)->toBe('BEB-001')
        ->and($producto->precio_consumidor)->toBe('13.00')
        ->and($producto->precio_minorista)->toBe('11.10')
        ->and($producto->precio_mayorista)->toBe('9.80')
        ->and($producto->stock_minimo)->toBe('5.000')
        ->and(DB::table('stock_niveles')->count())->toBe(0)
        ->and(Auditoria::query()->where('accion', 'producto.creado')->count())->toBe(1);
});

test('T01 two companies may use the same SKU but one company may not repeat it', function () {
    productoDe($this->empresa, ['sku' => 'BEB-001']);

    crearProductoDesdePantalla(['nombre' => 'Repetido', 'sku' => 'BEB-001', 'unidad_medida_id' => (string) $this->unidad->id, 'precio_consumidor' => '1'])
        ->assertHasErrors(['sku' => 'unique']);

    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    productoDe($empresaB, ['sku' => 'BEB-001']);

    expect(Producto::withoutGlobalScopes()->where('sku', 'BEB-001')->count())->toBe(2);
});

test('prices must be non-negative with at most two decimals', function (string $precio) {
    crearProductoDesdePantalla(['nombre' => 'X', 'sku' => 'X-1', 'unidad_medida_id' => (string) $this->unidad->id, 'precio_consumidor' => $precio])
        ->assertHasErrors('precio_consumidor');
    expect(Producto::query()->count())->toBe(0);
})->with(['-1', '1,234', 'abc', '']);

test('T02 category or unit of another company cannot be assigned', function () {
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $categoriaB = new Categoria(['nombre' => 'Ajena']);
    $categoriaB->save();
    $unidadB = new UnidadMedida(['nombre' => 'Ajena', 'abreviatura' => 'aj']);
    $unidadB->save();
    $this->actingAs($this->admin);

    crearProductoDesdePantalla(['nombre' => 'X', 'sku' => 'X-1', 'categoria_id' => (string) $categoriaB->id,
        'unidad_medida_id' => (string) $unidadB->id, 'precio_consumidor' => '1'])
        ->assertHasErrors(['categoria_id' => 'exists', 'unidad_medida_id' => 'exists']);
});

test('T02 products of another company are neither listed nor editable', function () {
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $ajeno = productoDe($empresaB, ['nombre' => 'Producto Ajeno']);
    $this->actingAs($this->admin);
    productoDe($this->empresa, ['nombre' => 'Producto Propio']);

    $this->get(route('productos.index'))->assertOk()->assertSee('Producto Propio')->assertDontSee('Producto Ajeno');
    $this->get(route('productos.editar', $ajeno))->assertNotFound();
    $this->get(route('productos.imagen', $ajeno))->assertNotFound();
});

test('sellers see products with the stock of their own branch but cannot edit', function () {
    $norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Norte']);
    $sur = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sur']);
    $producto = productoDe($this->empresa, ['nombre' => 'Leche 1L']);
    cargarStock($this->admin, $norte, $producto, '7');
    cargarStock($this->admin, $sur, $producto, '42');
    $vendedor = User::factory()->vendedor($norte)->create();

    $this->actingAs($vendedor);
    Livewire::test(Productos::class)
        ->set('sucursal', (string) $sur->id)
        ->assertSee('Leche 1L')->assertSee('Stock · Norte')->assertDontSee('Stock · Sur')
        ->assertDontSee('Nuevo producto');

    $this->get(route('productos.crear'))->assertForbidden();
    $this->get(route('productos.editar', $producto))->assertForbidden();
    expect($this->get(route('productos.exportar', ['sucursal' => $sur->id]))->streamedContent())
        ->toContain(';Norte;7.000;')->not->toContain(';Sur;');
});

test('the product image is stored privately per company and served only to it', function () {
    Storage::fake('local');

    crearProductoDesdePantalla([
        'nombre' => 'Con foto', 'sku' => 'FOTO-1', 'unidad_medida_id' => (string) $this->unidad->id,
        'precio_consumidor' => '5', 'imagen' => UploadedFile::fake()->createWithContent('original.png', base64_decode(PNG_MINIMO)),
    ])->assertHasNoErrors();

    $producto = Producto::query()->sole();
    expect($producto->imagen_path)->toStartWith("empresas/{$this->empresa->id}/productos/")
        ->not->toContain('original');
    Storage::disk('local')->assertExists($producto->imagen_path);

    $this->get(route('productos.imagen', $producto))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->actingAs(User::factory()->administrador(empresaConAdministrador()[0])->create());
    $this->get(route('productos.imagen', $producto))->assertNotFound();
});

test('files that are not images or exceed 3 MB are rejected', function () {
    Storage::fake('local');

    Livewire::test(ProductoFormulario::class)
        ->set('imagen', UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf'))
        ->assertHasErrors('imagen');
    Livewire::test(ProductoFormulario::class)
        ->set('imagen', UploadedFile::fake()->createWithContent('grande.png', base64_decode(PNG_MINIMO).str_repeat('0', 3200 * 1024)))
        ->assertHasErrors('imagen');
});

test('editing keeps stock untouched and stores per-branch minimums', function () {
    $sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id]);
    $producto = productoDe($this->empresa, ['nombre' => 'Café 250g', 'precio_consumidor' => '33.50']);
    cargarStock($this->admin, $sucursal, $producto, '4');

    Livewire::test(ProductoFormulario::class, ['producto' => $producto])
        ->assertSet('precio_consumidor', '33.50')
        ->set('precio_consumidor', '35')
        ->set("minimos.{$sucursal->id}", '6')
        ->call('guardar')
        ->assertHasNoErrors();

    expect($producto->refresh()->precio_consumidor)->toBe('35.00')
        ->and(stockDe($sucursal, $producto))->toBe('4.000')
        ->and((string) DB::table('stock_niveles')->where('sucursal_id', $sucursal->id)->value('stock_minimo'))->toBe('6.000');

    Livewire::test(Productos::class)->assertSee('Café 250g')->assertSee('Stock bajo');
});

test('new companies start with the base units of measure', function () {
    $superadmin = User::factory()->superadmin()->create();
    $this->actingAs($superadmin);

    app(CrearEmpresa::class)->ejecutar($superadmin, ['nombre' => 'Nueva'], [
        'name' => 'Admin', 'email' => 'nueva@example.test', 'password' => 'ClaveSegura12345',
    ]);

    $empresa = Empresa::query()->where('nombre', 'Nueva')->sole();
    expect(UnidadMedida::withoutGlobalScopes()->where('empresa_id', $empresa->id)->pluck('nombre')->sort()->values()->all())
        ->toBe(['Caja', 'Gramo', 'Kilogramo', 'Litro', 'Unidad']);
});

test('categories and units are managed by administrators only', function () {
    Livewire::test(Productos::class)
        ->call('nuevaCategoria')->set('categoriaNombre', 'Bebidas')->call('guardarCategoria')->assertHasNoErrors()
        ->call('nuevaCategoria')->set('categoriaNombre', 'Bebidas')->call('guardarCategoria')->assertHasErrors(['categoriaNombre' => 'unique'])
        ->call('nuevaUnidad')->set('unidadNombre', 'Paquete')->set('unidadAbreviatura', 'paq.')->call('guardarUnidad')->assertHasNoErrors();

    expect(Categoria::query()->pluck('nombre')->all())->toBe(['Bebidas'])
        ->and(UnidadMedida::query()->where('nombre', 'Paquete')->exists())->toBeTrue();

    $vendedor = User::factory()->vendedor(Sucursal::factory()->create(['empresa_id' => $this->empresa->id]))->create();
    $this->actingAs($vendedor);
    Livewire::test(Productos::class)->call('nuevaCategoria')->assertForbidden();
});

test('unidades de medida admiten codigo de unidad sin para empresas con siat habilitado', function () {
    $this->empresa->forceFill(['siat_habilitado' => true])->save();

    Livewire::test(Productos::class)
        ->call('nuevaUnidad')
        ->set('unidadNombre', 'Bidón')
        ->set('unidadAbreviatura', 'bdn.')
        ->set('unidadMedidaSin', '24')
        ->call('guardarUnidad')
        ->assertHasNoErrors();

    $unidad = UnidadMedida::query()->where('nombre', 'Bidón')->sole();
    expect($unidad->unidad_medida_sin)->toBe(24);

    Livewire::test(Productos::class)
        ->set('pestana', 'unidades')
        ->assertSeeHtml('Unidad SIN')
        ->assertSeeHtml('24');
});
