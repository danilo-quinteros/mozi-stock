<?php

use App\Domain\CatalogoExterno\Actions\DescargarCatalogoCentral;
use App\Domain\CatalogoExterno\Actions\SincronizarEmpresa;
use App\Domain\CatalogoExterno\Actions\VincularEmpresaProveedor;
use App\Domain\CatalogoExterno\Fuentes\CycloneFuente;
use App\Domain\Inventario\Actions\RegistrarAjuste;
use App\Livewire\Catalogo\Productos;
use App\Models\CatalogoExternoProducto;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\ProductoVinculoExterno;
use App\Models\ProveedorExterno;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    foreach (UnidadMedida::BASE as $base) {
        $u = new UnidadMedida($base);
        $u->empresa_id = $this->empresa->id;
        $u->save();
    }

    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id]);
    $this->actingAs($this->admin);

    $this->proveedor = ProveedorExterno::query()->firstOrCreate(
        ['codigo' => 'cyclone'],
        ['nombre' => 'Cyclone', 'driver' => 'cyclone', 'base_url' => 'https://cyclone.test/api/v1', 'activo' => true]
    );
});

test('descarga catalogo central desde API y descarta estrictamente cualquier precio de Cyclone', function () {
    Http::fake([
        'https://cyclone.test/api/v1/catalogo/productos*' => Http::response([
            'data' => [
                [
                    'id' => 101,
                    'codigo' => 'CYC-SH-350',
                    'nombre' => 'Shampoo Anticaspa 350gr',
                    'descripcion' => 'Shampoo limpiador profundo',
                    'presentacion' => '350gr',
                    'linea' => 'Cuidado Personal',
                    'foto_url' => null,
                    'estado' => 1,
                    'updated_at' => '2026-10-29T10:00:00Z',
                    // CAMPOS MALICIOSOS O PRIVADOS DE CYCLONE QUE DEBEN SER DESCARTADOS
                    'costo_produccion' => 15.50,
                    'precio_minorista' => 20.00,
                    'precio_mayorista' => 18.00,
                    'precio_consumidor' => 25.00,
                    'stock_minimo' => 10,
                ],
                [
                    'id' => 102,
                    'codigo' => 'CYC-LIMP-1L',
                    'nombre' => 'Limpiador Multiuso',
                    'descripcion' => 'Desengrasante multiuso',
                    'presentacion' => '1lt',
                    'linea' => 'Limpieza Hogar',
                    'foto_url' => null,
                    'estado' => 1,
                    'updated_at' => '2026-10-29T10:00:00Z',
                ],
            ],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => 200,
                'total' => 2,
            ],
        ]),
    ]);

    $descargador = app(DescargarCatalogoCentral::class);
    $resultado = $descargador->ejecutar($this->proveedor);

    expect($resultado['procesados'])->toBe(2)
        ->and($resultado['nuevos'])->toBe(2);

    $shampoo = CatalogoExternoProducto::query()->where('externo_id', '101')->sole();
    expect($shampoo->nombre_limpio)->toBe('Shampoo Anticaspa')
        ->and($shampoo->contenido)->toBe('350.000')
        ->and($shampoo->unidad_sugerida)->toBe('g')
        ->and($shampoo->tipo_venta)->toBe('unidad')
        ->and($shampoo->linea_nombre)->toBe('Cuidado Personal');

    // Verificar en la base de datos que NO existe columna de precios en catalogo_externo_productos
    $columnas = DB::getSchemaBuilder()->getColumnListing('catalogo_externo_productos');
    expect($columnas)->not->toContain('precio_consumidor')
        ->not->toContain('precio_minorista')
        ->not->toContain('precio_mayorista')
        ->not->toContain('costo_produccion');
});

test('sincroniza a empresa revendedora creando productos con precio cero y estado activo', function () {
    // Preparar producto central
    CatalogoExternoProducto::query()->create([
        'proveedor_externo_id' => $this->proveedor->id,
        'externo_id' => '201',
        'codigo' => 'LAV-500',
        'nombre_original' => 'Lavavajillas 500ml',
        'nombre_limpio' => 'Lavavajillas',
        'contenido' => '500.000',
        'unidad_sugerida' => 'ml',
        'tipo_venta' => 'unidad',
        'descripcion' => 'Lavavajillas concentrado',
        'linea_nombre' => 'Cocina',
        'activo_origen' => true,
        'disponible' => true,
        'hash' => 'hash_test_1',
    ]);

    // Vincular empresa como revendedora
    $superadmin = User::factory()->superadmin()->create();
    app(VincularEmpresaProveedor::class)->ejecutar($superadmin, $this->empresa, $this->proveedor, true);

    $sincronizador = app(SincronizarEmpresa::class);
    $res = $sincronizador->ejecutar($this->empresa, $this->proveedor);

    expect($res['creados'])->toBe(1);

    $producto = Producto::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->sole();
    expect($producto->nombre_base)->toBe('Lavavajillas')
        ->and($producto->nombre)->toBe('Lavavajillas 500 ml')
        ->and($producto->tipo_venta)->toBe('unidad')
        ->and($producto->precio_consumidor)->toBe('0.00')
        ->and($producto->precio_minorista)->toBeNull()
        ->and($producto->precio_mayorista)->toBeNull()
        ->and($producto->activo)->toBeTrue()
        ->and($producto->categoria->nombre)->toBe('Cocina')
        ->and($producto->unidad->abreviatura)->toBe('ml');

    // Comprobar que existe el vínculo
    expect(ProductoVinculoExterno::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->where('externo_id', '201')->exists())->toBeTrue();
});

test('la sincronizacion nunca sobrescribe precios ni stock de los revendedores y es idempotente', function () {
    $central = CatalogoExternoProducto::query()->create([
        'proveedor_externo_id' => $this->proveedor->id,
        'externo_id' => '301',
        'codigo' => 'SH-400',
        'nombre_original' => 'Shampoo Coco',
        'nombre_limpio' => 'Shampoo Coco',
        'contenido' => '400.000',
        'unidad_sugerida' => 'ml',
        'tipo_venta' => 'unidad',
        'descripcion' => 'Aroma coco',
        'linea_nombre' => 'Capilar',
        'activo_origen' => true,
        'disponible' => true,
        'hash' => 'hash_v1',
    ]);

    $superadmin = User::factory()->superadmin()->create();
    app(VincularEmpresaProveedor::class)->ejecutar($superadmin, $this->empresa, $this->proveedor, true);

    $sincronizador = app(SincronizarEmpresa::class);
    $sincronizador->ejecutar($this->empresa, $this->proveedor);

    $producto = Producto::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->sole();

    // 1. El revendedor fija sus propios precios
    $producto->precio_consumidor = '28.50';
    $producto->precio_minorista = '25.00';
    $producto->precio_mayorista = '22.00';
    $producto->save();

    // 2. El revendedor ingresa stock a su sucursal
    app(RegistrarAjuste::class)->ejecutar($this->admin, [
        'tipo' => 'entrada',
        'motivo' => 'reposicion_manual',
        'sucursal_id' => $this->sucursal->id,
        'nota' => 'Carga inicial del revendedor',
        'lineas' => [$producto->id => '50'],
        'clave' => (string) Str::uuid(),
    ]);

    expect(stockDe($this->sucursal, $producto))->toBe('50.000');

    // 3. Cyclone actualiza el nombre y descripción en su catálogo central
    $central->nombre_original = 'Shampoo Coco & Argán';
    $central->nombre_limpio = 'Shampoo Coco & Argán';
    $central->descripcion = 'Nueva fórmula con Argán';
    $central->hash = 'hash_v2';
    $central->save();

    // 4. Se ejecuta una segunda sincronización
    $res2 = $sincronizador->ejecutar($this->empresa, $this->proveedor);

    expect($res2['creados'])->toBe(0)
        ->and($res2['actualizados'])->toBe(1)
        ->and($res2['sin_cambios'])->toBe(0);

    // 5. Verificar que no se duplicaron productos
    expect(Producto::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count())->toBe(1);

    // 6. Verificar que los precios y existencias del revendedor quedaron INTACTOS
    $productoActualizado = $producto->fresh();
    expect($productoActualizado->nombre_base)->toBe('Shampoo Coco & Argán')
        ->and($productoActualizado->descripcion)->toBe('Nueva fórmula con Argán')
        ->and($productoActualizado->precio_consumidor)->toBe('28.50') // Intacto
        ->and($productoActualizado->precio_minorista)->toBe('25.00')   // Intacto
        ->and($productoActualizado->precio_mayorista)->toBe('22.00')   // Intacto
        ->and(stockDe($this->sucursal, $productoActualizado))->toBe('50.000'); // Intacto

    // 7. Tercera sincronización sin cambios
    $res3 = $sincronizador->ejecutar($this->empresa, $this->proveedor);
    expect($res3['creados'])->toBe(0)
        ->and($res3['actualizados'])->toBe(0)
        ->and($res3['sin_cambios'])->toBe(1);
});

test('aislamiento total entre empresa vinculada y no vinculada', function () {
    CatalogoExternoProducto::query()->create([
        'proveedor_externo_id' => $this->proveedor->id,
        'externo_id' => '401',
        'codigo' => 'PROD-A',
        'nombre_original' => 'Cera Líquida',
        'nombre_limpio' => 'Cera Líquida',
        'contenido' => '1.000',
        'unidad_sugerida' => 'L',
        'tipo_venta' => 'unidad',
        'linea_nombre' => 'Autos',
        'activo_origen' => true,
        'disponible' => true,
        'hash' => 'hash_a',
    ]);

    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    foreach (UnidadMedida::BASE as $base) {
        $u = new UnidadMedida($base);
        $u->empresa_id = $empresaB->id;
        $u->save();
    }
    $this->actingAs($this->admin);

    // Vincular SOLO a Empresa A
    $superadmin = User::factory()->superadmin()->create();
    app(VincularEmpresaProveedor::class)->ejecutar($superadmin, $this->empresa, $this->proveedor, true);

    $sincronizador = app(SincronizarEmpresa::class);
    $sincronizador->ejecutar($this->empresa, $this->proveedor);

    // Empresa A tiene 1 producto; Empresa B tiene 0 productos
    expect(Producto::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count())->toBe(1)
        ->and(Producto::withoutGlobalScopes()->where('empresa_id', $empresaB->id)->count())->toBe(0);
});

test('empresa revendedora inicia con catalogo vacio y puede agregar productos individual o masivamente desde catalogo cyclone', function () {
    $p1 = CatalogoExternoProducto::query()->create([
        'proveedor_externo_id' => $this->proveedor->id,
        'externo_id' => '501',
        'codigo' => 'CY-001',
        'nombre_original' => 'Shampoo hidratante 350 g',
        'nombre_limpio' => 'Shampoo hidratante',
        'contenido' => '350.000',
        'unidad_sugerida' => 'g',
        'tipo_venta' => 'unidad',
        'linea_nombre' => 'Cuidado personal',
        'activo_origen' => true,
        'disponible' => true,
        'hash' => 'hash_shampoo',
    ]);

    $p2 = CatalogoExternoProducto::query()->create([
        'proveedor_externo_id' => $this->proveedor->id,
        'externo_id' => '502',
        'codigo' => 'CY-002',
        'nombre_original' => 'Detergente líquido 1 L',
        'nombre_limpio' => 'Detergente líquido',
        'contenido' => '1.000',
        'unidad_sugerida' => 'L',
        'tipo_venta' => 'unidad',
        'linea_nombre' => 'Limpieza',
        'activo_origen' => true,
        'disponible' => true,
        'hash' => 'hash_detergente',
    ]);

    $superadmin = User::factory()->superadmin()->create();
    app(VincularEmpresaProveedor::class)->ejecutar($superadmin, $this->empresa, $this->proveedor, true);

    // 1. La empresa inicia con catálogo vacío
    expect(Producto::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count())->toBe(0);

    // 2. En la vista de productos se ve el botón "Cargar desde Cyclone" y el tab "Catálogo Cyclone"
    Livewire::test(Productos::class)
        ->assertSee('Catálogo Cyclone')
        ->assertSee('Cargar desde Cyclone')
        ->set('pestana', 'cyclone')
        ->assertSee('Shampoo hidratante')
        ->assertSee('Detergente líquido')
        ->assertSee('Disponible')
        // 3. Agregar el primer producto individualmente
        ->call('agregarProducto', $p1->id)
        ->assertHasNoErrors()
        ->assertSee('Producto agregado a tu catálogo.');

    expect(Producto::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count())->toBe(1);

    // 4. Agregar el segundo producto masivamente con selección
    Livewire::test(Productos::class)
        ->set('pestana', 'cyclone')
        ->set('seleccionadosCyclone', [(string) $p2->id])
        ->call('agregarMarcados')
        ->assertHasNoErrors();

    expect(Producto::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count())->toBe(2);

    // 5. Ambos productos ahora aparecen "En mi catálogo" y con botón "Dejar de vender"
    Livewire::test(Productos::class)
        ->set('pestana', 'cyclone')
        ->assertSee('En mi catálogo')
        ->assertSee('Dejar de vender');
});

test('dejar de vender producto sin stock lo elimina limpiamente, pero con stock alerta y desactiva preservando inventario', function () {
    $pCentral = CatalogoExternoProducto::query()->create([
        'proveedor_externo_id' => $this->proveedor->id,
        'externo_id' => '701',
        'codigo' => 'CY-003',
        'nombre_original' => 'Cera para Pisos 1 L',
        'nombre_limpio' => 'Cera para Pisos',
        'contenido' => '1.000',
        'unidad_sugerida' => 'L',
        'tipo_venta' => 'unidad',
        'linea_nombre' => 'Hogar',
        'activo_origen' => true,
        'disponible' => true,
        'hash' => 'hash_cera',
    ]);

    $superadmin = User::factory()->superadmin()->create();
    app(VincularEmpresaProveedor::class)->ejecutar($superadmin, $this->empresa, $this->proveedor, true);

    // Revendedor agrega el producto
    $comp = Livewire::test(Productos::class)
        ->call('agregarProducto', $pCentral->id);

    $producto = Producto::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->first();
    expect($producto)->not->toBeNull()
        ->and($producto->activo)->toBeTrue();

    // CASO 1: Producto con 0 stock y 0 historial -> Al dejar de vender se elimina limpiamente
    $comp->call('solicitarDejarVender', $pCentral->id)
        ->assertHasNoErrors()
        ->assertSee('Producto retirado de tu catálogo.');

    expect(Producto::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count())->toBe(0);

    // Volver a agregarlo
    $comp->call('agregarProducto', $pCentral->id);
    $producto = Producto::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->first();

    // Registrar stock en la sucursal
    app(RegistrarAjuste::class)->ejecutar($this->admin, [
        'tipo' => 'entrada',
        'motivo' => 'reposicion_manual',
        'sucursal_id' => $this->sucursal->id,
        'nota' => 'Carga inicial de stock',
        'lineas' => [$producto->id => '15'],
        'clave' => (string) Str::uuid(),
    ]);

    expect(stockDe($this->sucursal, $producto))->toBe('15.000');

    // CASO 2: Producto con stock > 0 -> Al solicitar dejar de vender, muestra alerta de inventario
    $comp->call('solicitarDejarVender', $pCentral->id)
        ->assertSet('confirmandoDejarVenderId', $pCentral->id)
        ->assertSee('Este producto tiene existencias registradas en inventario')
        ->assertSee('15')
        ->call('confirmarDejarVender')
        ->assertHasNoErrors()
        ->assertSee('Producto retirado de la venta activa. Sus existencias e historial se conservaron.');

    // Verificar que el producto NO fue borrado, pero su estado es activo = false, y el stock sigue INTACTO
    $productoInactivo = $producto->fresh();
    expect($productoInactivo)->not->toBeNull()
        ->and($productoInactivo->activo)->toBeFalse()
        ->and(stockDe($this->sucursal, $productoInactivo))->toBe('15.000');

    // CASO 3: Re-agregar el producto reactiva el producto sin duplicarlo ni alterar su stock
    $comp->call('agregarProducto', $pCentral->id)
        ->assertHasNoErrors();

    $productoReactivado = $producto->fresh();
    expect($productoReactivado->activo)->toBeTrue()
        ->and(stockDe($this->sucursal, $productoReactivado))->toBe('15.000')
        ->and(Producto::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count())->toBe(1);
});

test('dejar de vender masivamente detecta productos con stock y abre modal de advertencia masivo', function () {
    $p1 = CatalogoExternoProducto::query()->create([
        'proveedor_externo_id' => $this->proveedor->id,
        'externo_id' => '801',
        'codigo' => 'BULK-1',
        'nombre_original' => 'Producto Con Stock',
        'nombre_limpio' => 'Producto Con Stock',
        'contenido' => '1.000',
        'unidad_sugerida' => 'und.',
        'tipo_venta' => 'unidad',
        'activo_origen' => true,
        'disponible' => true,
        'hash' => 'hash_b1',
    ]);

    $p2 = CatalogoExternoProducto::query()->create([
        'proveedor_externo_id' => $this->proveedor->id,
        'externo_id' => '802',
        'codigo' => 'BULK-2',
        'nombre_original' => 'Producto Sin Stock',
        'nombre_limpio' => 'Producto Sin Stock',
        'contenido' => '1.000',
        'unidad_sugerida' => 'und.',
        'tipo_venta' => 'unidad',
        'activo_origen' => true,
        'disponible' => true,
        'hash' => 'hash_b2',
    ]);

    $superadmin = User::factory()->superadmin()->create();
    app(VincularEmpresaProveedor::class)->ejecutar($superadmin, $this->empresa, $this->proveedor, true);

    // Agregar ambos productos
    $comp = Livewire::test(Productos::class)
        ->set('pestana', 'cyclone')
        ->set('seleccionadosCyclone', [(string) $p1->id, (string) $p2->id])
        ->call('agregarMarcados');

    $prod1 = Producto::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->where('sku', 'BULK-1')->sole();
    $prod2 = Producto::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->where('sku', 'BULK-2')->sole();

    // Solo ingresar stock a prod1
    app(RegistrarAjuste::class)->ejecutar($this->admin, [
        'tipo' => 'entrada',
        'motivo' => 'reposicion_manual',
        'sucursal_id' => $this->sucursal->id,
        'nota' => 'Carga inicial',
        'lineas' => [$prod1->id => '20'],
        'clave' => (string) Str::uuid(),
    ]);

    // Seleccionar ambos para "Dejar de vender"
    $comp->set('seleccionadosCyclone', [(string) $p1->id, (string) $p2->id])
        ->call('solicitarDejarVenderMarcados')
        ->assertSet('confirmandoDejarVenderBulk', true)
        ->assertSee('1 productos seleccionados tienen existencias registradas en inventario.')
        ->assertSee('Producto Con Stock')
        ->call('confirmarDejarVenderBulk')
        ->assertHasNoErrors()
        ->assertSet('confirmandoDejarVenderBulk', false)
        ->assertSet('seleccionadosCyclone', []);

    // prod1 con stock: se desactivó (activo = false) y conserva 20 de stock
    $prod1Actual = $prod1->fresh();
    expect($prod1Actual->activo)->toBeFalse()
        ->and(stockDe($this->sucursal, $prod1Actual))->toBe('20.000');

    // prod2 sin stock ni historial: se eliminó completamente
    expect(Producto::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->where('id', $prod2->id)->exists())->toBeFalse();
});

test('el comando artisan mozistock:catalogo-externo funciona en modo sincrono y con colas', function () {
    CatalogoExternoProducto::query()->create([
        'proveedor_externo_id' => $this->proveedor->id,
        'externo_id' => '601',
        'codigo' => 'ART-1',
        'nombre_original' => 'Producto Comando',
        'nombre_limpio' => 'Producto Comando',
        'contenido' => '1.000',
        'unidad_sugerida' => 'und.',
        'tipo_venta' => 'unidad',
        'activo_origen' => true,
        'disponible' => true,
        'hash' => 'hash_cmd',
    ]);

    $superadmin = User::factory()->superadmin()->create();
    app(VincularEmpresaProveedor::class)->ejecutar($superadmin, $this->empresa, $this->proveedor, true);

    $this->artisan('mozistock:catalogo-externo', [
        'accion' => 'sincronizar',
        '--proveedor' => 'cyclone',
        '--empresa' => (string) $this->empresa->id,
        '--todos' => true,
        '--sync' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('creados');

    expect(Producto::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count())->toBe(1);
});

test('sincroniza productos a granel como Litros o Kg con tipo_venta medida', function () {
    Http::fake([
        'https://cyclone.test/api/v1/catalogo/productos*' => Http::response([
            'data' => [
                [
                    'id' => 205,
                    'codigo' => '1LAVA',
                    'nombre' => 'Lava Vajilla',
                    'descripcion' => 'Vajillero Agranel',
                    'presentacion' => 'Litros',
                    'linea' => 'Hogar',
                    'foto_url' => null,
                    'estado' => 1,
                    'updated_at' => '2026-10-29T10:00:00Z',
                ],
            ],
            'meta' => ['current_page' => 1, 'last_page' => 1],
        ]),
    ]);

    app(DescargarCatalogoCentral::class)->ejecutar($this->proveedor);

    $prodCentral = CatalogoExternoProducto::where('externo_id', '205')->first();
    expect($prodCentral->tipo_venta)->toBe('medida')
        ->and($prodCentral->unidad_sugerida)->toBe('L')
        ->and($prodCentral->contenido)->toBeNull();

    $superadmin = User::factory()->superadmin()->create();
    app(VincularEmpresaProveedor::class)->ejecutar($superadmin, $this->empresa, $this->proveedor, true);
    $res = app(SincronizarEmpresa::class)->ejecutar($this->empresa, $this->proveedor, $this->admin);

    expect($res['creados'])->toBe(1);

    $prodEmpresa = Producto::where('sku', '1LAVA')->first();
    expect($prodEmpresa->tipo_venta)->toBe('medida')
        ->and($prodEmpresa->unidad->abreviatura)->toBe('L')
        ->and($prodEmpresa->unidad->unidad_medida_sin)->toBe(28)
        ->and($prodEmpresa->contenido)->toBeNull();
});

test('descarga catalogo con unidad_siat y la sincroniza al clasificador sin oficial', function () {
    Http::fake([
        'https://cyclone.test/api/v1/catalogo/productos*' => Http::response([
            'data' => [
                [
                    'id' => 301,
                    'codigo' => 'CYC-LIT-01',
                    'nombre' => 'Limpia Pisos Marina',
                    'descripcion' => 'A granel',
                    'presentacion' => 'Litros',
                    'unidad_siat' => 'LITRO',
                    'linea' => 'Hogar',
                    'foto_url' => null,
                    'estado' => 1,
                    'updated_at' => '2026-10-30T10:00:00Z',
                ],
            ],
            'meta' => ['current_page' => 1, 'last_page' => 1],
        ]),
    ]);

    app(DescargarCatalogoCentral::class)->ejecutar($this->proveedor);

    $prodCentral = CatalogoExternoProducto::where('externo_id', '301')->first();
    expect($prodCentral->unidad_siat_sugerida)->toBe('LITRO');

    $superadmin = User::factory()->superadmin()->create();
    app(VincularEmpresaProveedor::class)->ejecutar($superadmin, $this->empresa, $this->proveedor, true);
    app(SincronizarEmpresa::class)->ejecutar($this->empresa, $this->proveedor, $this->admin);

    $prodEmpresa = Producto::where('sku', 'CYC-LIT-01')->first();
    expect($prodEmpresa->unidad->unidad_medida_sin)->toBe(28);
});

test('sirve foto de catalogo externo a revendedor autorizado y aisla para no revendedores', function () {
    Storage::fake('local');

    // 1. Crear producto externo con imagen simulada en caché
    $fakePng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $path = "catalogo_central/{$this->proveedor->id}/999.png";
    Storage::disk('local')->put($path, $fakePng);

    $prodCentral = CatalogoExternoProducto::create([
        'proveedor_externo_id' => $this->proveedor->id,
        'externo_id' => '999',
        'codigo' => 'CYC-IMG-01',
        'nombre_original' => 'Producto con Foto',
        'nombre_limpio' => 'Producto con Foto',
        'tipo_venta' => 'unidad',
        'foto_url' => 'https://cyclone.test/api/v1/catalogo/productos/999/foto',
        'foto_cache_path' => $path,
        'disponible' => true,
        'hash' => 'fake-hash-foto',
    ]);

    // Empresa NO revendedora intenta ver la foto
    $otraEmpresa = Empresa::factory()->create();
    $otroAdmin = User::factory()->create(['empresa_id' => $otraEmpresa->id, 'rol' => 'administrador']);
    $this->actingAs($otroAdmin);

    $resForbidden = $this->get(route('catalogo-externo.foto', $prodCentral));
    $resForbidden->assertStatus(403);

    // Empresa vinculada como revendedora SÍ puede ver la foto
    $superadmin = User::factory()->superadmin()->create();
    $this->actingAs($superadmin);
    app(VincularEmpresaProveedor::class)->ejecutar($superadmin, $this->empresa, $this->proveedor, true);
    $this->actingAs($this->admin);

    $resOk = $this->get(route('catalogo-externo.foto', $prodCentral));
    $resOk->assertStatus(200);
    $resOk->assertHeader('Content-Type', 'image/png');
});

test('copia foto a almacenamiento privado del tenant al sincronizar producto externo', function () {
    Storage::fake('local');

    $fakePng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $centralPath = "catalogo_central/{$this->proveedor->id}/888.png";
    Storage::disk('local')->put($centralPath, $fakePng);

    $prodCentral = CatalogoExternoProducto::create([
        'proveedor_externo_id' => $this->proveedor->id,
        'externo_id' => '888',
        'codigo' => 'CYC-SYNC-IMG',
        'nombre_original' => 'Producto Para Tenant',
        'nombre_limpio' => 'Producto Para Tenant',
        'tipo_venta' => 'unidad',
        'foto_url' => 'https://cyclone.test/api/v1/catalogo/productos/888/foto',
        'foto_cache_path' => $centralPath,
        'disponible' => true,
        'hash' => 'fake-hash-tenant-foto',
    ]);

    $superadmin = User::factory()->superadmin()->create();
    app(VincularEmpresaProveedor::class)->ejecutar($superadmin, $this->empresa, $this->proveedor, true);

    app(SincronizarEmpresa::class)->ejecutar($this->empresa, $this->proveedor, $this->admin);

    $prodTenant = Producto::where('sku', 'CYC-SYNC-IMG')->first();
    expect($prodTenant)->not->toBeNull()
        ->and($prodTenant->imagen_path)->not->toBeNull()
        ->and($prodTenant->imagen_path)->toContain("empresas/{$this->empresa->id}/productos/");

    // Verificar que existe en disco local privado del tenant
    expect(Storage::disk('local')->exists($prodTenant->imagen_path))->toBeTrue();

    // Y que el controller privado sirve la imagen
    $this->actingAs($this->admin);
    $res = $this->get(route('productos.imagen', $prodTenant));
    $res->assertStatus(200);
});

test('resolverCodigoSin busca en el catálogo SIAT de unidades lo que no está en el mapeo fijo', function () {
    DB::table('siat_catalogos')->insert([
        ['empresa_id' => $this->empresa->id, 'catalogo' => 'UnidadesMedida', 'codigo' => '62', 'descripcion' => 'TONELADA'],
        ['empresa_id' => $this->empresa->id, 'catalogo' => 'UnidadesMedida', 'codigo' => '15', 'descripcion' => 'DOCENA'],
        ['empresa_id' => $this->empresa->id, 'catalogo' => 'TiposMoneda', 'codigo' => '9', 'descripcion' => 'CUARTO'],
    ]);

    expect(UnidadMedida::resolverCodigoSin('Tonelada'))->toBe(62)
        ->and(UnidadMedida::resolverCodigoSin('  docena '))->toBe(15)
        ->and(UnidadMedida::resolverCodigoSin(null, 'Docena'))->toBe(15)
        // Solo el catálogo de unidades, y los comodines del usuario no se interpretan.
        ->and(UnidadMedida::resolverCodigoSin('Cuarto'))->toBeNull()
        ->and(UnidadMedida::resolverCodigoSin('%'))->toBeNull()
        ->and(UnidadMedida::resolverCodigoSin('_ONELADA'))->toBeNull()
        // El mapeo fijo sigue teniendo prioridad.
        ->and(UnidadMedida::resolverCodigoSin('Litro'))->toBe(28);
});

test('el token de Cyclone sale del proveedor o de config, no de env(), y viaja en la descarga', function () {
    // Con config:cache, env() devuelve null fuera de config/: el respaldo debe leerse de services.cyclone.
    config(['services.cyclone.token' => 'token-del-servidor', 'services.cyclone.base_url' => 'https://cyclone.test/api/v1']);
    $this->proveedor->forceFill(['token' => null, 'base_url' => null])->save();

    expect(CycloneFuente::token($this->proveedor->fresh()))->toBe('token-del-servidor');

    Http::fake(['cyclone.test/*' => Http::response(['data' => [], 'meta' => ['last_page' => 1]])]);
    iterator_to_array(app(CycloneFuente::class)->obtenerProductos($this->proveedor->fresh()));

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer token-del-servidor')
        && str_starts_with($request->url(), 'https://cyclone.test/api/v1/catalogo/productos'));

    // El token guardado (cifrado) en el proveedor tiene prioridad.
    $this->proveedor->forceFill(['token' => 'token-del-proveedor'])->save();
    expect(CycloneFuente::token($this->proveedor->fresh()))->toBe('token-del-proveedor');
});

test('el token de Cyclone solo viaja a fotos del mismo host y esquema que su API', function () {
    $this->proveedor->forceFill(['token' => 'secreto', 'base_url' => 'https://cyclone.test/api/v1'])->save();
    $proveedor = $this->proveedor->fresh();

    expect(CycloneFuente::tokenParaFoto($proveedor, 'https://cyclone.test/storage/productos/1.jpg'))->toBe('secreto')
        ->and(CycloneFuente::tokenParaFoto($proveedor, 'https://CYCLONE.test/productos/1.jpg'))->toBe('secreto')
        ->and(CycloneFuente::tokenParaFoto($proveedor, 'https://otro-servidor.test/1.jpg'))->toBeNull()
        ->and(CycloneFuente::tokenParaFoto($proveedor, 'https://cyclone.test.atacante.com/1.jpg'))->toBeNull()
        ->and(CycloneFuente::tokenParaFoto($proveedor, 'http://cyclone.test/1.jpg'))->toBeNull()
        ->and(CycloneFuente::tokenParaFoto($proveedor, 'no-es-url'))->toBeNull();

    // Descarga central: la foto de otro host se pide sin Authorization.
    Http::fake([
        'cyclone.test/api/*' => Http::response(['data' => [['id' => 'X1', 'nombre' => 'Cera', 'foto_url' => 'https://otro-servidor.test/x1.jpg', 'activo' => true]], 'meta' => ['last_page' => 1]]),
        'otro-servidor.test/*' => Http::response('no es imagen', 200),
    ]);
    app(DescargarCatalogoCentral::class)->ejecutar($proveedor);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://otro-servidor.test/') && ! $request->hasHeader('Authorization'));
    Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://otro-servidor.test/') && $request->hasHeader('Authorization'));
});

test('un error HTTP de Cyclone tras los reintentos se informa con su código y queda en el proveedor', function () {
    Http::fake(['cyclone.test/*' => Http::response('caído', 503)]);

    expect(fn () => app(DescargarCatalogoCentral::class)->ejecutar($this->proveedor))
        ->toThrow(RuntimeException::class, 'Respuesta fallida de Cyclone (HTTP 503)');

    expect($this->proveedor->fresh()->ultimo_error)->toContain('HTTP 503');
    Http::assertSentCount(2); // retry(2) = dos intentos en total
});
