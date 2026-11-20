<?php

use App\Domain\Migracion\Cyclone\FotosCyclone;
use App\Domain\Migracion\Cyclone\OrigenCyclone;
use App\Domain\Migracion\Cyclone\PlanMigracionCyclone;
use App\Domain\Tenancy\Actions\AprovisionarEmpresa;
use App\Enums\Rubro;
use App\Models\Empresa;
use App\Models\Plan;
use App\Models\ProveedorExterno;
use App\Models\Sucursal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;

/*
 * Migración desde el sistema anterior de Cyclone (decisión del propietario, 20/11/2026): productos, sucursales, stock
 * (como «Inventario inicial»), clientes y proveedores a una empresa NUEVA. Ventas, caja, créditos y compras empiezan
 * desde cero. El respaldo de Cyclone se simula con tablas TEMPORARY en la misma conexión de la prueba (con la misma
 * estructura que su base, incluidos los precios FLOAT), así quedan dentro de la transacción de RefreshDatabase.
 */

beforeEach(function () {
    Storage::fake('local');
    [$this->empresa, $this->admin] = migracionEmpresaNueva('Cyclone');
    cycloneOrigen();
});

/** Empresa recién creada como la crea el sistema: Casa Matriz (CM) con su caja, unidades base y administrador. */
function migracionEmpresaNueva(string $nombre): array
{
    return app(AprovisionarEmpresa::class)->ejecutar(null, ['nombre' => $nombre, 'rubro' => Rubro::General],
        ['name' => 'Admin '.$nombre, 'email' => 'admin-'.Str::lower(Str::random(8)).'@mozistock.test', 'password' => 'secreto-de-prueba'], true);
}

/**
 * Tablas del respaldo de Cyclone con datos de ejemplo. $extra agrega filas: ['producto' => [[...]], ...].
 *
 * @param  array<string, list<array<string, mixed>>>  $extra
 */
function cycloneOrigen(array $extra = []): void
{
    $tablas = [
        'sucursal' => 'id INT PRIMARY KEY, nombre VARCHAR(255) NOT NULL, codigo_siat TINYINT(1) NOT NULL, direccion TEXT NULL, telefono VARCHAR(15) NULL',
        'unidad_presentacion' => 'id INT PRIMARY KEY, nombre VARCHAR(30) NOT NULL, unidad_medida_siat VARCHAR(50) NOT NULL',
        'linea_produccion' => 'id INT PRIMARY KEY, nombre VARCHAR(30) NOT NULL',
        'producto' => 'id INT PRIMARY KEY, codigo VARCHAR(20) NOT NULL, codigo_sin VARCHAR(20) NULL, codigo_actividad VARCHAR(20) NULL,
            nombre VARCHAR(100) NOT NULL, foto VARCHAR(100) NULL, unidad_presentacion INT NOT NULL, linea_produccion INT NOT NULL,
            stock_minimo INT NOT NULL, stock_maximo INT NULL, costo_produccion FLOAT NOT NULL, precio_minorista FLOAT NOT NULL,
            precio_mayorista FLOAT NOT NULL, precio_consumidor FLOAT NOT NULL, descripcion TEXT NOT NULL, estado TINYINT(1) NOT NULL DEFAULT 1',
        'stock_sucursal' => 'id INT AUTO_INCREMENT PRIMARY KEY, producto INT NOT NULL, sucursal INT NOT NULL, stock INT DEFAULT 0',
        'cliente' => 'id INT PRIMARY KEY, nombre_completo VARCHAR(100) NOT NULL, razon_social VARCHAR(100) NULL, nit VARCHAR(15) NULL,
            email VARCHAR(100) NULL, telefono VARCHAR(20) NULL, direccion TEXT NULL, estado TINYINT(1) NOT NULL',
        'proveedor' => 'id INT PRIMARY KEY, nombre_completo VARCHAR(100) NOT NULL, email VARCHAR(100) NULL, telefono VARCHAR(20) NULL,
            direccion TEXT NULL, estado TINYINT(1) NULL',
    ];

    foreach ($tablas as $tabla => $columnas) {
        DB::statement("DROP TEMPORARY TABLE IF EXISTS {$tabla}");
        DB::statement("CREATE TEMPORARY TABLE {$tabla} ({$columnas})");
    }

    $producto = fn (int $id, string $codigo, string $nombre, int $unidad, int $linea, array $otros = []): array => $otros + [
        'id' => $id, 'codigo' => $codigo, 'codigo_sin' => '35322', 'codigo_actividad' => '202300', 'nombre' => $nombre,
        'foto' => null, 'unidad_presentacion' => $unidad, 'linea_produccion' => $linea, 'stock_minimo' => 5, 'stock_maximo' => null,
        'costo_produccion' => 10, 'precio_minorista' => 20, 'precio_mayorista' => 18, 'precio_consumidor' => 25, 'descripcion' => '', 'estado' => 1,
    ];

    $datos = [
        'sucursal' => [
            ['id' => 1, 'nombre' => 'Sucursal Av. Beni', 'codigo_siat' => 1, 'direccion' => 'Av. Beni 8vo anillo', 'telefono' => '75070807'],
            ['id' => 3, 'nombre' => 'Central Mutualista', 'codigo_siat' => 0, 'direccion' => 'Av. Mutualista', 'telefono' => null],
            ['id' => 6, 'nombre' => 'Fabrica', 'codigo_siat' => 6, 'direccion' => null, 'telefono' => null],
        ],
        'unidad_presentacion' => [
            ['id' => 3, 'nombre' => '900ml', 'unidad_medida_siat' => '34'],
            ['id' => 6, 'nombre' => '4.5L', 'unidad_medida_siat' => '28'],
            ['id' => 23, 'nombre' => 'Litros', 'unidad_medida_siat' => '28'],
            ['id' => 30, 'nombre' => 'Paquete', 'unidad_medida_siat' => '42'],
        ],
        'linea_produccion' => [['id' => 1, 'nombre' => 'Hogar'], ['id' => 2, 'nombre' => 'Automotriz']],
        'producto' => [
            $producto(3, '0dimo05', 'Desincrustante Industrial para Motores', 6, 2, [
                'foto' => '1682479264_DESINCRUSTANTE.png', 'costo_produccion' => 80, 'precio_minorista' => 123.3,
                'precio_mayorista' => 112.1, 'precio_consumidor' => 150, 'descripcion' => 'Quita el sarro de metales.',
            ]),
            $producto(5, '0DIMO09', 'Desincrustante Industrial para Motores 900ml', 3, 2, ['stock_minimo' => 5, 'stock_maximo' => 2]),
            $producto(11, '0CELI09', 'Cera Liquida', 3, 2, ['estado' => 0, 'precio_minorista' => 0, 'precio_mayorista' => 0]),
            $producto(20, 'LAVA01', 'Lavandina a granel', 23, 1, ['precio_consumidor' => 12.9, 'precio_minorista' => 10.55, 'precio_mayorista' => 9.75]),
            $producto(21, 'PAQ01', 'Bolsas', 30, 1, ['precio_consumidor' => 0, 'costo_produccion' => 1.1]),
        ],
        'stock_sucursal' => [
            ['producto' => 3, 'sucursal' => 1, 'stock' => 4],
            ['producto' => 3, 'sucursal' => 3, 'stock' => 10],
            ['producto' => 5, 'sucursal' => 1, 'stock' => -3],
            ['producto' => 11, 'sucursal' => 3, 'stock' => 2],
            ['producto' => 20, 'sucursal' => 6, 'stock' => 7],
            ['producto' => 21, 'sucursal' => 3, 'stock' => 0],
            ['producto' => 99, 'sucursal' => 1, 'stock' => 5],
        ],
        'cliente' => [
            ['id' => 1, 'nombre_completo' => 'Juan Perez', 'razon_social' => 'JP SRL', 'nit' => '1234567', 'email' => 'JUAN@Mail.com', 'telefono' => '777', 'direccion' => 'Calle 1', 'estado' => 1],
            ['id' => 2, 'nombre_completo' => 'Ana', 'razon_social' => '', 'nit' => '0', 'email' => 'correo-malo', 'telefono' => '', 'direccion' => null, 'estado' => 1],
            ['id' => 3, 'nombre_completo' => 'Juan Perez (otra ficha)', 'razon_social' => 'JP SRL', 'nit' => '1234567', 'email' => null, 'telefono' => null, 'direccion' => null, 'estado' => 1],
            ['id' => 4, 'nombre_completo' => 'Otro', 'razon_social' => null, 'nit' => '1234567', 'email' => null, 'telefono' => null, 'direccion' => null, 'estado' => 0],
            ['id' => 5, 'nombre_completo' => '   ', 'razon_social' => null, 'nit' => null, 'email' => null, 'telefono' => null, 'direccion' => null, 'estado' => 1],
        ],
        'proveedor' => [
            ['id' => 2, 'nombre_completo' => 'Maprial', 'email' => null, 'telefono' => null, 'direccion' => 'Parque', 'estado' => null],
            ['id' => 3, 'nombre_completo' => 'Telchi', 'email' => 'ventas@telchi.com', 'telefono' => '123', 'direccion' => null, 'estado' => 0],
        ],
    ];

    foreach ($datos as $tabla => $filas) {
        DB::table($tabla)->insert(array_merge($filas, $extra[$tabla] ?? []));
    }
}

function migrarCyclone(Empresa $empresa, array $opciones = []): PendingCommand
{
    return test()->artisan('mozistock:migrar-cyclone', ['empresa' => $empresa->id, '--conexion' => config('database.default')] + $opciones);
}

function productoMigrado(Empresa $empresa, string $sku): object
{
    return DB::table('productos')->join('unidades_medida', 'unidades_medida.id', '=', 'productos.unidad_medida_id')
        ->leftJoin('categorias', 'categorias.id', '=', 'productos.categoria_id')
        ->where('productos.empresa_id', $empresa->id)->where('productos.sku', $sku)
        ->select('productos.*', 'unidades_medida.abreviatura as unidad', 'categorias.nombre as categoria')->sole();
}

function sucursalMigrada(Empresa $empresa, string $codigo): Sucursal
{
    return Sucursal::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('codigo', $codigo)->sole();
}

function nivelMigrado(Sucursal $sucursal, object $producto): ?string
{
    $cantidad = DB::table('stock_niveles')->where('sucursal_id', $sucursal->id)->where('producto_id', $producto->id)->value('cantidad');

    return $cantidad === null ? null : (string) $cantidad;
}

test('the preview writes nothing and lists what cannot be migrated as is', function () {
    migrarCyclone($this->empresa)
        ->expectsOutputToContain('Vista previa: no se guardó nada')
        ->expectsOutputToContain('Stock negativo en Cyclone: entra como 0')
        ->assertSuccessful();

    foreach (['productos', 'clientes', 'proveedores', 'operaciones_inventario', 'migracion_origenes'] as $tabla) {
        expect(DB::table($tabla)->where('empresa_id', $this->empresa->id)->count())->toBe(0);
    }

    expect(Sucursal::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->pluck('nombre')->all())->toBe(['Casa Matriz']);
    $informe = collect(Storage::disk('local')->files('migraciones'))->sole();
    expect(Storage::disk('local')->get($informe))
        ->toContain('0DIMO09 Desincrustante Industrial para Motores en Sucursal Av. Beni: -3')
        ->toContain('Otro: NIT 1234567 ya es de «JP SRL»');
});

test('it migrates branches, products and initial stock as one inventory entry per branch', function () {
    migrarCyclone($this->empresa, ['--ejecutar' => true, '--sin-fotos' => true])->expectsOutputToContain('Migración guardada.')->assertSuccessful();

    // La casa matriz (código SIAT 0) reutiliza «Casa Matriz»; las demás son nuevas, cada una con su caja principal.
    $matriz = sucursalMigrada($this->empresa, 'CM');
    $beni = sucursalMigrada($this->empresa, 'S1');
    $fabrica = sucursalMigrada($this->empresa, 'S6');
    expect($matriz->nombre)->toBe('Central Mutualista')->and($matriz->direccion)->toBe('Av. Mutualista')
        ->and($beni->nombre)->toBe('Sucursal Av. Beni')->and($fabrica->direccion)->toBeNull()
        ->and($beni->telefono)->toBe('75070807')->and($matriz->telefono)->toBeNull()
        ->and(Sucursal::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count())->toBe(3);
    foreach ([$matriz, $beni, $fabrica] as $sucursal) {
        expect(DB::table('cajas')->where('sucursal_id', $sucursal->id)->count())->toBe(1);
    }

    // Presentación interpretada como en el catálogo de las distribuidoras; precios FLOAT pasados a DECIMAL sin desvío.
    $motor = productoMigrado($this->empresa, '0DIMO05');
    expect($motor->nombre)->toBe('Desincrustante Industrial para Motores')->and($motor->contenido)->toBe('4.500')
        ->and($motor->unidad)->toBe('L')->and($motor->tipo_venta)->toBe('unidad')->and($motor->categoria)->toBe('Automotriz')
        ->and($motor->precio_consumidor)->toBe('150.00')->and($motor->precio_minorista)->toBe('123.30')
        ->and($motor->precio_mayorista)->toBe('112.10')->and($motor->costo_promedio)->toBe('80.000000')
        ->and($motor->stock_minimo)->toBe('5.000')->and($motor->descripcion)->toBe('Quita el sarro de metales.')
        ->and((bool) $motor->activo)->toBeTrue()->and((bool) $motor->controla_stock)->toBeTrue();

    $chico = productoMigrado($this->empresa, '0DIMO09');
    expect($chico->nombre)->toBe('Desincrustante Industrial para Motores')->and($chico->contenido)->toBe('900.000')
        ->and($chico->unidad)->toBe('ml')->and($chico->stock_maximo)->toBeNull();

    $cera = productoMigrado($this->empresa, '0CELI09');
    expect((bool) $cera->activo)->toBeFalse()->and($cera->precio_minorista)->toBeNull()->and($cera->precio_mayorista)->toBeNull();

    $granel = productoMigrado($this->empresa, 'LAVA01');
    expect($granel->unidad)->toBe('L')->and($granel->tipo_venta)->toBe('medida')->and($granel->contenido)->toBeNull()
        ->and($granel->categoria)->toBe('Hogar')->and($granel->precio_consumidor)->toBe('12.90')->and($granel->precio_minorista)->toBe('10.55');

    $bolsas = productoMigrado($this->empresa, 'PAQ01');
    expect($bolsas->unidad)->toBe('paq.')->and($bolsas->precio_consumidor)->toBe('0.00')->and($bolsas->costo_promedio)->toBe('1.100000');

    // Stock: solo las cantidades positivas; la negativa entra como 0 (sin saldo) y la de un producto inexistente no entra.
    expect(nivelMigrado($beni, $motor))->toBe('4.000')
        ->and(nivelMigrado($matriz, $motor))->toBe('10.000')
        ->and(nivelMigrado($beni, $chico))->toBeNull()
        ->and(nivelMigrado($matriz, $cera))->toBe('2.000')
        ->and(nivelMigrado($fabrica, $granel))->toBe('7.000')
        ->and(nivelMigrado($matriz, $bolsas))->toBeNull()
        ->and(DB::table('stock_niveles')->where('empresa_id', $this->empresa->id)->sum('cantidad'))->toBe('23.000');

    $operaciones = DB::table('operaciones_inventario')->where('empresa_id', $this->empresa->id)->orderBy('id')->get();
    expect($operaciones)->toHaveCount(3)
        ->and($operaciones->pluck('tipo')->unique()->all())->toBe(['entrada'])
        ->and($operaciones->pluck('motivo')->unique()->all())->toBe(['inventario_inicial'])
        ->and($operaciones->pluck('usuario_id')->unique()->all())->toBe([$this->admin->id])
        ->and($operaciones->pluck('sucursal_id')->sort()->values()->all())->toBe(collect([$matriz->id, $beni->id, $fabrica->id])->sort()->values()->all());
    expect(DB::table('movimientos_stock')->where('empresa_id', $this->empresa->id)->count())->toBe(4)
        ->and(DB::table('movimientos_stock')->where('empresa_id', $this->empresa->id)->sum('cantidad'))->toBe('23.000');

    // Equivalencias de origen: base para enlazar después el catálogo de las distribuidoras.
    expect(DB::table('migracion_origenes')->where('empresa_id', $this->empresa->id)->where('entidad', 'producto')
        ->where('origen_id', '3')->value('destino_id'))->toBe($motor->id)
        ->and(DB::table('migracion_origenes')->where('empresa_id', $this->empresa->id)->where('entidad', 'sucursal')->count())->toBe(3)
        ->and(DB::table('auditoria')->where('empresa_id', $this->empresa->id)->where('accion', 'empresa.migrada_desde_cyclone')->count())->toBe(1);
});

test('the initial stock is not a sale, a purchase nor a cash movement', function () {
    migrarCyclone($this->empresa, ['--ejecutar' => true, '--sin-fotos' => true])->assertSuccessful();

    foreach (['ventas', 'pagos_venta', 'movimientos_caja', 'compras'] as $tabla) {
        expect(DB::table($tabla)->where('empresa_id', $this->empresa->id)->count())->toBe(0);
    }
});

test('it migrates clients and suppliers with the agreed rules', function () {
    migrarCyclone($this->empresa, ['--ejecutar' => true, '--sin-fotos' => true])->assertSuccessful();

    $clientes = DB::table('clientes')->where('empresa_id', $this->empresa->id)->orderBy('id')->get();
    // La razón social es el nombre si existe; mismo NIT y nombre se guarda una vez; NIT repetido con otro nombre queda vacío.
    expect($clientes->map(fn ($c) => [$c->nombre, $c->documento, $c->telefono, $c->correo, $c->direccion, (bool) $c->activo])->all())->toBe([
        ['JP SRL', '1234567', '777', 'juan@mail.com', 'Calle 1', true],
        ['Ana', null, null, null, null, true],
        ['Otro', null, null, null, null, false],
    ]);

    $proveedores = DB::table('proveedores')->where('empresa_id', $this->empresa->id)->orderBy('id')->get();
    expect($proveedores->map(fn ($p) => [$p->nombre, $p->correo, $p->telefono, $p->direccion, (bool) $p->activo])->all())->toBe([
        ['Maprial', null, null, 'Parque', true],
        ['Telchi', 'ventas@telchi.com', '123', null, false],
    ]);
});

test('running it again never duplicates products, clients or stock', function () {
    migrarCyclone($this->empresa, ['--ejecutar' => true, '--sin-fotos' => true])->assertSuccessful();

    migrarCyclone($this->empresa, ['--ejecutar' => true, '--sin-fotos' => true])
        ->expectsOutputToContain('Esta empresa ya fue migrada desde Cyclone')
        ->assertFailed();

    expect(DB::table('productos')->where('empresa_id', $this->empresa->id)->count())->toBe(5)
        ->and(DB::table('clientes')->where('empresa_id', $this->empresa->id)->count())->toBe(3)
        ->and(DB::table('operaciones_inventario')->where('empresa_id', $this->empresa->id)->count())->toBe(3)
        ->and(DB::table('stock_niveles')->where('empresa_id', $this->empresa->id)->sum('cantidad'))->toBe('23.000');
});

test('another company is never touched and its administrator cannot run the migration for this one', function () {
    [$otra, $adminOtra] = migracionEmpresaNueva('Otra empresa');
    $suyo = productoDe($otra, ['sku' => '0DIMO05', 'nombre' => 'Producto de la otra empresa']);
    $matrizOtra = Sucursal::withoutGlobalScopes()->where('empresa_id', $otra->id)->sole();
    $this->actingAs($adminOtra);
    cargarStock($adminOtra, $matrizOtra, $suyo, '9');
    auth()->logout();

    migrarCyclone($this->empresa, ['--ejecutar' => true, '--usuario' => $adminOtra->email])
        ->expectsOutputToContain('no tiene un administrador activo con ese correo')->assertFailed();
    expect(DB::table('productos')->where('empresa_id', $this->empresa->id)->count())->toBe(0);

    // La otra empresa ya tiene productos: no recibe la migración.
    migrarCyclone($otra, ['--ejecutar' => true])->expectsOutputToContain('la migración es solo para una empresa nueva')->assertFailed();

    migrarCyclone($this->empresa, ['--ejecutar' => true, '--sin-fotos' => true])->assertSuccessful();

    expect(DB::table('productos')->where('empresa_id', $otra->id)->pluck('nombre')->all())->toBe(['Producto de la otra empresa'])
        ->and(DB::table('stock_niveles')->where('empresa_id', $otra->id)->sum('cantidad'))->toBe('9.000')
        ->and(Sucursal::withoutGlobalScopes()->where('empresa_id', $otra->id)->count())->toBe(1)
        ->and(DB::table('clientes')->where('empresa_id', $otra->id)->count())->toBe(0)
        ->and(DB::table('migracion_origenes')->where('empresa_id', $otra->id)->count())->toBe(0);
});

test('errors in the backup or a branch limit of the plan stop the migration without writing anything', function () {
    cycloneOrigen(['producto' => [[
        'id' => 50, 'codigo' => '0DIMO05', 'codigo_sin' => null, 'codigo_actividad' => null, 'nombre' => 'Repetido', 'foto' => null,
        'unidad_presentacion' => 3, 'linea_produccion' => 1, 'stock_minimo' => 0, 'stock_maximo' => null, 'costo_produccion' => 1,
        'precio_minorista' => 1, 'precio_mayorista' => 1, 'precio_consumidor' => 1, 'descripcion' => '', 'estado' => 1,
    ]]]);

    migrarCyclone($this->empresa, ['--ejecutar' => true])->expectsOutputToContain('El código 0DIMO05 está repetido')->assertFailed();
    expect(DB::table('productos')->where('empresa_id', $this->empresa->id)->count())->toBe(0);

    cycloneOrigen();
    $plan = Plan::factory()->create(['codigo' => 'plan-una-sucursal', 'sucursales_incluidas' => 1]);
    DB::table('suscripciones')->insert([
        'empresa_id' => $this->empresa->id, 'plan_id' => $plan->id, 'precio_mensual' => '150.00', 'sucursales_extra' => 0,
        'dia_vencimiento' => 31, 'vencimiento' => '2099-12-31', 'created_at' => now(), 'updated_at' => now(),
    ]);

    migrarCyclone($this->empresa, ['--ejecutar' => true])->expectsOutputToContain('tendría 3 sucursales activas y su plan permite 1')->assertFailed();
    expect(DB::table('productos')->where('empresa_id', $this->empresa->id)->count())->toBe(0)
        ->and(Sucursal::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count())->toBe(1);
});

test('photos come from the central catalog copy into the private storage of the company, once', function () {
    $proveedor = ProveedorExterno::query()->where('codigo', 'cyclone')->firstOrFail();
    $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    Storage::disk('local')->put("catalogo_central/{$proveedor->id}/3.png", $png);
    DB::table('catalogo_externo_productos')->insert([
        'proveedor_externo_id' => $proveedor->id, 'externo_id' => '3', 'nombre_original' => 'Desincrustante', 'nombre_limpio' => 'Desincrustante',
        'foto_url' => null, 'foto_cache_path' => "catalogo_central/{$proveedor->id}/3.png", 'hash' => str_repeat('a', 64),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    migrarCyclone($this->empresa, ['--ejecutar' => true])->expectsOutputToContain('Fotos copiadas: 1.')->assertSuccessful();

    $imagen = productoMigrado($this->empresa, '0DIMO05')->imagen_path;
    expect($imagen)->toStartWith("empresas/{$this->empresa->id}/productos/")->toEndWith('.png')
        ->and(Storage::disk('local')->get($imagen))->toBe($png)
        ->and(productoMigrado($this->empresa, '0DIMO09')->imagen_path)->toBeNull();

    // Repetir solo completa productos sin imagen: no crea otro archivo.
    $plan = app(PlanMigracionCyclone::class)->construir(new OrigenCyclone(DB::connection()));
    $fotos = collect($plan['productos'])->whereNotNull('foto')->pluck('foto', 'origen_id')->all();
    expect(app(FotosCyclone::class)->copiar($this->empresa, $fotos)['copiadas'])->toBe(0)
        ->and(Storage::disk('local')->files("empresas/{$this->empresa->id}/productos"))->toHaveCount(1);
});
