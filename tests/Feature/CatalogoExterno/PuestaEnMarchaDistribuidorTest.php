<?php

use App\Domain\Caja\Actions\AbrirTurnoCaja;
use App\Domain\Catalogo\Actions\AsignarPreciosIniciales;
use App\Domain\CatalogoExterno\Actions\AgregarProductoExterno;
use App\Domain\CatalogoExterno\Actions\VincularEmpresaProveedor;
use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Enums\Capacidad;
use App\Enums\Rubro;
use App\Livewire\Catalogo\Productos;
use App\Livewire\Compras\NuevaCompra;
use App\Livewire\Inventario\NuevoAjuste;
use App\Livewire\Ventas\Pos;
use App\Models\Auditoria;
use App\Models\CatalogoExternoProducto;
use App\Models\Compra;
use App\Models\EmpresaProveedorExterno;
use App\Models\Producto;
use App\Models\ProductoVinculoExterno;
use App\Models\Proveedor;
use App\Models\ProveedorExterno;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Distribuidores de Cyclone que empiezan: agregar desde compras y ajustes lo que todavía no venden, poner el precio
 * de venta al comprar y no vender sin precio por olvido (aviso en Inicio, filtro «Sin precio» y confirmación en el POS).
 */

function catalogoCyclone(ProveedorExterno $proveedor, string $externoId, string $nombre, string $codigo): CatalogoExternoProducto
{
    return CatalogoExternoProducto::query()->create([
        'proveedor_externo_id' => $proveedor->id, 'externo_id' => $externoId, 'codigo' => $codigo,
        'nombre_original' => $nombre.' 350 g', 'nombre_limpio' => $nombre, 'contenido' => '350.000', 'unidad_sugerida' => 'g',
        'tipo_venta' => 'unidad', 'linea_nombre' => 'Cuidado personal', 'activo_origen' => true, 'disponible' => true, 'hash' => 'hash_'.$externoId,
    ]);
}

function unidadesBase(int $empresaId): void
{
    foreach (UnidadMedida::BASE as $base) {
        $unidad = new UnidadMedida($base);
        $unidad->empresa_id = $empresaId;
        $unidad->save();
    }
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->actingAs($this->admin);
    unidadesBase($this->empresa->id);
    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->proveedorCompra = new Proveedor(['nombre' => 'Cyclone Bolivia']);
    $this->proveedorCompra->empresa_id = $this->empresa->id;
    $this->proveedorCompra->save();

    $this->cyclone = ProveedorExterno::query()->firstOrCreate(['codigo' => 'cyclone'],
        ['nombre' => 'Cyclone', 'driver' => 'cyclone', 'base_url' => 'https://cyclone.test/api/v1', 'activo' => true]);
    $this->palta = catalogoCyclone($this->cyclone, '901', 'Shampoo de palta', 'CY-PALTA');
    $this->coco = catalogoCyclone($this->cyclone, '902', 'Shampoo de coco', 'CY-COCO');
    $this->superadmin = User::factory()->superadmin()->create();
    app(VincularEmpresaProveedor::class)->ejecutar($this->superadmin, $this->empresa, $this->cyclone, true);
});

function productoLocalDe(int $empresaId, CatalogoExternoProducto $externo): ?Producto
{
    $id = ProductoVinculoExterno::withoutGlobalScopes()->where('empresa_id', $empresaId)->where('externo_id', $externo->externo_id)->value('producto_id');

    return $id === null ? null : Producto::withoutGlobalScopes()->find($id);
}

// --- 1. Agregar desde Nueva compra y Nuevo ajuste ------------------------------------------------------------------

test('Nueva compra ofrece el producto de Cyclone que aún no se vende y lo agrega al catálogo y a la tabla', function () {
    $pantalla = Livewire::test(NuevaCompra::class)->set('busqueda', 'palta');

    expect($pantalla->instance()->sugerencias)->toBeEmpty()
        ->and($pantalla->instance()->sugerenciasExternas->pluck('nombre')->all())->toBe(['Shampoo de palta']);
    $pantalla->assertSee('Del catálogo Cyclone · aún no lo vendes')->assertSee('Agregar a mi catálogo')->assertDontSee('Producto no encontrado');

    $pantalla->call('agregarDesdeCatalogoExterno', $this->palta->id)->assertReturned(true);
    $producto = productoLocalDe($this->empresa->id, $this->palta);

    expect($producto)->not->toBeNull()
        ->and($producto->precio_consumidor)->toBe('0.00')
        ->and($producto->empresa_id)->toBe($this->empresa->id)
        ->and($pantalla->get('lineas'))->toHaveCount(1)
        ->and($pantalla->get('lineas.0.id'))->toBe($producto->id);

    // Doble clic: no crea otro producto; la misma línea suma 1.
    $pantalla->call('agregarDesdeCatalogoExterno', $this->palta->id);

    expect(Producto::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count())->toBe(1)
        ->and($pantalla->get('lineas.0.cantidad'))->toBe('2');

    // Ya está en el catálogo: deja de ofrecerse como externo y aparece entre los propios.
    $otra = Livewire::test(NuevaCompra::class)->set('busqueda', 'palta');
    expect($otra->instance()->sugerenciasExternas)->toBeEmpty()
        ->and($otra->instance()->sugerencias->pluck('nombre')->all())->toBe(['Shampoo de palta 350 g']);
});

test('Enter sin coincidencias propias avisa que el producto está en el catálogo de Cyclone', function () {
    Livewire::test(NuevaCompra::class)
        ->call('agregarDesdeBuscador', 'Shampoo de palta')
        ->assertReturned(false)
        ->assertSet('mensajeCaptura', '«Shampoo de palta» aún no está en tu catálogo, pero sí en el de Cyclone: elige «Agregar a mi catálogo».');
});

test('un producto que se dejó de vender se ofrece de nuevo y al agregarlo se reactiva sin duplicarse', function () {
    $producto = app(AgregarProductoExterno::class)->ejecutar($this->admin, $this->palta->id);
    $producto->forceFill(['activo' => false])->save();

    $pantalla = Livewire::test(NuevaCompra::class)->set('busqueda', 'palta');
    expect($pantalla->instance()->sugerenciasExternas->sole()->inactivo)->toBeTrue();
    $pantalla->assertSee('Lo dejaste de vender')->call('agregarDesdeCatalogoExterno', $this->palta->id)->assertReturned(true);

    expect($producto->fresh()->activo)->toBeTrue()
        ->and(Producto::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count())->toBe(1);
});

test('Nuevo ajuste ofrece Cyclone solo en ingresos y la compra de materia prima no lo ofrece', function () {
    $ajuste = Livewire::test(NuevoAjuste::class)->set('tipo', 'entrada')->set('busqueda', 'palta');
    expect($ajuste->instance()->sugerenciasExternas->pluck('nombre')->all())->toBe(['Shampoo de palta']);

    $ajuste->set('tipo', 'salida')->set('busqueda', 'palt');
    expect($ajuste->instance()->sugerenciasExternas)->toBeEmpty();
    $ajuste->call('agregarDesdeCatalogoExterno', $this->palta->id)->assertReturned(false);
    expect(productoLocalDe($this->empresa->id, $this->palta))->toBeNull();

    // «Ingreso de materia prima» existe con la capacidad Producción.
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::General, [Capacidad::Produccion]);
    $materiaPrima = Livewire::withQueryParams(['materia_prima' => 1])->test(NuevaCompra::class)->set('busqueda', 'palta');
    expect($materiaPrima->instance()->sugerenciasExternas)->toBeEmpty();
});

test('aislamiento: una empresa no vinculada o con el vínculo inactivo no ve ni agrega productos de Cyclone', function () {
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    unidadesBase($empresaB->id);

    $pantalla = Livewire::test(NuevaCompra::class)->set('busqueda', 'palta');
    expect($pantalla->instance()->sugerenciasExternas)->toBeEmpty();
    // Aunque mande el id a mano, no se agrega.
    $pantalla->call('agregarDesdeCatalogoExterno', $this->palta->id)->assertReturned(false);
    expect(fn () => app(AgregarProductoExterno::class)->ejecutar($adminB, $this->palta->id))->toThrow(ValidationException::class)
        ->and(Producto::withoutGlobalScopes()->where('empresa_id', $empresaB->id)->count())->toBe(0);

    // Vínculo de la empresa A desactivado por el superadmin: tampoco.
    $this->actingAs($this->admin);
    EmpresaProveedorExterno::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->update(['activo' => false]);
    expect(Livewire::test(NuevaCompra::class)->set('busqueda', 'palta')->instance()->sugerenciasExternas)->toBeEmpty()
        ->and(fn () => app(AgregarProductoExterno::class)->ejecutar($this->admin, $this->palta->id))->toThrow(ValidationException::class)
        ->and(Producto::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count())->toBe(0);
});

test('un vendedor no puede agregar productos de Cyclone ni poner precios iniciales', function () {
    $vendedor = User::factory()->vendedor($this->sucursal)->create();
    $producto = productoDe($this->empresa, ['precio_consumidor' => '0.00']);
    $this->actingAs($vendedor);

    expect(fn () => app(AgregarProductoExterno::class)->ejecutar($vendedor, $this->palta->id))->toThrow(AuthorizationException::class)
        ->and(fn () => app(AsignarPreciosIniciales::class)->ejecutar($vendedor, [$producto->id => '10']))->toThrow(AuthorizationException::class)
        ->and($producto->fresh()->precio_consumidor)->toBe('0.00');
});

// --- 3. Precio de venta en la compra ---------------------------------------------------------------------------------

test('la compra pone el precio de venta a un producto recién agregado, en la misma operación y con auditoría', function () {
    $pantalla = Livewire::test(NuevaCompra::class)
        ->set('proveedor_id', (string) $this->proveedorCompra->id)
        ->call('agregarDesdeCatalogoExterno', $this->palta->id)
        ->set('lineas.0.cantidad', '24')
        ->set('lineas.0.costo', '12,50')
        ->assertDontSee('no tiene precio de venta')
        ->assertDontSee('Margen sobre el costo')
        ->assertSee('Precio de venta (Bs)')
        ->set('lineas.0.precio', '18,50')
        ->call('revisar')
        ->assertSet('confirmando', true)
        ->assertSee('Precio de venta: Bs 18,50');

    $pantalla->call('confirmar')->assertHasNoErrors();
    $producto = productoLocalDe($this->empresa->id, $this->palta);

    expect(Compra::query()->sole()->total)->toBe('300.00')
        ->and(stockDe($this->sucursal, $producto))->toBe('24.000')
        ->and($producto->precio_consumidor)->toBe('18.50')
        ->and(Auditoria::query()->where('accion', 'producto.actualizado')->where('entidad_id', $producto->id)->sole()->despues['precio_consumidor'])->toBe('18.50');

    // Repetir la confirmación no registra otra compra ni vuelve a cambiar el precio.
    $pantalla->call('confirmar');
    expect(Compra::query()->count())->toBe(1)
        ->and(Auditoria::query()->where('accion', 'producto.actualizado')->where('entidad_id', $producto->id)->count())->toBe(1);
});

test('un precio inválido se marca al revisar y no se registra la compra', function () {
    Livewire::test(NuevaCompra::class)
        ->set('proveedor_id', (string) $this->proveedorCompra->id)
        ->call('agregarDesdeCatalogoExterno', $this->palta->id)
        ->set('lineas.0.costo', '10')
        ->set('lineas.0.precio', '0')
        ->call('revisar')
        ->assertHasErrors(['lineas.0.precio'])
        ->assertSet('confirmando', false)
        ->set('lineas.0.precio', '1,234')
        ->call('revisar')
        ->assertHasErrors(['lineas.0.precio']);

    expect(Compra::query()->count())->toBe(0);
});

test('nunca cambia un precio ya definido, ni el que otra persona puso entre la pantalla y la confirmación', function () {
    $conPrecio = productoDe($this->empresa, ['nombre' => 'Jabón', 'precio_consumidor' => '7.00']);
    $pantalla = Livewire::test(NuevaCompra::class)
        ->set('proveedor_id', (string) $this->proveedorCompra->id)
        ->call('agregarProducto', $conPrecio->id)
        ->call('agregarDesdeCatalogoExterno', $this->palta->id)
        ->set('lineas.0.costo', '5')
        ->set('lineas.0.precio', '99')   // forzado a mano: el producto ya tiene precio, se ignora
        ->set('lineas.1.costo', '10')
        ->set('lineas.1.precio', '15')
        ->call('revisar');

    $palta = productoLocalDe($this->empresa->id, $this->palta);
    $palta->forceFill(['precio_consumidor' => '20.00'])->save();   // otro administrador le puso precio

    $pantalla->call('confirmar')->assertHasNoErrors();

    expect(Compra::query()->count())->toBe(1)
        ->and($conPrecio->fresh()->precio_consumidor)->toBe('7.00')
        ->and($palta->fresh()->precio_consumidor)->toBe('20.00');
});

test('AsignarPreciosIniciales ignora productos de otra empresa aunque lleguen sus ids', function () {
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $ajeno = productoDe($empresaB, ['precio_consumidor' => '0.00']);
    $this->actingAs($this->admin);

    expect(app(AsignarPreciosIniciales::class)->ejecutar($this->admin, [$ajeno->id => '10']))->toBe(0)
        ->and(Producto::withoutGlobalScopes()->find($ajeno->id)->precio_consumidor)->toBe('0.00');
});

// --- 4. Sin precio: filtro, aviso en Inicio y POS --------------------------------------------------------------------

test('Productos filtra «Sin precio» y el CSV respeta el filtro', function () {
    productoDe($this->empresa, ['nombre' => 'Jabón con precio', 'precio_consumidor' => '7.00']);
    productoDe($this->empresa, ['nombre' => 'Crema sin precio', 'precio_consumidor' => '0.00']);

    Livewire::test(Productos::class)
        ->assertSee('Jabón con precio')
        ->set('precio', 'sin_precio')
        ->assertSee('Crema sin precio')
        ->assertDontSee('Jabón con precio');

    $csv = $this->get(route('productos.exportar', ['precio' => 'sin_precio']))->assertOk()->streamedContent();
    expect($csv)->toContain('Crema sin precio')->not->toContain('Jabón con precio');
});

test('Inicio avisa al administrador cuántos productos activos no tienen precio; el vendedor no lo ve', function () {
    guiaTerminada($this->empresa);
    productoDe($this->empresa, ['precio_consumidor' => '0.00']);
    productoDe($this->empresa, ['precio_consumidor' => '0.00']);
    productoDe($this->empresa, ['precio_consumidor' => '0.00', 'activo' => false]);
    productoDe($this->empresa, ['precio_consumidor' => '9.00']);

    $this->get(route('dashboard'))->assertOk()
        ->assertSee('2 productos activos no tienen precio de venta')
        ->assertSee(route('productos.index', ['precio' => 'sin_precio']), false);

    $this->actingAs(User::factory()->vendedor($this->sucursal)->create());
    $this->get(route('dashboard'))->assertOk()->assertDontSee('no tienen precio de venta');
});

test('el POS pide confirmar antes de vender a Bs 0,00 y solo vende al confirmarlo', function () {
    $sinPrecio = productoDe($this->empresa, ['nombre' => 'Shampoo regalo', 'precio_consumidor' => '0.00']);
    $conPrecio = productoDe($this->empresa, ['nombre' => 'Jabón', 'precio_consumidor' => '7.00']);
    cargarStock($this->admin, $this->sucursal, $sinPrecio, '5');
    cargarStock($this->admin, $this->sucursal, $conPrecio, '5');
    app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->sucursal->cajaPrincipal(), '100.00');

    $pantalla = Livewire::test(Pos::class)
        ->call('agregarProducto', $conPrecio->id)
        ->call('agregarProducto', $sinPrecio->id)
        ->call('confirmar')
        ->assertSet('productosPrecioCero', ['Shampoo regalo'])
        ->assertSee('Productos sin precio');

    expect(Venta::query()->count())->toBe(0)
        ->and(stockDe($this->sucursal, $sinPrecio))->toBe('5.000');

    $pantalla->call('confirmar', true)->assertHasNoErrors()->assertSet('productosPrecioCero', []);

    expect(Venta::query()->sole()->total)->toBe('7.00')
        ->and(stockDe($this->sucursal, $sinPrecio))->toBe('4.000');

    // Con todos los precios definidos no pregunta nada.
    Livewire::test(Pos::class)->call('agregarProducto', $conPrecio->id)->call('confirmar')->assertSet('productosPrecioCero', []);
    expect(Venta::query()->count())->toBe(2);
});

// --- «Agregar» en Productos › Catálogo Cyclone con precio de venta y stock inicial ---------------------------------

function agregarCyclone(CatalogoExternoProducto $externo, string $precio, string $stock): Testable
{
    return Livewire::test(Productos::class)->set('pestana', 'cyclone')
        ->call('abrirAgregarCyclone', $externo->id)
        ->set('cyclonePrecio', $precio)
        ->set('cycloneStock', $stock)
        ->call('agregarProductoCyclone');
}

test('Agregar exige el stock inicial y avisa que se puede poner 0; sin precio tampoco agrega', function () {
    agregarCyclone($this->palta, '25', '')
        ->assertHasErrors(['cycloneStock'])
        ->assertSee('Si todavía no tienes unidades, puedes poner 0.')
        ->assertSet('agregandoCycloneId', $this->palta->id);

    agregarCyclone($this->palta, '', '0')->assertHasErrors(['cyclonePrecio'])->assertSee('Escribe el precio de venta.');
    agregarCyclone($this->palta, '0', '0')->assertHasErrors(['cyclonePrecio']);
    agregarCyclone($this->palta, '12.345', '0')->assertHasErrors(['cyclonePrecio']);
    agregarCyclone($this->palta, '10', '-1')->assertHasErrors(['cycloneStock']);

    expect(productoLocalDe($this->empresa->id, $this->palta))->toBeNull();
});

test('Agregar con stock 0 crea el producto con su precio, auditado y sin movimiento de inventario', function () {
    agregarCyclone($this->palta, '25.50', '0')
        ->assertHasNoErrors()
        ->assertSet('agregandoCycloneId', null)
        ->assertSee('sin stock por ahora');

    $producto = productoLocalDe($this->empresa->id, $this->palta);

    expect($producto->precio_consumidor)->toBe('25.50')
        ->and($producto->activo)->toBeTrue()
        ->and(stockDe($this->sucursal, $producto))->toBe('0.000')
        ->and(DB::table('operaciones_inventario')->where('empresa_id', $this->empresa->id)->count())->toBe(0)
        ->and(Auditoria::query()->where('accion', 'producto.actualizado')->where('entidad_id', $producto->id)->exists())->toBeTrue();
});

test('Agregar con stock registra la entrada «Inventario inicial» en la sucursal elegida, una sola vez', function () {
    $sucursalNorte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Norte']);

    $pantalla = Livewire::test(Productos::class)->set('pestana', 'cyclone')
        ->call('abrirAgregarCyclone', $this->palta->id)
        ->set('cyclonePrecio', '30')
        ->set('cycloneStock', '24')
        ->set('cycloneSucursal', (string) $sucursalNorte->id);
    $clave = $pantalla->get('cycloneClave');
    $pantalla->call('agregarProductoCyclone')->assertHasNoErrors()->assertSee('con stock inicial de 24');

    $producto = productoLocalDe($this->empresa->id, $this->palta);
    expect(stockDe($sucursalNorte, $producto))->toBe('24.000')
        ->and(stockDe($this->sucursal, $producto))->toBe('0.000')
        ->and(DB::table('operaciones_inventario')->where('empresa_id', $this->empresa->id)->where('motivo', 'inventario_inicial')->count())->toBe(1);

    // Doble clic: el segundo envío ya no tiene formulario abierto.
    $pantalla->call('agregarProductoCyclone');
    // Reintento con la misma clave contra la acción: no repite la entrada.
    app(AgregarProductoExterno::class)->agregarConPrecioYStock($this->admin, $this->palta->id, '30', '24', $sucursalNorte->id, $clave);

    expect(stockDe($sucursalNorte, $producto))->toBe('24.000')
        ->and(DB::table('operaciones_inventario')->where('empresa_id', $this->empresa->id)->count())->toBe(1)
        ->and(Producto::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count())->toBe(1);
});

test('si el stock inicial falla no queda el producto ni el precio a medias', function () {
    // Producto por unidades: 2.5 no es una cantidad válida.
    agregarCyclone($this->palta, '30', '2.5')->assertHasErrors(['cycloneStock']);

    expect(productoLocalDe($this->empresa->id, $this->palta))->toBeNull()
        ->and(DB::table('operaciones_inventario')->where('empresa_id', $this->empresa->id)->count())->toBe(0);
});

test('Agregar no acepta la sucursal de otra empresa ni lo usa un vendedor o una empresa no vinculada', function () {
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $sucursalB = Sucursal::factory()->create(['empresa_id' => $empresaB->id]);
    $this->actingAs($this->admin);

    agregarCyclone($this->palta, '30', '5')->assertHasNoErrors();
    $this->assertDatabaseCount('stock_niveles', 1);

    Livewire::test(Productos::class)->set('pestana', 'cyclone')
        ->call('abrirAgregarCyclone', $this->coco->id)
        ->set('cyclonePrecio', '30')->set('cycloneStock', '5')->set('cycloneSucursal', (string) $sucursalB->id)
        ->call('agregarProductoCyclone')
        ->assertHasErrors(['cycloneSucursal']);
    expect(productoLocalDe($this->empresa->id, $this->coco))->toBeNull()
        ->and(DB::table('stock_niveles')->where('sucursal_id', $sucursalB->id)->count())->toBe(0);

    $this->actingAs(User::factory()->vendedor($this->sucursal)->create());
    Livewire::test(Productos::class)->call('abrirAgregarCyclone', $this->coco->id)->assertForbidden();

    $this->actingAs($adminB);
    unidadesBase($empresaB->id);
    expect(fn () => app(AgregarProductoExterno::class)->agregarConPrecioYStock($adminB, $this->coco->id, '30', '0', null, (string) Str::uuid()))
        ->toThrow(ValidationException::class)
        ->and(Producto::withoutGlobalScopes()->where('empresa_id', $empresaB->id)->count())->toBe(0);
});

test('al reactivar un producto que se dejó de vender, Agregar propone su precio actual', function () {
    agregarCyclone($this->palta, '18', '3')->assertHasNoErrors();
    $producto = productoLocalDe($this->empresa->id, $this->palta);
    $producto->forceFill(['activo' => false])->save();

    Livewire::test(Productos::class)->set('pestana', 'cyclone')
        ->call('abrirAgregarCyclone', $this->palta->id)
        ->assertSet('cyclonePrecio', '18.00')
        ->set('cycloneStock', '0')
        ->call('agregarProductoCyclone')
        ->assertHasNoErrors();

    expect($producto->fresh()->activo)->toBeTrue()
        ->and(stockDe($this->sucursal, $producto))->toBe('3.000');
});
