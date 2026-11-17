<?php

use App\Domain\Catalogo\Consultas\ListadoProductos;
use App\Domain\Compras\Actions\RegistrarCompra;
use App\Domain\Produccion\Actions\GuardarMateriaPrima;
use App\Domain\Produccion\Consultas\ListadoMateriasPrimas;
use App\Domain\Produccion\CostoPromedio;
use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Enums\Capacidad;
use App\Enums\Rubro;
use App\Livewire\Compras\NuevaCompra;
use App\Livewire\Empresa\FuncionesNegocio;
use App\Livewire\Pedidos\Editor;
use App\Livewire\Produccion\MateriaPrimaFormulario;
use App\Livewire\Produccion\MateriasPrimas;
use App\Models\Compra;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * Producción, etapas P0 y P1 (ESPECIFICACION_PRODUCCION.md § 4, § 8, § 9 y § 13): capacidad, materias primas que
 * nunca se venden, ingreso como compra con costo promedio ponderado, stock máximo y sugerencia de compra.
 */

function unidadDe(Empresa $empresa, string $nombre, string $abreviatura, bool $decimales): UnidadMedida
{
    $unidad = new UnidadMedida(['nombre' => $nombre, 'abreviatura' => $abreviatura, 'admite_decimales' => $decimales, 'unidad_medida_sin' => 57]);
    $unidad->empresa_id = $empresa->id;
    $unidad->save();

    return $unidad;
}

function activarProduccion(User $admin, Empresa $empresa): void
{
    app(CambiarFuncionesEmpresa::class)->ejecutar($admin, $empresa, Rubro::General, [Capacidad::Produccion]);
}

/**
 * @param  array<string, mixed>  $datos
 */
function crearMateria(User $admin, UnidadMedida $unidad, array $datos = []): Producto
{
    return app(GuardarMateriaPrima::class)->crear($admin, $datos + [
        'nombre' => 'Fragancia floral', 'unidad_medida_id' => $unidad->id, 'usos' => 'Aroma del suavizante',
        'stock_minimo' => '', 'stock_maximo' => '', 'controla_vencimiento' => false,
    ]);
}

/**
 * @param  array<int|string, array<string, mixed>>  $lineas
 */
function comprarMateria(User $admin, Sucursal $sucursal, array $lineas): Compra
{
    return app(RegistrarCompra::class)->ejecutar($admin, [
        'sucursal_id' => $sucursal->id, 'proveedor_id' => Proveedor::query()->orderBy('id')->value('id'), 'referencia' => null,
        'lineas' => $lineas, 'metodo' => 'efectivo', 'efectivo' => null, 'electronico' => null, 'externo' => true, 'clave' => (string) Str::uuid(),
    ]);
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->kg = unidadDe($this->empresa, 'Kilogramo', 'kg', true);
    $this->und = unidadDe($this->empresa, 'Unidad', 'und.', false);
    $this->suavizante = productoDe($this->empresa, ['nombre' => 'Suavizante floral']);
    $proveedor = new Proveedor(['nombre' => 'Químicos del Oriente']);
    $proveedor->empresa_id = $this->empresa->id;
    $proveedor->save();
    $this->actingAs($this->admin);
});

test('P0: activating Producción also turns on Lotes, and Lotes cannot be turned off while Producción is on', function () {
    activarProduccion($this->admin, $this->empresa);

    expect($this->empresa->fresh()->capacidadesActivas())->toBe([Capacidad::LotesVencimientos, Capacidad::Produccion]);

    // Pedir Producción sin Lotes cuando Lotes ya está encendida = intentar apagar Lotes: se rechaza (petición manipulada).
    expect(fn () => app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa->fresh(), Rubro::General, [Capacidad::Produccion]))
        ->toThrow(ValidationException::class, 'No puedes desactivar «Lotes y vencimientos» mientras «Producción» esté activa')
        ->and($this->empresa->fresh()->capacidadesActivas())->toBe([Capacidad::LotesVencimientos, Capacidad::Produccion]);

    // Apagar las dos a la vez sí se permite.
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa->fresh(), Rubro::General, []);
    expect($this->empresa->fresh()->capacidadesActivas())->toBe([]);
});

test('P0: the screen marks Lotes when Producción is marked and keeps it locked', function () {
    Livewire::test(FuncionesNegocio::class)
        ->set('capacidades', ['produccion'])
        ->assertSet('capacidades', ['produccion', 'lotes_vencimientos'])
        ->assertSeeHtml('disabled data-test="funcion-lotes_vencimientos"')
        ->assertSee('La necesita «Producción»');
});

test('P0: without the capability the module answers 404, and a seller gets 403 with it', function () {
    $this->get(route('produccion.materias'))->assertNotFound();
    $this->get(route('produccion.materias.crear'))->assertNotFound();

    activarProduccion($this->admin, $this->empresa);
    $this->get(route('produccion.materias'))->assertOk()->assertSee('Materias primas');
    $this->get(route('dashboard'))->assertSee(route('produccion.materias'), false);

    $vendedor = User::factory()->vendedor($this->matriz)->create();
    $this->actingAs($vendedor)->get(route('produccion.materias'))->assertForbidden();
    expect(fn () => app(GuardarMateriaPrima::class)->crear($vendedor, ['nombre' => 'X', 'unidad_medida_id' => $this->kg->id]))
        ->toThrow(AuthorizationException::class);
});

test('P1: a raw material gets the MP code, no prices, stock control and decimals from its unit', function () {
    activarProduccion($this->admin, $this->empresa);

    $fragancia = crearMateria($this->admin, $this->kg, ['stock_minimo' => '5', 'stock_maximo' => '20', 'observacion' => 'Proveedor único', 'controla_vencimiento' => true]);
    $botella = crearMateria($this->admin, $this->und, ['nombre' => 'Botella 1 L']);

    expect($fragancia->fresh())
        ->clase->toBe(Producto::CLASE_MATERIA_PRIMA)
        ->sku->toBe('MP-0001')
        ->precio_consumidor->toBe('0.00')
        ->controla_stock->toBeTrue()
        ->controla_vencimiento->toBeTrue()
        ->tipo_venta->toBe('medida')
        ->stock_minimo->toBe('5.000')
        ->stock_maximo->toBe('20.000')
        ->observacion->toBe('Proveedor único')
        ->descripcion->toBe('Aroma del suavizante')
        ->and($botella->fresh())->sku->toBe('MP-0002')->tipo_venta->toBe('unidad');

    expect(fn () => crearMateria($this->admin, $this->kg, ['nombre' => 'Soda', 'stock_minimo' => '10', 'stock_maximo' => '3']))
        ->toThrow(ValidationException::class, 'El stock máximo no puede ser menor que el mínimo.');

    // Una materia prima no se edita con el formulario de productos de venta.
    $this->get(route('productos.editar', $fragancia))->assertNotFound();
    $this->get(route('produccion.materias.editar', $fragancia))->assertOk()->assertSee('Editar materia prima');
    $this->get(route('produccion.materias.editar', $this->suavizante))->assertNotFound();
});

test('P1: the form creates and edits a raw material through the action', function () {
    activarProduccion($this->admin, $this->empresa);

    Livewire::test(MateriaPrimaFormulario::class)
        ->set('nombre', 'Soda cáustica')
        ->set('unidad_medida_id', (string) $this->kg->id)
        ->set('stock_minimo', '10')
        ->set('stock_maximo', '50')
        ->set('costo_inicial', '12.5')
        ->call('guardar')
        ->assertRedirect(route('produccion.materias'));

    $soda = Producto::query()->where('nombre', 'Soda cáustica')->sole();
    expect($soda->clase)->toBe(Producto::CLASE_MATERIA_PRIMA)->and($soda->costo_promedio)->toBe('12.500000');

    Livewire::test(MateriaPrimaFormulario::class, ['producto' => $soda])
        ->assertSet('nombre', 'Soda cáustica')
        ->set('observacion', 'Usar guantes')
        ->set('activo', false)
        ->call('guardar')
        ->assertHasNoErrors();

    expect($soda->fresh())->observacion->toBe('Usar guantes')->activo->toBeFalse();
});

test('P1: raw materials never appear in the sales catalog, POS search, orders or sales, even forcing the id', function () {
    activarProduccion($this->admin, $this->empresa);
    $fragancia = crearMateria($this->admin, $this->kg, ['nombre' => 'Fragancia suave']);
    cargarStock($this->admin, $this->matriz, $fragancia, '10');

    $catalogo = ListadoProductos::consulta($this->empresa->id, $this->matriz->id)->pluck('productos.id')->all();
    expect($catalogo)->toContain($this->suavizante->id)->not->toContain($fragancia->id);
    $this->get(route('productos.index'))->assertDontSee('Fragancia suave');
    $this->get(route('productos.exportar'))->assertDontSee('Fragancia suave');

    // Buscador de Pedidos y ventas: no la ofrece ni la agrega aunque llegue el id.
    Livewire::test(Editor::class)
        ->set('busqueda', 'Fragancia')
        ->assertDontSee('Fragancia suave')
        ->call('agregarProducto', $fragancia->id)
        ->assertSet('lineas', []);

    expect(fn () => crearPedido($this->admin, $this->matriz, [['producto_id' => $fragancia->id, 'cantidad' => '1']]))
        ->toThrow(ValidationException::class, 'no existe o está inactivo');

    // Un pedido anterior que la tuviera (por ejemplo, convertido después) tampoco se confirma.
    $pedido = crearPedido($this->admin, $this->matriz, [['producto_id' => $this->suavizante->id, 'cantidad' => '1']]);
    cargarStock($this->admin, $this->matriz, $this->suavizante, '5');
    DB::table('productos')->where('id', $this->suavizante->id)->update(['clase' => Producto::CLASE_MATERIA_PRIMA]);

    expect(fn () => confirmarVenta($this->admin, $pedido, ['metodo' => 'efectivo']))
        ->toThrow(ValidationException::class, 'es materia prima y no se vende')
        ->and(DB::table('ventas')->count())->toBe(0)
        ->and(stockDe($this->matriz, $this->suavizante->fresh()))->toBe('5.000');
});

test('P1: purchases recalculate the weighted average cost of raw materials only', function () {
    activarProduccion($this->admin, $this->empresa);
    $fragancia = crearMateria($this->admin, $this->kg, ['costo_inicial' => '5']);
    cargarStock($this->admin, $this->matriz, $fragancia, '10');

    // 10 kg a Bs 5 + 30 kg a Bs 7 → (50 + 210) / 40 = 6,50.
    comprarMateria($this->admin, $this->matriz, [$fragancia->id => ['cantidad' => '30', 'costo' => '7'], $this->suavizante->id => ['cantidad' => '2', 'costo' => '9']]);

    expect($fragancia->fresh()->costo_promedio)->toBe('6.500000')
        ->and($this->suavizante->fresh()->costo_promedio)->toBe('0.000000')
        ->and(stockDe($this->matriz, $fragancia))->toBe('40.000');

    // Una salida no cambia el costo; con stock 0, el costo es el de la compra.
    $soda = crearMateria($this->admin, $this->kg, ['nombre' => 'Soda']);
    comprarMateria($this->admin, $this->matriz, [$soda->id => ['cantidad' => '3', 'costo' => '1.00']]);
    expect($soda->fresh()->costo_promedio)->toBe('1.000000');

    // Céntimos: 3 a Bs 1,00 + 3 a Bs 1,01 = 6,03 / 6 = 1,005.
    comprarMateria($this->admin, $this->matriz, [$soda->id => ['cantidad' => '3', 'costo' => '1.01']]);
    expect($soda->fresh()->costo_promedio)->toBe('1.005000');

    $auditoria = DB::table('auditoria')->where('accion', 'compra.registrada')->orderByDesc('id')->value('despues');
    expect(json_decode((string) $auditoria, true)['costo_promedio'][$soda->id])->toBe(['antes' => '1.000000', 'despues' => '1.005000']);
});

test('P1: the weighted average formula rounds to 6 decimals and uses the purchase cost with no previous stock', function () {
    expect(CostoPromedio::promedio('0', '9.99', '3', '10.00'))->toBe('3.333333')
        ->and(CostoPromedio::promedio('-1', '9.99', '2', '10.00'))->toBe('5.000000')
        ->and(CostoPromedio::promedio('2', '0.333333', '1', '1.00'))->toBe('0.555555')
        ->and(CostoPromedio::promedio('1', '1', '2', '0.01'))->toBe('0.336667');
});

test('P1: a raw material with lots is bought with lot and expiry, and the initial cost is locked after the first purchase', function () {
    activarProduccion($this->admin, $this->empresa);
    $fragancia = crearMateria($this->admin, $this->kg, ['controla_vencimiento' => true, 'costo_inicial' => '4']);

    comprarMateria($this->admin, $this->matriz, [['producto_id' => $fragancia->id, 'cantidad' => '8', 'costo' => '6', 'lote' => 'F-01', 'vencimiento' => '31/12/2027']]);

    expect(DB::table('lotes')->where('producto_id', $fragancia->id)->value('numero'))->toBe('F-01')
        ->and($fragancia->fresh()->costo_promedio)->toBe('6.000000');

    expect(fn () => app(GuardarMateriaPrima::class)->actualizar($this->admin, $fragancia->fresh(), [
        'nombre' => 'Fragancia floral', 'unidad_medida_id' => $this->kg->id, 'controla_vencimiento' => true, 'costo_inicial' => '3',
    ]))->toThrow(ValidationException::class, 'ya tiene compras');

    Livewire::test(MateriaPrimaFormulario::class, ['producto' => $fragancia->fresh()])
        ->assertSeeHtml('disabled placeholder="0,00" data-test="materia-costo-inicial"')
        ->set('costo_inicial', '1')
        ->call('guardar')
        ->assertHasNoErrors();

    expect($fragancia->fresh()->costo_promedio)->toBe('6.000000');
});

test('P1: the raw material intake only offers raw materials and warns above the maximum without blocking', function () {
    activarProduccion($this->admin, $this->empresa);
    $fragancia = crearMateria($this->admin, $this->kg, ['nombre' => 'Fragancia floral', 'stock_maximo' => '20']);
    cargarStock($this->admin, $this->matriz, $fragancia, '15');
    $this->suavizante->forceFill(['nombre' => 'Fragancia en botella'])->save();

    $ingreso = Livewire::withQueryParams(['materia_prima' => '1'])->test(NuevaCompra::class)
        ->assertSet('soloMateriaPrima', true)
        ->assertSee('Ingreso de materia prima')
        ->set('busqueda', 'Fragancia');

    expect($ingreso->instance()->sugerencias()->pluck('id')->all())->toBe([$fragancia->id]);

    $ingreso->call('agregarProducto', $this->suavizante->id)->assertSet('lineas', [])
        ->call('agregarProducto', $fragancia->id)
        ->set('lineas.0.cantidad', '10')
        ->set('lineas.0.costo', '7')
        ->set('proveedor_id', (string) Proveedor::query()->value('id'))
        ->call('revisar')
        ->assertSee('Sobre el stock máximo')
        ->assertSee('quedará en 25 (máximo 20)');

    // Una compra normal ofrece las dos clases.
    $normal = Livewire::withQueryParams([])->test(NuevaCompra::class)->assertSet('soloMateriaPrima', false)->set('busqueda', 'Fragancia');
    expect($normal->instance()->sugerencias()->pluck('id')->sort()->values()->all())->toBe(collect([$fragancia->id, $this->suavizante->id])->sort()->values()->all());
});

test('P1: the purchase suggestion lists raw materials under their minimum and exports them', function () {
    activarProduccion($this->admin, $this->empresa);
    $fragancia = crearMateria($this->admin, $this->kg, ['nombre' => 'Fragancia floral', 'stock_minimo' => '10', 'stock_maximo' => '50']);
    $soda = crearMateria($this->admin, $this->kg, ['nombre' => 'Soda', 'stock_minimo' => '5']);
    $agua = crearMateria($this->admin, $this->kg, ['nombre' => 'Agua desmineralizada', 'stock_minimo' => '5', 'stock_maximo' => '8']);
    cargarStock($this->admin, $this->matriz, $fragancia, '4');
    cargarStock($this->admin, $this->matriz, $soda, '2');
    cargarStock($this->admin, $this->matriz, $agua, '9');
    // El suavizante (de venta) bajo su mínimo no entra en la sugerencia de producción, ni la materia prima en las alertas del catálogo.
    $this->suavizante->forceFill(['stock_minimo' => '3'])->save();

    $filas = ListadoMateriasPrimas::consulta($this->empresa->id, $this->matriz->id, '', 'sugerencia')->get();
    expect($filas->pluck('nombre')->all())->toBe(['Fragancia floral', 'Soda'])
        ->and(ListadoMateriasPrimas::sugerido('4.000', '10.000', '50.000'))->toBe('46.000')
        ->and(ListadoMateriasPrimas::sugerido('2.000', '5.000', null))->toBe('3.000')
        ->and(ListadoMateriasPrimas::resumen($this->empresa->id, $this->matriz->id))->toBe(['total' => 3, 'bajo_minimo' => 2, 'sobre_maximo' => 1])
        ->and(ListadoProductos::contarAlertas($this->empresa->id, $this->matriz->id))->toBe(1)
        ->and(ListadoProductos::contarAlertas($this->empresa->id, $this->matriz->id, Producto::CLASE_MATERIA_PRIMA))->toBe(2);

    Livewire::test(MateriasPrimas::class)->set('filtro', 'sugerencia')
        ->assertSee('Fragancia floral')->assertSee('46 kg')->assertDontSee('Agua desmineralizada');

    $csv = $this->get(route('produccion.sugerencia.exportar', ['sucursal' => $this->matriz->id]))->assertOk()->streamedContent();
    expect($csv)->toContain('MP-0001')->toContain('Fragancia floral')->toContain('46.000')->not->toContain('Agua desmineralizada');
});

test('P1: company B cannot see, edit, buy or export the raw materials of company A', function () {
    activarProduccion($this->admin, $this->empresa);
    $fragancia = crearMateria($this->admin, $this->kg, ['nombre' => 'Fragancia secreta', 'stock_minimo' => '5']);

    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $sucursalB = Sucursal::factory()->create(['empresa_id' => $empresaB->id]);
    $proveedorB = new Proveedor(['nombre' => 'Proveedor B']);
    $proveedorB->empresa_id = $empresaB->id;
    $proveedorB->save();
    activarProduccion($adminB, $empresaB);

    $this->get(route('produccion.materias'))->assertOk()->assertDontSee('Fragancia secreta');
    $this->get(route('produccion.materias.editar', $fragancia))->assertNotFound();
    $this->get(route('produccion.sugerencia.exportar'))->assertOk()->assertDontSee('Fragancia secreta');

    expect(fn () => app(RegistrarCompra::class)->ejecutar($adminB, [
        'sucursal_id' => $sucursalB->id, 'proveedor_id' => $proveedorB->id, 'referencia' => null,
        'lineas' => [$fragancia->id => ['cantidad' => '1', 'costo' => '1']], 'metodo' => 'efectivo', 'efectivo' => null, 'electronico' => null,
        'externo' => true, 'clave' => (string) Str::uuid(),
    ]))->toThrow(ValidationException::class);

    expect(fn () => app(GuardarMateriaPrima::class)->actualizar($adminB, $fragancia, ['nombre' => 'Robada', 'unidad_medida_id' => $this->kg->id]))
        ->toThrow(NotFoundHttpException::class);
    expect($fragancia->fresh()->nombre)->toBe('Fragancia secreta');
});
