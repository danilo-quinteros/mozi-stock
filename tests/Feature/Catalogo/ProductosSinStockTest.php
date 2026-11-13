<?php

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\Datos\CatalogoDatos;
use App\Domain\AgenteMozi\Datos\ConsultaDatos;
use App\Domain\AgenteMozi\Herramientas\RegistroHerramientas;
use App\Domain\AgenteMozi\Ia\DTOs\LlamadaHerramienta;
use App\Domain\Caja\Actions\AbrirTurnoCaja;
use App\Domain\Catalogo\Actions\ConfigurarMinimosSucursal;
use App\Domain\Catalogo\Actions\ConfirmarImportacionProductos;
use App\Domain\Catalogo\Actions\GuardarProducto;
use App\Domain\Catalogo\Actions\PrepararImportacionProductos;
use App\Domain\Catalogo\Consultas\ListadoProductos;
use App\Domain\Compras\Actions\RegistrarCompra;
use App\Domain\Inventario\Actions\RegistrarAjuste;
use App\Domain\Inventario\Actions\RegistrarTraspaso;
use App\Domain\Inventario\Consultas\HistorialMovimientos;
use App\Domain\Inventario\LibroStock;
use App\Domain\Reportes\Consultas\Cobros;
use App\Domain\Reportes\Consultas\InventarioSucursales;
use App\Domain\Reportes\Consultas\PanelInicio;
use App\Domain\Reportes\Consultas\VentasPeriodo;
use App\Domain\Reportes\Periodo;
use App\Domain\Ventas\Actions\ConfirmarVenta;
use App\Domain\Ventas\Actions\RegistrarDevolucion;
use App\Domain\Ventas\Actions\VenderEnPos;
use App\Enums\EstadoPedido;
use App\Enums\TipoOperacionInventario;
use App\Livewire\Catalogo\ProductoFormulario;
use App\Livewire\Catalogo\Productos;
use App\Livewire\Inventario\NuevoAjuste;
use App\Livewire\Pedidos\Editor;
use App\Livewire\Ventas\NuevaDevolucion;
use App\Livewire\Ventas\Pos;
use App\Models\Auditoria;
use App\Models\Compra;
use App\Models\Devolucion;
use App\Models\ImportacionProducto;
use App\Models\ImportacionProductoFila;
use App\Models\MovimientoCaja;
use App\Models\MovimientoStock;
use App\Models\OperacionInventario;
use App\Models\PagoVenta;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Productos sin control de stock, etapa K3 (ESPECIFICACION_PRODUCTOS_SIN_STOCK.md § 7): platos, servicios o
 * cargos que se venden sin mover inventario. Pique macho (Bs 45) y Sopa de maní (Bs 15) no controlan stock;
 * la Gaseosa (Bs 10) sí, con 1 unidad en Casa Matriz. Las demás pruebas de la suite cubren la regresión:
 * todos sus productos controlan stock, que es el valor por defecto.
 */

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    $this->plato = productoDe($this->empresa, ['nombre' => 'Pique macho', 'sku' => 'PLA-01', 'codigo_barras' => '7770000000011',
        'precio_consumidor' => '45.00', 'controla_stock' => false]);
    $this->sopa = productoDe($this->empresa, ['nombre' => 'Sopa de maní', 'precio_consumidor' => '15.00', 'controla_stock' => false]);
    $this->gaseosa = productoDe($this->empresa, ['nombre' => 'Gaseosa', 'sku' => 'BEB-01', 'precio_consumidor' => '10.00']);
    $this->actingAs($this->admin);
    cargarStock($this->admin, $this->matriz, $this->gaseosa, '1');
});

/**
 * @param  list<array{0: Producto, 1: string}>  $lineas  producto y cantidad
 * @return array<string, mixed>
 */
function sinStockDatosPos(Sucursal $sucursal, array $lineas): array
{
    return [
        'sucursal_id' => $sucursal->id, 'cliente_id' => null,
        'lineas' => array_map(fn (array $l) => ['producto_id' => $l[0]->id, 'cantidad' => $l[1], 'tipo_precio' => 'consumidor',
            'descuento_tipo' => 'bs', 'descuento_valor' => '0'], $lineas),
        'descuento_global_tipo' => 'bs', 'descuento_global_valor' => '0', 'clave' => (string) Str::uuid(),
    ];
}

/**
 * @param  array<int, array{cantidad: string, reingresa: bool}>  $lineas  por id de línea de venta
 */
function sinStockDevolver(User $admin, Venta $venta, array $lineas): Devolucion
{
    return app(RegistrarDevolucion::class)->ejecutar($admin, $venta, [
        'lineas' => $lineas, 'motivo' => 'Plato frío', 'metodo' => 'efectivo', 'efectivo' => null, 'electronico' => null,
        'externo' => true, 'clave' => (string) Str::uuid(),
    ]);
}

test('S1 a sale of only uncontrolled dishes keeps its V number with a header-only operation, and repeating it duplicates nothing', function () {
    app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->matriz->cajaPrincipal(), '100.00');
    $datos = sinStockDatosPos($this->matriz, [[$this->plato, '2'], [$this->sopa, '3']]);
    $pago = ['metodo' => 'efectivo', 'en_cuotas' => false, 'cuota_inicial' => null, 'efectivo' => null, 'electronico' => null, 'clave' => (string) Str::uuid()];

    $venta = app(VenderEnPos::class)->ejecutar($this->admin, $datos, $pago);
    $conteos = fn () => [Venta::query()->count(), PagoVenta::query()->count(), MovimientoCaja::query()->count(), OperacionInventario::query()->count(), MovimientoStock::query()->count()];
    $antes = $conteos();
    $repetida = app(VenderEnPos::class)->ejecutar($this->admin, $datos, $pago);
    $operacion = OperacionInventario::query()->where('tipo', TipoOperacionInventario::Venta)->sole();

    expect($repetida->id)->toBe($venta->id)
        ->and($conteos())->toBe($antes)
        ->and($venta->total)->toBe('135.00')
        ->and($venta->codigo())->toBe('V-00001')
        ->and($venta->numero)->toBe($operacion->numero)
        ->and($venta->operacion_inventario_id)->toBe($operacion->id)
        ->and($operacion->total_lineas)->toBe(0)
        ->and(MovimientoStock::query()->where('operacion_id', $operacion->id)->count())->toBe(0)
        ->and(PagoVenta::query()->where('venta_id', $venta->id)->count())->toBe(1)
        ->and(VentaItem::query()->where('venta_id', $venta->id)->count())->toBe(2)
        ->and(DB::table('stock_niveles')->whereIn('producto_id', [$this->plato->id, $this->sopa->id])->exists())->toBeFalse()
        // § 4.1.4: el historial de Ajustes y traspasos no muestra la cabecera vacía.
        ->and(HistorialMovimientos::consulta($this->empresa->id)->where('o.id', $operacion->id)->count())->toBe(0);
});

test('S1 a mixed sale fails for the soda without stock and saves nothing; with one soda only the soda moves', function () {
    $pedido = crearPedido($this->admin, $this->matriz, [['producto_id' => $this->plato->id, 'cantidad' => '1'], ['producto_id' => $this->gaseosa->id, 'cantidad' => '2']]);

    expect(fn () => confirmarVenta($this->admin, $pedido, ['metodo' => 'efectivo']))->toThrow(ValidationException::class, 'No hay stock suficiente');
    expect(Venta::query()->count())->toBe(0)
        ->and(PagoVenta::query()->count())->toBe(0)
        ->and(OperacionInventario::query()->where('tipo', TipoOperacionInventario::Venta)->count())->toBe(0)
        ->and($pedido->refresh()->estado)->toBe(EstadoPedido::Pendiente)
        ->and(stockDe($this->matriz, $this->gaseosa))->toBe('1.000');

    $bien = crearPedido($this->admin, $this->matriz, [['producto_id' => $this->plato->id, 'cantidad' => '1'], ['producto_id' => $this->gaseosa->id, 'cantidad' => '1']]);
    $pago = ['metodo' => 'efectivo', 'en_cuotas' => false, 'cuota_inicial' => null, 'efectivo' => null, 'electronico' => null,
        'clave' => (string) Str::uuid(), 'externo' => true];
    $venta = app(ConfirmarVenta::class)->ejecutar($this->admin, $bien, $pago);
    $repetida = app(ConfirmarVenta::class)->ejecutar($this->admin, $bien->refresh(), $pago);
    $movimientos = MovimientoStock::query()->where('operacion_id', $venta->operacion_inventario_id)->get();

    expect($repetida->id)->toBe($venta->id)
        ->and($venta->total)->toBe('55.00')
        ->and($movimientos)->toHaveCount(1)
        ->and($movimientos[0]->producto_id)->toBe($this->gaseosa->id)
        ->and($movimientos[0]->cantidad)->toBe('-1.000')
        ->and(OperacionInventario::query()->findOrFail($venta->operacion_inventario_id)->total_lineas)->toBe(1)
        ->and(stockDe($this->matriz, $this->gaseosa))->toBe('0.000')
        ->and(PagoVenta::query()->count())->toBe(1)
        ->and(DB::table('stock_niveles')->where('producto_id', $this->plato->id)->exists())->toBeFalse();
});

test('S1 the POS shows «Sin control» and sells 50 dishes without stock, while a product with stock keeps its limit', function () {
    app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->matriz->cajaPrincipal(), '100.00');

    $pantalla = Livewire::test(Pos::class)
        ->assertSee('Sin control')
        ->call('agregarProducto', $this->plato->id)
        ->set('lineas.0.cantidad', '49')
        ->call('sumar', 0, 1)
        ->assertSet('lineas.0.cantidad', '50')
        ->assertDontSee('stock insuficiente');
    $pantalla->call('confirmar')->assertHasNoErrors();
    $venta = Venta::query()->sole();

    expect($venta->total)->toBe('2250.00')
        ->and(VentaItem::query()->sole()->cantidad)->toBe('50.000')
        ->and(MovimientoStock::query()->where('operacion_id', $venta->operacion_inventario_id)->count())->toBe(0);

    Livewire::test(Pos::class)
        ->call('agregarProducto', $this->gaseosa->id)
        ->call('agregarProducto', $this->gaseosa->id)
        ->assertSet('lineas.0.cantidad', '1')
        ->assertSee('No hay más existencias de Gaseosa en esta sucursal.');
});

test('S1 Pedidos y ventas offers the dish with «Sin control» and no stock warning', function () {
    $pantalla = Livewire::test(Editor::class)->set('busqueda', 'Pique');

    expect($pantalla->instance()->sugerencias->pluck('nombre')->all())->toBe(['Pique macho']);
    $pantalla->call('agregarProducto', $this->plato->id)
        ->set('lineas.0.cantidad', '12')
        ->assertSee('PLA-01 · Sin control')
        ->assertDontSee('insuficiente');
});

test('S3 purchases, adjustments and transfers reject an uncontrolled product, even with a forged request', function () {
    $proveedor = new Proveedor(['nombre' => 'Distribuidora Oriente']);
    $proveedor->empresa_id = $this->empresa->id;
    $proveedor->save();
    $mensaje = 'Pique macho no controla stock.';

    expect(fn () => app(RegistrarCompra::class)->ejecutar($this->admin, [
        'sucursal_id' => $this->matriz->id, 'proveedor_id' => $proveedor->id, 'referencia' => null,
        'lineas' => [$this->plato->id => ['cantidad' => '5', 'costo' => '20']],
        'metodo' => 'efectivo', 'efectivo' => null, 'electronico' => null, 'externo' => true, 'clave' => (string) Str::uuid(),
    ]))->toThrow(ValidationException::class, $mensaje);

    expect(fn () => app(RegistrarAjuste::class)->ejecutar($this->admin, [
        'tipo' => 'entrada', 'motivo' => 'reposicion_manual', 'sucursal_id' => $this->matriz->id, 'nota' => 'Carga',
        'lineas' => [$this->plato->id => '5'], 'clave' => (string) Str::uuid(),
    ]))->toThrow(ValidationException::class, $mensaje);

    expect(fn () => app(RegistrarTraspaso::class)->ejecutar($this->admin, [
        'sucursal_id' => $this->matriz->id, 'sucursal_destino_id' => $this->norte->id, 'nota' => null,
        'lineas' => [$this->plato->id => '1'], 'clave' => (string) Str::uuid(),
    ]))->toThrow(ValidationException::class, $mensaje);

    expect(Compra::query()->count())->toBe(0)
        ->and(OperacionInventario::query()->count())->toBe(1)   // la carga de la gaseosa
        ->and(DB::table('stock_niveles')->where('producto_id', $this->plato->id)->exists())->toBeFalse();
});

test('S3 the capture of adjustments does not offer the dish and the scanner explains why', function () {
    $pantalla = Livewire::test(NuevoAjuste::class)->set('busqueda', 'Pique');

    expect($pantalla->instance()->sugerencias)->toBeEmpty();
    $pantalla->call('agregarDesdeBuscador', '7770000000011')
        ->assertSet('lineas', [])
        ->assertSee('Pique macho no controla stock.')
        ->call('agregarDesdeBuscador', 'BEB-01')
        ->assertSet('lineas.0.id', $this->gaseosa->id);
});

test('S4.4 a direct call to LibroStock::aplicar with an uncontrolled product throws and saves nothing', function () {
    expect(fn () => DB::transaction(fn () => app(LibroStock::class)->aplicar($this->admin, [
        'tipo' => TipoOperacionInventario::Entrada, 'motivo' => 'reposicion_manual', 'sucursal_id' => $this->matriz->id,
        'sucursal_destino_id' => null, 'nota' => 'Prueba', 'clave' => (string) Str::uuid(),
    ], [['sucursal_id' => $this->matriz->id, 'producto_id' => $this->plato->id, 'cantidad' => '5.000', 'nombre' => 'Pique macho']])))
        ->toThrow(LogicException::class, 'sin control de stock');

    expect(OperacionInventario::query()->count())->toBe(1)
        ->and(DB::table('stock_niveles')->where('producto_id', $this->plato->id)->exists())->toBeFalse();
});

test('S4.3 a return asking to restock a dish is saved without restocking; the soda in the same return does restock', function () {
    $venta = confirmarVenta($this->admin, crearPedido($this->admin, $this->matriz, [
        ['producto_id' => $this->plato->id, 'cantidad' => '2'], ['producto_id' => $this->gaseosa->id, 'cantidad' => '1'],
    ]), ['metodo' => 'efectivo']);
    $items = VentaItem::query()->where('venta_id', $venta->id)->orderBy('orden')->get();

    Livewire::test(NuevaDevolucion::class, ['venta' => $venta])
        ->assertSee('No controla stock')
        ->assertSet("lineas.{$items[0]->id}.reingresa", false)
        ->assertSet("lineas.{$items[1]->id}.reingresa", true);

    $soloPlato = sinStockDevolver($this->admin, $venta, [$items[0]->id => ['cantidad' => '1', 'reingresa' => true]]);

    expect($soloPlato->operacion_inventario_id)->toBeNull()
        ->and($soloPlato->reembolso)->toBe('45.00')
        ->and((bool) DB::table('devolucion_items')->where('devolucion_id', $soloPlato->id)->value('reingresa_stock'))->toBeFalse()
        ->and(DB::table('stock_niveles')->where('producto_id', $this->plato->id)->exists())->toBeFalse();

    $mixta = sinStockDevolver($this->admin, $venta->refresh(), [
        $items[0]->id => ['cantidad' => '1', 'reingresa' => true], $items[1]->id => ['cantidad' => '1', 'reingresa' => true],
    ]);

    expect(MovimientoStock::query()->where('operacion_id', $mixta->operacion_inventario_id)->pluck('producto_id')->all())->toBe([$this->gaseosa->id])
        ->and(DB::table('devolucion_items')->where('devolucion_id', $mixta->id)->orderBy('id')->pluck('reingresa_stock')->map(fn ($v) => (bool) $v)->all())->toBe([false, true])
        ->and(stockDe($this->matriz, $this->gaseosa))->toBe('1.000');
});

test('S4.5 unchecking with stock in a branch is refused naming the branches; with stock 0 everywhere it is allowed and audited', function () {
    cargarStock($this->admin, $this->norte, $this->gaseosa, '3');

    $formulario = Livewire::test(ProductoFormulario::class, ['producto' => $this->gaseosa])
        ->assertSee('Controla stock')
        ->assertSee('Alerta de stock mínimo')
        ->assertSee('Mínimo propio')
        ->set('controla_stock', false)
        ->assertDontSee('Alerta de stock mínimo')
        ->assertDontSee('Mínimo propio')
        ->assertSee('Para dejar de controlar el stock, debe estar en 0 en todas las sucursales.')
        ->call('guardar')
        ->assertHasErrors('controla_stock')
        ->assertSee('Tiene stock en: Casa Matriz (1), Sucursal Norte (3).');

    expect($this->gaseosa->refresh()->controla_stock)->toBeTrue();

    foreach ([[$this->matriz, '1'], [$this->norte, '3']] as [$sucursal, $cantidad]) {
        app(RegistrarAjuste::class)->ejecutar($this->admin, ['tipo' => 'salida', 'motivo' => 'merma', 'sucursal_id' => $sucursal->id,
            'nota' => 'Pasa a sin control', 'lineas' => [$this->gaseosa->id => $cantidad], 'clave' => (string) Str::uuid()]);
    }

    $formulario->call('guardar')->assertHasNoErrors()->assertRedirect(route('productos.index'));
    $auditoria = Auditoria::query()->where('accion', 'producto.actualizado')->latest('id')->firstOrFail();

    expect($this->gaseosa->refresh()->controla_stock)->toBeFalse()
        ->and($auditoria->antes['controla_stock'])->toBeTrue()
        ->and($auditoria->despues['controla_stock'])->toBeFalse();
});

test('S4.5 checking it again starts at stock 0 and the product is not sold until it gets an entry', function () {
    Livewire::test(ProductoFormulario::class, ['producto' => $this->sopa])
        ->assertDontSee('Stock por sucursal')
        ->assertDontSee('Alerta de stock mínimo')
        ->set('controla_stock', true)
        ->call('guardar')
        ->assertHasNoErrors();

    expect($this->sopa->refresh()->controla_stock)->toBeTrue()
        ->and(fn () => confirmarVenta($this->admin, crearPedido($this->admin, $this->matriz, [['producto_id' => $this->sopa->id, 'cantidad' => '1']]), ['metodo' => 'efectivo']))
        ->toThrow(ValidationException::class, 'No hay stock suficiente');

    cargarStock($this->admin, $this->matriz, $this->sopa, '5');
    confirmarVenta($this->admin, crearPedido($this->admin, $this->matriz, [['producto_id' => $this->sopa->id, 'cantidad' => '1']]), ['metodo' => 'efectivo']);

    expect(stockDe($this->matriz, $this->sopa))->toBe('4.000');
});

test('S1 a new product can be created without stock control and then accepts no minimums per branch', function () {
    Livewire::test(ProductoFormulario::class)
        ->set('nombre', 'Silpancho')
        ->set('precio_consumidor', '40')
        ->set('controla_stock', false)
        ->assertDontSee('Alerta de stock mínimo')
        ->call('guardar')
        ->assertHasNoErrors();

    $silpancho = Producto::query()->where('nombre', 'Silpancho')->sole();

    expect($silpancho->controla_stock)->toBeFalse()
        ->and(session('aviso'))->toBe('Producto SIL-001 creado. Se vende sin control de stock.')
        ->and(fn () => app(ConfigurarMinimosSucursal::class)->ejecutar($this->admin, $silpancho, [$this->matriz->id => '2']))
        ->toThrow(ValidationException::class, 'no controla stock');
});

test('reports: sales and collections include the dishes, while inventory, low stock and the catalog filters leave them out', function () {
    DB::table('productos')->where('id', $this->plato->id)->update(['stock_minimo' => '5.000']);
    confirmarVenta($this->admin, crearPedido($this->admin, $this->matriz, [['producto_id' => $this->plato->id, 'cantidad' => '2']]), ['metodo' => 'efectivo']);
    $hoy = Periodo::hoy();

    expect(VentasPeriodo::totales($this->empresa->id, $hoy)['valor_vendido'])->toBe('90.00')
        ->and(Cobros::resumen($this->empresa->id, $hoy)['efectivo'])->toBe('90.00')
        ->and(collect(PanelInicio::masVendidos($this->empresa->id, null))->pluck('nombre')->all())->toContain('Pique macho')
        ->and(InventarioSucursales::consulta($this->empresa->id)->pluck('nombre')->unique()->values()->all())->toBe(['Gaseosa'])
        ->and(ListadoProductos::contarAlertas($this->empresa->id))->toBe(0)
        ->and(PanelInicio::stockBajo($this->empresa->id, null))->toBeEmpty();

    Livewire::test(Productos::class)
        ->assertSee('Sin control')
        ->set('filtroStock', 'sin_stock')
        ->assertDontSee('Pique macho')
        ->assertDontSee('Sopa de maní');

    $csv = $this->get(route('productos.exportar'))->assertOk()->streamedContent();

    expect($csv)->toContain('Pique macho')
        ->and(collect(explode("\n", $csv))->first(fn ($linea) => str_contains($linea, 'Pique macho')))->toContain('Sin control');
});

test('A1 company B cannot change the checkbox of a product of company A', function () {
    [, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);

    $this->get(route('productos.editar', $this->gaseosa))->assertNotFound();
    expect(fn () => app(GuardarProducto::class)->actualizar($adminB, $this->gaseosa, ['controla_stock' => false]))
        ->toThrow(AuthorizationException::class);
    expect(Producto::withoutGlobalScopes()->findOrFail($this->gaseosa->id)->controla_stock)->toBeTrue();
});

test('S2 the import reads «Controla stock», ignores the initial stock of a dish with a warning and rejects other values', function () {
    Storage::fake('local');

    foreach (UnidadMedida::BASE as $base) {
        $unidad = new UnidadMedida($base);
        $unidad->empresa_id = $this->empresa->id;
        $unidad->save();
    }

    $archivo = UploadedFile::fake()->createWithContent('carta.csv', "Nombre;Precio;Controla stock;Stock\nCharque;50;no;10\nCoca-Cola 2L;13;sí;24\nApi morado;8;quizás;\nTé;5;;\n");
    $preparar = app(PrepararImportacionProductos::class);
    $importacion = $preparar->crear($this->admin, $archivo);

    expect($importacion->mapeo)->toMatchArray(['nombre' => 0, 'precio_consumidor' => 1, 'controla_stock' => 2, 'stock' => 3]);

    $preparar->asignarColumnas($this->admin, $importacion, [
        'fila_encabezados' => $importacion->fila_encabezados,
        'mapeo' => [0 => 'nombre', 1 => 'precio_consumidor', 2 => 'controla_stock', 3 => 'stock'],
        'unidad_defecto_id' => (int) UnidadMedida::query()->where('nombre', 'Unidad')->value('id'),
        'usar_stock' => true,
        'sucursal_stock_id' => $this->matriz->id,
    ]);

    if ($importacion->estado === ImportacionProducto::VALORES) {
        $preparar->asignarValores($this->admin, $importacion, $importacion->valores);
    }

    $importacion->refresh();
    $filas = ImportacionProductoFila::query()->where('importacion_id', $importacion->id)->where('fila', '>', $importacion->fila_encabezados)
        ->orderBy('fila')->get()->values();

    expect($filas[0]->advertencias)->toBe(['No controla stock: se ignora el stock 10.'])
        ->and($filas[0]->normalizado['stock'])->toBeNull()
        ->and($filas[1]->normalizado['stock'])->toBe('24.000')
        ->and($filas[2]->estado)->toBe(ImportacionProductoFila::ERROR)
        ->and($filas[2]->errores)->toBe(['«quizás» en Controla stock no es válido: escribe sí o no.'])
        ->and($filas[3]->normalizado['controla_stock'])->toBeTrue()
        ->and($importacion->resumen['con_stock'])->toBe(1);

    app(ConfirmarImportacionProductos::class)->ejecutar($this->admin, $importacion, true, $importacion->resumen['firma']);
    $creados = Producto::query()->whereNotNull('importacion_id')->get()->keyBy('nombre');

    expect($creados->keys()->sort()->values()->all())->toBe(['Charque', 'Coca-Cola 2L', 'Té'])
        ->and($creados['Charque']->controla_stock)->toBeFalse()
        ->and($creados['Coca-Cola 2L']->controla_stock)->toBeTrue()
        ->and($creados['Té']->controla_stock)->toBeTrue()
        ->and(DB::table('stock_niveles')->where('producto_id', $creados['Charque']->id)->exists())->toBeFalse()
        ->and(stockDe($this->matriz, $creados['Coca-Cola 2L']))->toBe('24.000');
});

test('S2 Agente Mozi reports «sin control de stock» instead of 0 and never counts dishes as missing', function () {
    crearPedido($this->admin, $this->matriz, [['producto_id' => $this->plato->id, 'cantidad' => '5']]);
    $registro = app(RegistroHerramientas::class);
    $resultado = $registro->ejecutar(new LlamadaHerramienta('c1', 'analyze_pending_orders', []), $registro->disponibles($this->admin),
        $this->admin, ContextoAgente::desde($this->admin));
    $datasets = collect($resultado['datasets'])->mapWithKeys(fn (array $d) => [$d['dataset']->clave => $d['dataset']->filas]);

    expect($resultado['error'] ?? null)->toBeNull()
        ->and($datasets['pendientes'][0]['lineas_sin_stock'])->toBe(0)
        ->and($datasets['faltantes'])->toBe([]);

    $consulta = fn (array $argumentos) => (new ConsultaDatos(new CatalogoDatos(ContextoAgente::desde($this->admin))))->ejecutar($argumentos);
    $productos = collect($consulta(['source' => 'productos', 'fields' => ['nombre', 'stock', 'control_stock']])['filas'])->keyBy('nombre');
    $stock = $consulta(['source' => 'stock', 'fields' => ['producto']])['filas'];

    expect($productos['Pique macho']['stock'])->toBeNull()
        ->and($productos['Pique macho']['control_stock'])->toBe('sin control de stock')
        ->and($productos['Gaseosa']['control_stock'])->toBe('controla stock')
        ->and((float) $productos['Gaseosa']['stock'])->toBe(1.0)
        ->and(array_values(array_unique(array_column($stock, 'producto'))))->toBe(['Gaseosa']);
});

test('performance: the sale reads the stock-control marks with a fixed number of queries', function () {
    $platos = collect(range(1, 20))->map(fn (int $n) => productoDe($this->empresa, ['nombre' => "Plato {$n}", 'precio_consumidor' => '10.00', 'controla_stock' => false]));
    $consultas = function (array $productos): int {
        $pedido = crearPedido($this->admin, $this->matriz, array_map(fn (Producto $p) => ['producto_id' => $p->id, 'cantidad' => '1'], $productos));
        DB::flushQueryLog();
        DB::enableQueryLog();
        confirmarVenta($this->admin, $pedido, ['metodo' => 'efectivo']);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    // La primera venta del request carga en caché las capacidades de la empresa; desde ahí, mismo número de consultas.
    $consultas($platos->take(1)->all());

    expect($consultas($platos->slice(1, 2)->all()))->toBe($consultas($platos->slice(3)->all()));
});
