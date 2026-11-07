<?php

use App\Domain\Tenancy\Actions\CrearEmpresa;
use App\Enums\Rubro;
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

    app(CrearEmpresa::class)->ejecutar($superadmin, ['nombre' => 'Nueva', 'rubro' => Rubro::General], [
        'name' => 'Admin', 'email' => 'nueva@example.test', 'password' => 'ClaveSegura12345',
    ]);

    $empresa = Empresa::query()->where('nombre', 'Nueva')->sole();
    expect(UnidadMedida::withoutGlobalScopes()->where('empresa_id', $empresa->id)->pluck('nombre')->sort()->values()->all())
        ->toBe(['Caja', 'Gramo', 'Kilogramo', 'Litro', 'Mililitro', 'Unidad']);
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

test('un producto guarda nombre y contenido por separado y los muestra unidos en todo el sistema', function () {
    $gramo = UnidadMedida::withoutGlobalScopes()
        ->where('empresa_id', $this->empresa->id)
        ->where('abreviatura', 'g')
        ->sole();

    crearProductoDesdePantalla([
        'nombre' => 'Desengrasante',
        'contenido' => '350',
        'sku' => 'DES-350',
        'unidad_medida_id' => (string) $gramo->id,
        'precio_consumidor' => '25.00',
    ])->assertHasNoErrors()->assertRedirect(route('productos.index'));

    $producto = Producto::query()->where('sku', 'DES-350')->sole();

    // En base de datos se guardan por separado:
    expect(DB::table('productos')->where('id', $producto->id)->value('nombre'))->toBe('Desengrasante')
        ->and(DB::table('productos')->where('id', $producto->id)->value('contenido'))->toBe('350.000')
        ->and($producto->nombre_base)->toBe('Desengrasante')
        ->and($producto->nombre)->toBe('Desengrasante 350 g')
        ->and($producto->nombre_completo)->toBe('Desengrasante 350 g');

    // En el catálogo y listado se muestra unido automáticamente:
    Livewire::test(Productos::class)
        ->assertSee('Desengrasante 350 g');

    // En edición, el formulario recupera nombre base y contenido por separado:
    Livewire::test(ProductoFormulario::class, ['producto' => $producto])
        ->assertSet('nombre', 'Desengrasante')
        ->assertSet('contenido', '350')
        ->assertSee('Desengrasante')
        ->assertSee('350 g');
});

test('el campo contenido es opcional y muestra solo el nombre si no se especifica', function () {
    crearProductoDesdePantalla([
        'nombre' => 'Detergente Simple',
        'sku' => 'DET-001',
        'unidad_medida_id' => (string) $this->unidad->id,
        'precio_consumidor' => '10.00',
    ])->assertHasNoErrors();

    $producto = Producto::query()->where('sku', 'DET-001')->sole();

    expect(DB::table('productos')->where('id', $producto->id)->value('contenido'))->toBeNull()
        ->and($producto->nombre)->toBe('Detergente Simple')
        ->and($producto->nombre_base)->toBe('Detergente Simple');
});

test('formulario muestra selector de como se vende campo contenido y vista previa dinamica', function () {
    $mililitro = UnidadMedida::withoutGlobalScopes()
        ->where('empresa_id', $this->empresa->id)
        ->where('abreviatura', 'ml')
        ->sole();

    $form = Livewire::test(ProductoFormulario::class)
        ->assertSeeHtml('Contenido / Presentación')
        ->assertSeeHtml('placeholder="350"')
        ->assertSee('¿Cómo se vende este producto?')
        ->assertSee('Por unidad')
        ->assertSee('Por peso o medida')
        ->assertSee('se vende por unidades.')
        ->assertSeeHtml('Vista previa del nombre completo');

    // Inicialmente con el campo nombre vacío, no se muestra nada en la vista previa
    expect($form->get('vistaPreviaNombreBase'))->toBe('')
        ->and($form->get('vistaPreviaContenido'))->toBe('')
        ->and($form->get('vistaPreviaNombreCompleto'))->toBe('')
        ->and($form->get('tipo_venta'))->toBe('unidad');

    // Cambiar a por peso o medida actualiza el texto de ejemplo
    $form->set('tipo_venta', 'medida')
        ->assertSee('se vende por peso o medida.');

    // En tipo de venta por unidad, contenido y unidad actualizan la vista previa calculada (en dos colores)
    $form->set('tipo_venta', 'unidad')
        ->set('nombre', 'Desengrasante')
        ->set('contenido', '350')
        ->set('unidad_medida_id', (string) $mililitro->id)
        ->assertSee('Desengrasante')
        ->assertSee('350 ml');

    // Al cambiar a por peso o medida, el contenido se oculta
    $form->set('tipo_venta', 'medida')
        ->assertSet('vistaPreviaContenido', '')
        ->assertSee('se vende por peso o medida.');

    // Si el nombre se vacía, la vista previa vuelve a quedar completamente vacía
    $form->set('nombre', '')
        ->assertSet('vistaPreviaNombreBase', '')
        ->assertSet('vistaPreviaContenido', '')
        ->assertSet('vistaPreviaNombreCompleto', '');

    // Al restaurar nombre y borrar contenido, la vista previa vuelve a mostrar solo el nombre
    $form->set('nombre', 'Desengrasante')
        ->set('contenido', '')
        ->assertSee('Desengrasante')
        ->assertSet('vistaPreviaContenido', '');
});

test('permite crear producto con tipo de venta por unidad o por peso o medida', function () {
    $kilogramo = UnidadMedida::withoutGlobalScopes()
        ->where('empresa_id', $this->empresa->id)
        ->where('abreviatura', 'kg')
        ->sole();

    Livewire::test(ProductoFormulario::class)
        ->set('nombre', 'Queso Criollo')
        ->set('tipo_venta', 'medida')
        ->set('unidad_medida_id', (string) $kilogramo->id)
        ->set('precio_consumidor', '45.00')
        ->call('guardar')
        ->assertHasNoErrors()
        ->assertRedirect(route('productos.index'));

    $producto = Producto::query()->where('nombre', 'Queso Criollo')->sole();
    expect($producto->tipo_venta)->toBe('medida')
        ->and($producto->esPorMedida())->toBeTrue()
        ->and($producto->esPorUnidad())->toBeFalse();
});

test('permite crear producto sin sku de forma opcional', function () {
    $litro = UnidadMedida::withoutGlobalScopes()
        ->where('empresa_id', $this->empresa->id)
        ->where('abreviatura', 'L')
        ->sole();

    Livewire::test(ProductoFormulario::class)
        ->set('nombre', 'Agua Pura')
        ->set('sku', '')
        ->set('codigo_barras', '7759998881110')
        ->set('unidad_medida_id', (string) $litro->id)
        ->set('precio_consumidor', '12.50')
        ->call('guardar')
        ->assertHasNoErrors()
        ->assertRedirect(route('productos.index'));

    $producto = Producto::query()->where('codigo_barras', '7759998881110')->sole();
    expect($producto->sku)->toBeNull()
        ->and($producto->nombre)->toBe('Agua Pura')
        ->and($producto->codigo_barras)->toBe('7759998881110');
});

test('permite editar un producto que tiene sku nulo sin error de tipo', function () {
    $litro = UnidadMedida::withoutGlobalScopes()
        ->where('empresa_id', $this->empresa->id)
        ->where('abreviatura', 'L')
        ->sole();

    $producto = productoDe($this->empresa, [
        'nombre' => 'Jugo Natural',
        'sku' => null,
        'codigo_barras' => '7758887776660',
        'unidad_medida_id' => $litro->id,
        'precio_consumidor' => '15.00',
    ]);

    expect($producto->sku)->toBeNull();

    Livewire::test(ProductoFormulario::class, ['producto' => $producto])
        ->assertSet('nombre', 'Jugo Natural')
        ->assertSet('sku', '')
        ->assertSet('codigo_barras', '7758887776660')
        ->set('nombre', 'Jugo Natural Modificado')
        ->call('guardar')
        ->assertHasNoErrors()
        ->assertRedirect(route('productos.index'));

    expect($producto->fresh()->nombre_base)->toBe('Jugo Natural Modificado')
        ->and($producto->fresh()->sku)->toBeNull();
});

test('filtro con stock y sin stock filtra adecuadamente en el listado y en exportacion csv', function () {
    $sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Central']);
    $conStock = productoDe($this->empresa, ['nombre' => 'Arroz Grano de Oro']);
    $sinStock = productoDe($this->empresa, ['nombre' => 'Fideo La Suprema']);

    cargarStock($this->admin, $sucursal, $conStock, '20');

    // Estado por defecto: se ven ambos
    Livewire::test(Productos::class)
        ->assertSet('filtroStock', '')
        ->assertSee('Arroz Grano de Oro')
        ->assertSee('Fideo La Suprema')
        ->set('filtroStock', 'con_stock')
        ->assertSee('Arroz Grano de Oro')
        ->assertDontSee('Fideo La Suprema')
        ->set('filtroStock', 'sin_stock')
        ->assertSee('Fideo La Suprema')
        ->assertDontSee('Arroz Grano de Oro');

    // Exportación CSV con filtro
    $csvConStock = $this->get(route('productos.exportar', ['sucursal' => $sucursal->id, 'stock' => 'con_stock']))->streamedContent();
    expect($csvConStock)->toContain('Arroz Grano de Oro')->not->toContain('Fideo La Suprema');

    $csvSinStock = $this->get(route('productos.exportar', ['sucursal' => $sucursal->id, 'stock' => 'sin_stock']))->streamedContent();
    expect($csvSinStock)->toContain('Fideo La Suprema')->not->toContain('Arroz Grano de Oro');
});

test('administrador puede editar el precio consumidor en linea desde el listado', function () {
    Sucursal::factory()->create(['empresa_id' => $this->empresa->id]);
    $producto = productoDe($this->empresa, ['nombre' => 'Aceite Fino 1L', 'precio_consumidor' => '10.00']);

    Livewire::test(Productos::class)
        ->call('iniciarEdicionPrecio', $producto->id)
        ->assertSet('editandoProductoId', $producto->id)
        ->assertSet('editandoPrecio', '10')
        ->set('editandoPrecio', '14.50')
        ->call('guardarPrecio')
        ->assertHasNoErrors()
        ->assertSet('editandoProductoId', null)
        ->assertSet('editandoPrecio', '');

    expect($producto->fresh()->precio_consumidor)->toBe('14.50')
        ->and(Auditoria::query()
            ->where('accion', 'producto.actualizado')
            ->where('entidad_id', $producto->id)
            ->exists())->toBeTrue();
});

test('la edicion en linea valida formatos invalidos y valores negativos', function () {
    Sucursal::factory()->create(['empresa_id' => $this->empresa->id]);
    $producto = productoDe($this->empresa, ['precio_consumidor' => '10.00']);

    Livewire::test(Productos::class)
        ->call('iniciarEdicionPrecio', $producto->id)
        ->set('editandoPrecio', '-2.50')
        ->call('guardarPrecio')
        ->assertHasErrors(['editandoPrecio'])
        ->set('editandoPrecio', 'abc')
        ->call('guardarPrecio')
        ->assertHasErrors(['editandoPrecio'])
        ->set('editandoPrecio', '10.555')
        ->call('guardarPrecio')
        ->assertHasErrors(['editandoPrecio'])
        ->call('cancelarEdicionPrecio')
        ->assertSet('editandoProductoId', null);

    expect($producto->fresh()->precio_consumidor)->toBe('10.00');
});

test('vendedor no puede editar precio consumidor en linea y recibe 403', function () {
    $sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id]);
    $producto = productoDe($this->empresa, ['precio_consumidor' => '10.00']);
    $vendedor = User::factory()->vendedor($sucursal)->create();

    $this->actingAs($vendedor);

    Livewire::test(Productos::class)
        ->assertDontSee('Clic para editar precio')
        ->call('iniciarEdicionPrecio', $producto->id)
        ->assertForbidden();

    Livewire::test(Productos::class)
        ->call('guardarPrecio')
        ->assertForbidden();

    expect($producto->fresh()->precio_consumidor)->toBe('10.00');
});

test('administrador no puede editar precio de producto de otra empresa', function () {
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $productoAjeno = productoDe($empresaB, ['precio_consumidor' => '25.00']);

    $this->actingAs($this->admin);

    Livewire::test(Productos::class)
        ->call('iniciarEdicionPrecio', $productoAjeno->id)
        ->assertNotFound();
});
