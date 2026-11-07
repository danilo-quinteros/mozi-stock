<?php

use App\Domain\Catalogo\Actions\ConfirmarImportacionProductos;
use App\Domain\Catalogo\Actions\PrepararImportacionProductos;
use App\Livewire\Catalogo\ImportarProductos;
use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\ImportacionProducto;
use App\Models\ImportacionProductoFila;
use App\Models\OperacionInventario;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @param  list<list<mixed>>  $filas
 */
function excelDePrueba(array $filas, string $nombre = 'productos.xlsx'): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'imp');
    $escritor = new Writer;
    $escritor->openToFile($ruta);

    foreach ($filas as $fila) {
        $escritor->addRow(Row::fromValues($fila));
    }

    $escritor->close();
    $contenido = (string) file_get_contents($ruta);
    unlink($ruta);

    return UploadedFile::fake()->createWithContent($nombre, $contenido);
}

function csvDePrueba(string $contenido, string $nombre = 'productos.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($nombre, $contenido);
}

/**
 * Sube el archivo, acepta las columnas y valores propuestos y deja la importación en revisión.
 *
 * @param  array<string, mixed>  $columnas
 */
function importacionEnRevision(User $actor, UploadedFile $archivo, array $columnas = []): ImportacionProducto
{
    $preparar = app(PrepararImportacionProductos::class);
    $importacion = $preparar->crear($actor, $archivo);
    $mapeo = [];

    foreach ($importacion->mapeo as $campo => $indice) {
        $mapeo[$indice] = $campo;
    }

    $preparar->asignarColumnas($actor, $importacion, $columnas + [
        'fila_encabezados' => $importacion->fila_encabezados,
        'mapeo' => $mapeo,
        'unidad_defecto_id' => (int) UnidadMedida::query()->where('nombre', 'Unidad')->value('id'),
        'usar_stock' => isset($importacion->mapeo['stock']) ? false : null,
        'sucursal_stock_id' => null,
    ]);

    if ($importacion->estado === ImportacionProducto::VALORES) {
        $preparar->asignarValores($actor, $importacion, $importacion->valores);
    }

    return $importacion->refresh();
}

beforeEach(function () {
    Storage::fake('local');
    [$this->empresa, $this->admin] = empresaConAdministrador();

    foreach (UnidadMedida::BASE as $base) {
        $unidad = new UnidadMedida($base);
        $unidad->empresa_id = $this->empresa->id;
        $unidad->save();
    }

    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Central']);
    $this->actingAs($this->admin);
});

test('an Excel without template is matched by the user and imported with initial stock', function () {
    $lacteos = new Categoria(['nombre' => 'Lácteos']);
    $lacteos->save();
    $kilogramo = UnidadMedida::query()->where('nombre', 'Kilogramo')->sole();

    $archivo = excelDePrueba([
        ['LISTA DE PRECIOS 2026'],
        ['Cód.', 'Descripción', 'Categoría', 'U.M.', 'Precio venta', 'P. Mayorista', 'Existencias', 'Costo'],
        ['beb-01', 'Coca-Cola 2L', 'Bebidas', 'UND', 13, 11.5, 24, 9],
        ['LAC-01', 'Queso fresco', 'LACTEOS', 'kg', 45.5, '', 2.5, 30],
        ['', 'Pan molde', '', '', '12,00', '', '', ''],
        ['BOL-01', 'Arroz 5 kg', 'Abarrotes', 'bolsa', 60, 55, 10, 50],
    ], 'Lista Bolivia.xlsx');

    $pantalla = Livewire::test(ImportarProductos::class)
        ->set('archivo', $archivo)
        ->assertHasNoErrors()
        ->assertSee('¿Qué dato tiene cada columna?')
        ->assertSet('fila_encabezados', '2')
        ->assertSet('mapeo.0', 'sku')
        ->assertSet('mapeo.1', 'nombre')
        ->assertSet('mapeo.2', 'categoria')
        ->assertSet('mapeo.3', 'unidad')
        ->assertSet('mapeo.4', 'precio_consumidor')
        ->assertSet('mapeo.5', 'precio_mayorista')
        ->assertSet('mapeo.6', 'stock')
        ->assertSee('Tu archivo tiene una columna de stock');

    // «Costo» no es un dato de la ficha: queda sin importar. Sin responder la pregunta del stock no avanza.
    expect($pantalla->get('mapeo'))->not->toHaveKey(7);
    $pantalla->call('guardarColumnas')->assertHasErrors('usar_stock');
    $pantalla->set('usar_stock', 'si')->set('sucursal_stock_id', (string) $this->sucursal->id)
        ->call('guardarColumnas')->assertHasNoErrors()->assertSee('Categorías y unidades del archivo');

    $categorias = collect($pantalla->get('categorias'))->keyBy('clave');
    $unidades = collect($pantalla->get('unidades'))->keyBy('clave');
    expect($categorias['lacteos']['destino'])->toBe((string) $lacteos->id)
        ->and($categorias['bebidas']['destino'])->toBe('crear')
        ->and($unidades['kg']['destino'])->toBe((string) $kilogramo->id)
        ->and($unidades['und']['destino'])->toBe((string) UnidadMedida::query()->where('nombre', 'Unidad')->value('id'))
        ->and($unidades['bolsa']['destino'])->toBe('crear');

    $pantalla->call('guardarValores')->assertHasNoErrors()->assertSee('Revisa antes de importar')
        ->assertSee('Se crearán 4 productos, 2 categorías nuevas, 1 unidades nuevas.');
    $importacion = ImportacionProducto::query()->sole();
    expect($importacion->resumen)->toMatchArray(['total' => 4, 'validas' => 4, 'errores' => 0, 'existentes' => 0, 'con_stock' => 3,
        'categorias_nuevas' => 2, 'unidades_nuevas' => 1])
        ->and(Producto::query()->count())->toBe(0);

    $pantalla->call('pedirConfirmacion', false)->call('confirmar')->assertHasNoErrors()->assertSee('Importación completada');

    $productos = Producto::query()->orderBy('importacion_fila')->get()->keyBy('nombre');
    expect($productos)->toHaveCount(4)
        ->and($productos['Coca-Cola 2L']->sku)->toBe('BEB-01')
        ->and($productos['Coca-Cola 2L']->precio_consumidor)->toBe('13.00')
        ->and($productos['Coca-Cola 2L']->precio_mayorista)->toBe('11.50')
        ->and($productos['Coca-Cola 2L']->categoria->nombre)->toBe('Bebidas')
        ->and($productos['Queso fresco']->categoria_id)->toBe($lacteos->id)
        ->and($productos['Queso fresco']->unidad_medida_id)->toBe($kilogramo->id)
        ->and($productos['Queso fresco']->tipo_venta)->toBe('medida')
        ->and($productos['Pan molde']->sku)->toBeNull()
        ->and($productos['Pan molde']->categoria_id)->toBeNull()
        ->and($productos['Pan molde']->precio_consumidor)->toBe('12.00')
        ->and($productos['Arroz 5 kg']->unidad->nombre)->toBe('Bolsa')
        ->and($productos->every(fn (Producto $p) => $p->importacion_id === $importacion->id))->toBeTrue();

    $stock = DB::table('stock_niveles')->where('sucursal_id', $this->sucursal->id)->pluck('cantidad', 'producto_id');
    expect($stock->all())->toEqual([
        $productos['Coca-Cola 2L']->id => '24.000',
        $productos['Queso fresco']->id => '2.500',
        $productos['Arroz 5 kg']->id => '10.000',
    ]);

    $operacion = OperacionInventario::query()->sole();
    expect($operacion->tipo->value)->toBe('entrada')
        ->and($operacion->motivo)->toBe('inventario_inicial')
        ->and($operacion->total_lineas)->toBe(3)
        ->and($operacion->nota)->toBe("Importación de productos n.º {$importacion->id} (Lista Bolivia.xlsx)")
        ->and(Auditoria::query()->where('accion', 'productos.importados')->count())->toBe(1)
        ->and($importacion->refresh()->estado)->toBe(ImportacionProducto::COMPLETADA)
        ->and($importacion->archivo_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles("empresas/{$this->empresa->id}/importaciones"))->toBe([]);
});

test('rows with errors are shown and the user decides to import only the correct ones', function () {
    productoDe($this->empresa, ['sku' => 'EXI-1', 'nombre' => 'Ya registrado', 'precio_consumidor' => '5.00']);

    $archivo = csvDePrueba(implode("\n", [
        'Código;Nombre;Precio;Código de barras',
        'A-1;Correcto uno;10,50;',
        'A-2;Sin precio;;',
        'A-3;Precio ambiguo;1.500;',
        'A-4;Barras científico;8;7,79E+12',
        'A-5;Negativo;-2;',
        'A-1;Código repetido;3;',
        'exi-1;Existente;99;',
        'A-6;Correcto dos;1.234,50;7790001112223',
    ]));

    $importacion = importacionEnRevision($this->admin, $archivo);
    $estados = $importacion->filas()->where('fila', '>', 1)->orderBy('fila')->pluck('estado', 'fila')->all();

    expect($importacion->resumen)->toMatchArray(['total' => 8, 'validas' => 2, 'errores' => 5, 'existentes' => 1])
        ->and($estados)->toBe([2 => 'valida', 3 => 'error', 4 => 'error', 5 => 'error', 6 => 'error', 7 => 'error', 8 => 'existente', 9 => 'valida'])
        ->and(ImportacionProductoFila::query()->where('fila', 4)->value('errores')[0])->toContain('ambiguo')
        ->and(ImportacionProductoFila::query()->where('fila', 5)->value('errores')[0])->toContain('notación científica')
        ->and(ImportacionProductoFila::query()->where('fila', 7)->value('errores')[0])->toContain('ya aparece en la fila 2');

    $confirmar = app(ConfirmarImportacionProductos::class);
    expect(fn () => $confirmar->ejecutar($this->admin, $importacion, false, $importacion->resumen['firma']))
        ->toThrow(ValidationException::class, 'Hay filas con errores');
    expect(Producto::query()->count())->toBe(1);

    $resultado = $confirmar->ejecutar($this->admin, $importacion->refresh(), true, $importacion->resumen['firma']);

    expect($resultado)->toMatchArray(['creados' => 2, 'omitidas_error' => 5, 'omitidas_existentes' => 1, 'stock' => null])
        ->and(Producto::query()->where('sku', 'A-6')->value('precio_consumidor'))->toBe('1234.50')
        ->and(Producto::query()->where('sku', 'EXI-1')->value('precio_consumidor'))->toBe('5.00')
        ->and(DB::table('movimientos_stock')->count())->toBe(0);
});

test('the error rows can be downloaded to fix them', function () {
    $archivo = csvDePrueba("Nombre;Precio\nBien;5\nMal;abc\n");
    $importacion = importacionEnRevision($this->admin, $archivo);

    $csv = Livewire::test(ImportarProductos::class, ['importacionId' => $importacion->id])
        ->call('descargarErrores')
        ->effects['download'] ?? null;

    expect($csv)->not->toBeNull()
        ->and(base64_decode($csv['content']))->toContain('Mal;abc')->toContain('no es un número válido')->not->toContain('Bien');
});

test('confirming twice does not duplicate products or stock', function () {
    $archivo = csvDePrueba("Nombre;Precio;Stock\nAgua;3;10\nJugo;5;4\n");
    $preparar = app(PrepararImportacionProductos::class);
    $importacion = $preparar->crear($this->admin, $archivo);
    $preparar->asignarColumnas($this->admin, $importacion, [
        'fila_encabezados' => 1, 'mapeo' => [0 => 'nombre', 1 => 'precio_consumidor', 2 => 'stock'],
        'unidad_defecto_id' => (int) UnidadMedida::query()->where('nombre', 'Unidad')->value('id'),
        'usar_stock' => true, 'sucursal_stock_id' => $this->sucursal->id,
    ]);
    $firma = $importacion->refresh()->resumen['firma'];
    $confirmar = app(ConfirmarImportacionProductos::class);

    $primero = $confirmar->ejecutar($this->admin, $importacion, false, $firma);
    $segundo = $confirmar->ejecutar($this->admin, ImportacionProducto::query()->sole(), false, $firma);

    expect($segundo)->toBe($primero)
        ->and(Producto::query()->count())->toBe(2)
        ->and(OperacionInventario::query()->count())->toBe(1)
        ->and(DB::table('stock_niveles')->sum('cantidad'))->toEqual('14.000');
});

test('if the catalog changed after the review the import is not confirmed until reviewed again', function () {
    $archivo = csvDePrueba("Código;Nombre;Precio\nN-1;Nuevo uno;3\nN-2;Nuevo dos;4\n");
    $importacion = importacionEnRevision($this->admin, $archivo);
    $firmaVista = $importacion->resumen['firma'];

    // Otro administrador crea N-2 mientras tanto.
    productoDe($this->empresa, ['sku' => 'N-2']);

    $pantalla = Livewire::test(ImportarProductos::class, ['importacionId' => $importacion->id])
        ->assertSet('firma', $firmaVista);
    $pantalla->call('pedirConfirmacion', false)->call('confirmar')->assertHasErrors('confirmar');

    expect(Producto::query()->count())->toBe(1)
        ->and($importacion->refresh()->resumen)->toMatchArray(['validas' => 1, 'existentes' => 1]);

    $pantalla->assertSet('firma', $importacion->resumen['firma'])
        ->call('pedirConfirmacion', false)->call('confirmar')->assertHasNoErrors();

    expect(Producto::query()->where('sku', 'N-1')->exists())->toBeTrue()
        ->and(Producto::query()->count())->toBe(2);
});

test('A1 companies never see or use each other imports, codes or categories', function () {
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $unidadB = new UnidadMedida(['nombre' => 'Unidad', 'abreviatura' => 'und.']);
    $unidadB->save();
    (new Categoria(['nombre' => 'Bebidas']))->save();
    productoDe($empresaB, ['sku' => 'COMP-1']);
    $importacionB = importacionEnRevision($adminB, csvDePrueba("Código;Nombre;Precio;Categoría\nX-1;De B;1;Bebidas\n"));

    $this->actingAs($this->admin);
    $importacionA = importacionEnRevision($this->admin, csvDePrueba("Código;Nombre;Precio;Categoría\nCOMP-1;Mismo código que B;2;Bebidas\n"));

    // El SKU de B no cuenta como existente en A y la categoría «Bebidas» de B no se reutiliza.
    expect($importacionA->resumen)->toMatchArray(['validas' => 1, 'existentes' => 0, 'categorias_nuevas' => 1]);

    Livewire::test(ImportarProductos::class, ['importacionId' => $importacionB->id])
        ->assertSet('importacionId', null)
        ->assertDontSee('De B');
    expect(fn () => app(ConfirmarImportacionProductos::class)->ejecutar($this->admin, ImportacionProducto::withoutGlobalScopes()->findOrFail($importacionB->id), false, ''))
        ->toThrow(HttpException::class);

    app(ConfirmarImportacionProductos::class)->ejecutar($this->admin, $importacionA, false, $importacionA->resumen['firma']);

    $producto = Producto::query()->where('sku', 'COMP-1')->sole();
    expect($producto->empresa_id)->toBe($this->empresa->id)
        ->and($producto->categoria->empresa_id)->toBe($this->empresa->id)
        ->and(Producto::withoutGlobalScopes()->where('sku', 'COMP-1')->count())->toBe(2)
        ->and(ImportacionProducto::withoutGlobalScopes()->find($importacionB->id)->estado)->toBe(ImportacionProducto::REVISION);
});

test('sellers cannot open the import screen or run its actions', function () {
    $vendedor = User::factory()->vendedor($this->sucursal)->create();
    $importacion = importacionEnRevision($this->admin, csvDePrueba("Nombre;Precio\nAgua;3\n"));
    $this->actingAs($vendedor);

    $this->get(route('productos.importar'))->assertForbidden();
    $this->get(route('productos.index'))->assertOk()->assertDontSee(route('productos.importar'));
    expect(fn () => app(ConfirmarImportacionProductos::class)->ejecutar($vendedor, $importacion, false, $importacion->resumen['firma']))
        ->toThrow(AuthorizationException::class);
    expect(Producto::query()->count())->toBe(0);
});

test('Windows-1252 CSV with semicolons and decimal commas is read correctly', function () {
    $contenido = mb_convert_encoding("Nombre;Categoría;Precio\nAzúcar morena;Dulces;8,50\nCafé;Bebidas;1.234,50\n", 'Windows-1252', 'UTF-8');
    $importacion = importacionEnRevision($this->admin, csvDePrueba($contenido));

    expect($importacion->resumen)->toMatchArray(['validas' => 2, 'errores' => 0])
        ->and(collect($importacion->valores['categorias'])->pluck('nombre')->all())->toBe(['Dulces', 'Bebidas'])
        ->and($importacion->filas()->where('fila', 2)->value('normalizado'))->toMatchArray(['nombre' => 'Azúcar morena', 'precio_consumidor' => '8.50']);
});

test('UTF-8 CSV with BOM and tab separator is read correctly', function () {
    $importacion = importacionEnRevision($this->admin, csvDePrueba("\u{FEFF}Producto\tPVP\nTé verde\t4.5\n", 'lista.txt'));

    expect($importacion->mapeo)->toBe(['nombre' => 0, 'precio_consumidor' => 1])
        ->and($importacion->filas()->where('fila', 2)->value('normalizado'))->toMatchArray(['nombre' => 'Té verde', 'precio_consumidor' => '4.50']);
});

test('stock must be whole for products sold by unit and prices with more decimals are rounded with a warning', function () {
    $archivo = excelDePrueba([
        ['Nombre', 'Precio', 'Stock'],
        ['Galletas', 15.6666, 2.5],
        ['Fideos', 7, 0],
    ]);
    $preparar = app(PrepararImportacionProductos::class);
    $importacion = $preparar->crear($this->admin, $archivo);
    $preparar->asignarColumnas($this->admin, $importacion, [
        'fila_encabezados' => 1, 'mapeo' => [0 => 'nombre', 1 => 'precio_consumidor', 2 => 'stock'],
        'unidad_defecto_id' => (int) UnidadMedida::query()->where('nombre', 'Unidad')->value('id'),
        'usar_stock' => true, 'sucursal_stock_id' => $this->sucursal->id,
    ]);

    $galletas = ImportacionProductoFila::query()->where('fila', 2)->sole();
    $fideos = ImportacionProductoFila::query()->where('fila', 3)->sole();

    expect($galletas->estado)->toBe('error')
        ->and($galletas->errores[0])->toContain('unidades enteras')
        ->and($galletas->advertencias[0])->toContain('redondeado de 15,6666 a 15,67')
        ->and($galletas->normalizado['precio_consumidor'])->toBe('15.67')
        ->and($fideos->estado)->toBe('valida')
        ->and($fideos->normalizado['stock'])->toBeNull();
});

test('cancelling deletes the file and rows and creates nothing', function () {
    Livewire::test(ImportarProductos::class)
        ->set('archivo', csvDePrueba("Nombre;Precio\nAgua;3\n"))
        ->assertHasNoErrors()
        ->call('cancelar')
        ->assertSet('importacionId', null)
        ->assertSee('Elige tu archivo de productos');

    expect(ImportacionProducto::query()->sole()->estado)->toBe(ImportacionProducto::CANCELADA)
        ->and(ImportacionProductoFila::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles("empresas/{$this->empresa->id}/importaciones"))->toBe([])
        ->and(Producto::query()->count())->toBe(0);
});

test('unsupported or empty files are rejected with a clear message', function () {
    Livewire::test(ImportarProductos::class)
        ->set('archivo', UploadedFile::fake()->createWithContent('lista.xls', 'binario'))
        ->assertHasErrors('archivo');

    Livewire::test(ImportarProductos::class)
        ->set('archivo', csvDePrueba("\n\n"))
        ->assertHasErrors(['archivo']);

    expect(ImportacionProducto::query()->count())->toBe(0);
});

test('validating hundreds of rows uses a bounded number of queries', function () {
    $lineas = ['Código;Nombre;Precio;Categoría'];

    foreach (range(1, 600) as $n) {
        $lineas[] = "C-{$n};Producto {$n};{$n},50;Grupo ".($n % 7);
    }

    $preparar = app(PrepararImportacionProductos::class);
    $importacion = $preparar->crear($this->admin, csvDePrueba(implode("\n", $lineas)));
    $preparar->asignarColumnas($this->admin, $importacion, [
        'fila_encabezados' => 1, 'mapeo' => [0 => 'sku', 1 => 'nombre', 2 => 'precio_consumidor', 3 => 'categoria'],
        'unidad_defecto_id' => (int) UnidadMedida::query()->where('nombre', 'Unidad')->value('id'),
        'usar_stock' => null, 'sucursal_stock_id' => null,
    ]);

    DB::enableQueryLog();
    $preparar->asignarValores($this->admin, $importacion, $importacion->valores);
    $consultas = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($importacion->refresh()->resumen['validas'])->toBe(600)
        ->and($consultas)->toBeLessThan(25);

    DB::enableQueryLog();
    app(ConfirmarImportacionProductos::class)->ejecutar($this->admin, $importacion, false, $importacion->resumen['firma']);
    $consultas = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect(Producto::query()->count())->toBe(600)
        ->and(Categoria::query()->count())->toBe(7)
        ->and($consultas)->toBeLessThan(60);
});
