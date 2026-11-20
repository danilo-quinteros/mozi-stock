<?php

use App\Domain\Migracion\Cyclone\PlanMigracionCyclone;
use App\Domain\Migracion\Cyclone\RespaldoSqlCyclone;
use App\Domain\Tenancy\Actions\AprovisionarEmpresa;
use App\Enums\Rubro;
use App\Livewire\Admin\Empresas;
use App\Livewire\Admin\MigracionCyclone;
use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Plan;
use App\Models\ProveedorExterno;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Pantalla de Superadmin para migrar Cyclone sin consola (pedido del propietario, 20/11/2026): se sube el respaldo .sql
 * que exporta phpMyAdmin, se revisa la vista previa y se migra con un botón. El archivo nunca se ejecuta: se leen solo
 * los valores de los INSERT de las 7 tablas de Cyclone. La migración es la misma del comando (MigrarCycloneTest).
 */

beforeEach(function () {
    Storage::fake('local');
    $this->superadmin = User::factory()->superadmin()->create();
    [$this->empresa, $this->admin] = app(AprovisionarEmpresa::class)->ejecutar(null, ['nombre' => 'Cyclone', 'rubro' => Rubro::General],
        ['name' => 'Admin Cyclone', 'email' => 'admin-'.Str::lower(Str::random(8)).'@mozistock.test', 'password' => 'secreto-de-prueba'], true);
});

/** Valor literal como lo escribe phpMyAdmin (\' \\ \n dentro de los textos). */
function sqlCyclone(mixed $valor): string
{
    return match (true) {
        $valor === null => 'NULL',
        is_int($valor), is_float($valor) => (string) $valor,
        default => "'".str_replace(['\\', "'", "\n"], ['\\\\', "\\'", '\\n'], (string) $valor)."'",
    };
}

/**
 * Respaldo de Cyclone con la forma exacta que exporta phpMyAdmin (estructura de cyclone.sql y un INSERT por tabla).
 *
 * @param  list<array<string, mixed>>  $productosExtra
 */
function respaldoCyclone(array $productosExtra = [], string $intercalado = ''): string
{
    $tablas = [
        'sucursal' => ['id' => 'int(11) NOT NULL', 'nombre' => 'varchar(255) NOT NULL', 'codigo_siat' => 'tinyint(1) NOT NULL',
            'direccion' => 'text', 'telefono' => 'varchar(15) DEFAULT NULL', 'created_at' => 'timestamp NULL DEFAULT NULL', 'updated_at' => 'timestamp NULL DEFAULT NULL'],
        'unidad_presentacion' => ['id' => 'int(11) NOT NULL', 'nombre' => 'varchar(30) NOT NULL', 'unidad_medida_siat' => 'varchar(50) NOT NULL',
            'created_at' => 'timestamp NULL DEFAULT NULL', 'updated_at' => 'timestamp NULL DEFAULT NULL'],
        'linea_produccion' => ['id' => 'int(11) NOT NULL', 'nombre' => 'varchar(30) NOT NULL', 'created_at' => 'timestamp NULL DEFAULT NULL', 'updated_at' => 'timestamp NULL DEFAULT NULL'],
        'producto' => ['id' => 'int(11) NOT NULL', 'codigo' => 'varchar(20) NOT NULL', 'codigo_sin' => 'varchar(20) DEFAULT NULL', 'codigo_actividad' => 'varchar(20) DEFAULT NULL',
            'nombre' => 'varchar(100) NOT NULL', 'foto' => 'varchar(100) DEFAULT NULL', 'unidad_presentacion' => 'int(11) NOT NULL', 'linea_produccion' => 'int(11) NOT NULL',
            'stock_minimo' => 'int(11) NOT NULL', 'stock_maximo' => 'int(11) DEFAULT NULL', 'costo_produccion' => 'float NOT NULL', 'precio_minorista' => 'float NOT NULL',
            'precio_mayorista' => 'float NOT NULL', 'precio_consumidor' => 'float NOT NULL', 'descripcion' => 'text NOT NULL', 'estado' => "tinyint(1) NOT NULL DEFAULT '1'",
            'created_at' => 'timestamp NULL DEFAULT NULL', 'updated_at' => 'timestamp NULL DEFAULT NULL'],
        'stock_sucursal' => ['id' => 'int(11) NOT NULL', 'producto' => 'int(11) NOT NULL', 'sucursal' => 'int(11) NOT NULL', 'stock' => "int(11) DEFAULT '0'",
            'created_at' => 'timestamp NULL DEFAULT NULL', 'updated_at' => 'timestamp NULL DEFAULT NULL'],
        'cliente' => ['id' => 'int(11) NOT NULL', 'nombre_completo' => 'varchar(100) NOT NULL', 'razon_social' => 'varchar(100) DEFAULT NULL', 'nit' => 'varchar(15) DEFAULT NULL',
            'email' => 'varchar(100) DEFAULT NULL', 'telefono' => 'varchar(20) DEFAULT NULL', 'direccion' => 'text', 'estado' => 'tinyint(1) NOT NULL',
            'created_at' => 'timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP', 'updated_at' => 'timestamp NULL DEFAULT NULL'],
        'proveedor' => ['id' => 'int(11) NOT NULL', 'nombre_completo' => 'varchar(100) NOT NULL', 'email' => 'varchar(100) DEFAULT NULL', 'telefono' => 'varchar(20) DEFAULT NULL',
            'direccion' => 'text', 'estado' => 'tinyint(1) DEFAULT NULL', 'created_at' => 'timestamp NULL DEFAULT NULL', 'updated_at' => 'timestamp NULL DEFAULT NULL'],
    ];

    $producto = fn (int $id, string $codigo, string $nombre, array $otros = []): array => $otros + [
        'id' => $id, 'codigo' => $codigo, 'codigo_sin' => '35322', 'codigo_actividad' => '202300', 'nombre' => $nombre, 'foto' => null,
        'unidad_presentacion' => 3, 'linea_produccion' => 1, 'stock_minimo' => 5, 'stock_maximo' => null, 'costo_produccion' => 10,
        'precio_minorista' => 20, 'precio_mayorista' => 18, 'precio_consumidor' => 25, 'descripcion' => '', 'estado' => 1,
        'created_at' => '2023-04-26 03:21:04', 'updated_at' => null,
    ];

    $datos = [
        'sucursal' => [
            ['id' => 1, 'nombre' => 'Sucursal Av. Beni', 'codigo_siat' => 1, 'direccion' => 'Av. Beni 8vo anillo', 'telefono' => '75070807', 'created_at' => null, 'updated_at' => null],
            ['id' => 3, 'nombre' => 'Central Mutualista', 'codigo_siat' => 0, 'direccion' => 'Av. Mutualista', 'telefono' => null, 'created_at' => null, 'updated_at' => null],
        ],
        'unidad_presentacion' => [
            ['id' => 3, 'nombre' => '900ml', 'unidad_medida_siat' => '34', 'created_at' => null, 'updated_at' => null],
            ['id' => 23, 'nombre' => 'Litros', 'unidad_medida_siat' => '28', 'created_at' => null, 'updated_at' => null],
            ['id' => 30, 'nombre' => 'Paquete', 'unidad_medida_siat' => '42', 'created_at' => null, 'updated_at' => null],
        ],
        'linea_produccion' => [
            ['id' => 1, 'nombre' => 'Hogar', 'created_at' => null, 'updated_at' => null],
            ['id' => 2, 'nombre' => 'Automotriz', 'created_at' => null, 'updated_at' => null],
        ],
        'producto' => [
            // Textos con «;», paréntesis, comas, comillas escapadas, saltos de línea y barras: no deben cortar nada.
            $producto(3, '0dimo05', "Cera 'Brillo'; (x), (y)", [
                'foto' => '1682479264_DESINCRUSTANTE.png', 'linea_produccion' => 2, 'costo_produccion' => 80, 'precio_minorista' => 123.3,
                'precio_mayorista' => 112.1, 'precio_consumidor' => 150, 'descripcion' => "Quita el sarro.\nUso industrial \\ diluido",
            ]),
            $producto(5, 'LAVA01', 'Ñandú Lavandina', ['unidad_presentacion' => 23, 'costo_produccion' => 1.1, 'precio_minorista' => 10.55, 'precio_mayorista' => 9.75, 'precio_consumidor' => 12.9]),
            ...array_map(fn (array $p): array => $producto($p['id'], $p['codigo'], $p['nombre'], $p), $productosExtra),
        ],
        'stock_sucursal' => [
            ['id' => 1, 'producto' => 3, 'sucursal' => 1, 'stock' => 4, 'created_at' => null, 'updated_at' => null],
            ['id' => 2, 'producto' => 3, 'sucursal' => 3, 'stock' => 10, 'created_at' => null, 'updated_at' => null],
            ['id' => 3, 'producto' => 5, 'sucursal' => 1, 'stock' => -3, 'created_at' => null, 'updated_at' => null],
            ['id' => 4, 'producto' => 5, 'sucursal' => 3, 'stock' => 7, 'created_at' => null, 'updated_at' => null],
        ],
        'cliente' => [
            ['id' => 1, 'nombre_completo' => 'Juan Perez', 'razon_social' => 'JP SRL', 'nit' => '1234567', 'email' => 'JUAN@Mail.com', 'telefono' => '777',
                'direccion' => 'Calle 1; esquina (2), frente', 'estado' => 1, 'created_at' => '2024-01-01 10:00:00', 'updated_at' => null],
        ],
        'proveedor' => [
            ['id' => 2, 'nombre_completo' => 'Maprial', 'email' => null, 'telefono' => null, 'direccion' => 'Parque Industrial', 'estado' => null, 'created_at' => null, 'updated_at' => null],
        ],
    ];

    $sql = "-- phpMyAdmin SQL Dump\n-- version 5.2.3\n\nSET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\nSTART TRANSACTION;\nSET time_zone = \"+00:00\";\n\n"
        ."/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n/*!40101 SET NAMES utf8mb4 */;\n\n";

    foreach ($tablas as $tabla => $columnas) {
        $sql .= "--\n-- Estructura de tabla para la tabla `{$tabla}`\n--\n\nCREATE TABLE `{$tabla}` (\n"
            .implode(",\n", array_map(fn (string $c, string $tipo): string => "  `{$c}` {$tipo}", array_keys($columnas), $columnas))
            ."\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\n";

        if ($tabla === 'producto') {
            $sql .= $intercalado."\n";
        }

        $sql .= "--\n-- Volcado de datos para la tabla `{$tabla}`\n--\n\nINSERT INTO `{$tabla}` (`".implode('`, `', array_keys($columnas))."`) VALUES\n"
            .implode(",\n", array_map(fn (array $fila): string => '('.implode(', ', array_map(fn (string $c): string => sqlCyclone($fila[$c]), array_keys($columnas))).')', $datos[$tabla]))
            .";\n\n";
    }

    // Un cliente con comilla doblada (''), como también la escribe MySQL.
    $sql .= "INSERT INTO `cliente` (`id`, `nombre_completo`, `razon_social`, `nit`, `email`, `telefono`, `direccion`, `estado`, `created_at`, `updated_at`) VALUES\n"
        ."(2, 'Tienda O''Brien', NULL, '0', NULL, NULL, 'Línea 1\\nLínea 2', 1, NULL, NULL);\n\n";

    return $sql."ALTER TABLE `cliente`\n  ADD PRIMARY KEY (`id`);\nCOMMIT;\n";
}

function leerRespaldoCyclone(string $sql): array
{
    Storage::disk('local')->put('pruebas/respaldo.sql', $sql);
    $lector = app(RespaldoSqlCyclone::class);

    try {
        return app(PlanMigracionCyclone::class)->construir($lector->cargar(Storage::disk('local')->path('pruebas/respaldo.sql')));
    } finally {
        $lector->liberar();
    }
}

function subirRespaldoCyclone(string $sql, string $nombre = 'cyclone_datos.sql'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($nombre, $sql);
}

function stockMigradoCyclone(Empresa $empresa, string $codigoSucursal, string $sku): ?string
{
    return DB::table('stock_niveles')
        ->join('sucursales', 'sucursales.id', '=', 'stock_niveles.sucursal_id')
        ->join('productos', 'productos.id', '=', 'stock_niveles.producto_id')
        ->where('sucursales.empresa_id', $empresa->id)->where('sucursales.codigo', $codigoSucursal)->where('productos.sku', $sku)
        ->value('stock_niveles.cantidad');
}

test('the backup is read value by value: quotes, semicolons and line breaks inside texts are kept exactly', function () {
    $plan = leerRespaldoCyclone(respaldoCyclone());
    $cera = collect($plan['productos'])->firstWhere('sku', '0DIMO05');
    $lavandina = collect($plan['productos'])->firstWhere('sku', 'LAVA01');

    expect($cera['nombre'])->toBe("Cera 'Brillo'; (x), (y)")
        ->and($cera['descripcion'])->toBe("Quita el sarro.\nUso industrial \\ diluido")
        ->and($cera['precio_minorista'])->toBe('123.30')
        ->and($cera['precio_mayorista'])->toBe('112.10')
        ->and($cera['foto'])->toBe('1682479264_DESINCRUSTANTE.png')
        ->and($lavandina['nombre'])->toBe('Ñandú Lavandina')
        ->and($lavandina['precio_minorista'])->toBe('10.55')
        ->and($lavandina['costo'])->toBe('1.10')
        ->and(collect($plan['clientes'])->pluck('direccion', 'nombre')->all())
        ->toBe(['JP SRL' => 'Calle 1; esquina (2), frente', "Tienda O'Brien" => "Línea 1\nLínea 2"])
        ->and($plan['stock'][3])->toHaveCount(2)
        ->and($plan['observaciones'])->toHaveKey('stock_negativo');

    // Sin lista de columnas (formato de mysqldump): se usan las del CREATE TABLE del mismo archivo.
    $sinColumnas = (string) preg_replace('/INSERT INTO (`\w+`) \([^)]*\) VALUES/', 'INSERT INTO $1 VALUES', respaldoCyclone());
    expect(json_encode(leerRespaldoCyclone($sinColumnas)))->toBe(json_encode($plan));
});

test('the backup is never executed: other statements and tables are ignored', function () {
    $usuarios = User::query()->count();
    $hostil = "DROP TABLE `users`;\nUPDATE `empresas` SET `activa` = 0;\nDELETE FROM `productos`;\n"
        ."INSERT INTO `users` (`id`, `name`, `email`) VALUES (999999, 'Intruso', 'intruso@mozistock.test');\n"
        ."CREATE TABLE `intrusa` (`id` int(11) NOT NULL);\n";

    $plan = leerRespaldoCyclone(respaldoCyclone([], $hostil));

    expect($plan['errores'])->toBe([])
        ->and(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasTable('intrusa'))->toBeFalse()
        ->and(User::query()->count())->toBe($usuarios)
        ->and(Empresa::query()->where('activa', false)->count())->toBe(0);
});

test('an unreadable backup gives a clear message in spanish', function (string $sql, string $mensaje) {
    expect(fn () => leerRespaldoCyclone($sql))->toThrow(ValidationException::class, $mensaje);
})->with([
    'no es de Cyclone' => ['SELECT 1;', 'no trae las tablas sucursal, unidad_presentacion'],
    'una función en vez de un valor' => [str_replace("'Maprial'", 'NOW()', respaldoCyclone()), 'No se pudo leer un valor de la tabla proveedor'],
    'una subconsulta' => [str_replace("'Maprial'", '(SELECT password FROM users LIMIT 1)', respaldoCyclone()), 'No se pudo leer un valor de la tabla proveedor'],
    'un texto sin cerrar' => [respaldoCyclone()."INSERT INTO `linea_produccion` (`id`, `nombre`) VALUES (9, 'sin cierre);\n", 'texto sin cerrar'],
    'faltan valores' => [str_replace("(2, 'Tienda O''Brien', NULL,", "(2, 'Tienda O''Brien',", respaldoCyclone()), 'tiene 9 valores y se esperaban 10'],
    'falta una columna' => [str_replace('`precio_consumidor`, `descripcion`', '`descripcion`', respaldoCyclone()), 'falta la columna precio_consumidor'],
]);

test('only the superadmin opens the migration screen', function () {
    $vendedor = User::factory()->vendedor(Sucursal::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->firstOrFail())->create();

    $this->actingAs($this->admin)->get(route('admin.migracion-cyclone'))->assertForbidden();
    $this->actingAs($vendedor)->get(route('admin.migracion-cyclone'))->assertForbidden();
    Livewire::actingAs($this->admin)->test(MigracionCyclone::class)->assertForbidden();

    $this->actingAs($this->superadmin)->get(route('admin.migracion-cyclone'))->assertOk()->assertSee('Migrar desde Cyclone');
    Livewire::actingAs($this->superadmin)->test(Empresas::class)->assertSeeHtml(route('admin.migracion-cyclone'));
});

test('the superadmin uploads the backup, reviews the preview and migrates with one button', function () {
    $pantalla = Livewire::actingAs($this->superadmin)->test(MigracionCyclone::class)
        ->set('empresaId', $this->empresa->id)
        ->set('archivo', subirRespaldoCyclone(respaldoCyclone()))
        ->assertHasNoErrors()
        ->assertSet('respaldoNombre', 'cyclone_datos.sql')
        ->assertSee('Vista previa: todavía no se guardó nada')
        ->assertSee('Sucursal Av. Beni')->assertSee('Reutiliza «Casa Matriz»')
        ->assertSee('Stock negativo en Cyclone: entra como 0')
        ->call('descargarInforme')->assertFileDownloaded('informe-migracion-cyclone.txt');

    // La vista previa no escribe nada; el respaldo queda solo en el almacenamiento privado.
    expect(DB::table('productos')->where('empresa_id', $this->empresa->id)->count())->toBe(0)
        ->and(DB::table('migracion_origenes')->count())->toBe(0)
        ->and(Storage::disk('local')->files('migraciones/respaldos'))->toHaveCount(1);

    $pantalla->call('confirmar')->assertSet('confirmando', true)->assertSee('Migrar ahora')
        ->call('migrar')
        ->assertHasNoErrors()
        ->assertSet('confirmando', false)
        ->assertSet('respaldo', null)
        ->assertSet('vista', [])
        ->assertSee('Migración guardada')
        ->assertDontSee('todavía no se guardó nada')
        ->assertSee('no hace falta subir el respaldo de nuevo');

    expect(DB::table('productos')->where('empresa_id', $this->empresa->id)->pluck('sku')->sort()->values()->all())->toBe(['0DIMO05', 'LAVA01'])
        ->and(stockMigradoCyclone($this->empresa, 'CM', '0DIMO05'))->toBe('10.000')
        ->and(stockMigradoCyclone($this->empresa, 'S1', '0DIMO05'))->toBe('4.000')
        ->and(stockMigradoCyclone($this->empresa, 'S1', 'LAVA01'))->toBeNull()
        ->and(DB::table('clientes')->where('empresa_id', $this->empresa->id)->count())->toBe(2)
        ->and(DB::table('proveedores')->where('empresa_id', $this->empresa->id)->value('direccion'))->toBe('Parque Industrial')
        ->and(DB::table('ventas')->where('empresa_id', $this->empresa->id)->count())->toBe(0)
        // El respaldo (datos personales) se borra al migrar; el informe queda como el del comando.
        ->and(Storage::disk('local')->files('migraciones/respaldos'))->toBe([])
        ->and(Storage::disk('local')->files('migraciones'))->toHaveCount(1);

    $auditoria = Auditoria::query()->where('accion', 'empresa.migrada_desde_cyclone')->sole();
    expect($auditoria->usuario_id)->toBe($this->admin->id)
        ->and($auditoria->despues)->toMatchArray(['desde' => 'superadmin', 'superadmin_id' => $this->superadmin->id, 'archivo' => 'cyclone_datos.sql', 'productos' => 2]);

    // Volver a subir el respaldo no migra dos veces, ni desde la pantalla ni llamando a la acción directamente.
    Livewire::actingAs($this->superadmin)->test(MigracionCyclone::class)
        ->set('empresaId', $this->empresa->id)
        ->set('archivo', subirRespaldoCyclone(respaldoCyclone()))
        ->assertSee('Esta empresa ya fue migrada desde Cyclone')
        ->call('confirmar')->assertSet('confirmando', false)
        ->call('migrar')->assertHasErrors('migracion');

    expect(DB::table('productos')->where('empresa_id', $this->empresa->id)->count())->toBe(2)
        ->and(DB::table('clientes')->where('empresa_id', $this->empresa->id)->count())->toBe(2)
        ->and(Auditoria::query()->where('accion', 'empresa.migrada_desde_cyclone')->count())->toBe(1);
});

test('the plan limit or a company with data stop the migration even calling the action directly', function () {
    $plan = Plan::factory()->create(['codigo' => 'plan-una-sucursal-pantalla', 'sucursales_incluidas' => 1]);
    DB::table('suscripciones')->insert([
        'empresa_id' => $this->empresa->id, 'plan_id' => $plan->id, 'precio_mensual' => '150.00', 'sucursales_extra' => 0,
        'dia_vencimiento' => 31, 'vencimiento' => '2099-12-31', 'created_at' => now(), 'updated_at' => now(),
    ]);

    Livewire::actingAs($this->superadmin)->test(MigracionCyclone::class)
        ->set('empresaId', $this->empresa->id)
        ->set('archivo', subirRespaldoCyclone(respaldoCyclone([['id' => 7, 'codigo' => 'PAQ01', 'nombre' => 'Bolsas', 'unidad_presentacion' => 30]])))
        ->assertSee('Unidades nuevas: paq.')->assertDontSee('paq..')
        ->assertSee('tendría 2 sucursales activas y su plan permite 1')
        ->assertSee('Ir a Suscripciones para agregar sucursales extra')
        ->call('confirmar')->assertSet('confirmando', false)
        ->call('migrar')->assertHasErrors('migracion');

    expect(DB::table('productos')->where('empresa_id', $this->empresa->id)->count())->toBe(0)
        ->and(DB::table('sucursales')->where('empresa_id', $this->empresa->id)->count())->toBe(1);

    // Otra empresa que ya tiene datos no aparece entre las disponibles y no recibe la migración.
    [$conDatos] = empresaConAdministrador(['nombre' => 'Distribuidora con datos']);
    productoDe($conDatos);

    Livewire::actingAs($this->superadmin)->test(MigracionCyclone::class)
        ->assertDontSee('Distribuidora con datos')
        ->set('empresaId', $conDatos->id)
        ->assertSee('ya tiene datos')
        ->set('archivo', subirRespaldoCyclone(respaldoCyclone()))
        ->assertSee('La empresa ya tiene 1 productos')
        ->call('migrar')->assertHasErrors('migracion');

    expect(DB::table('productos')->where('empresa_id', $conDatos->id)->count())->toBe(1)
        ->and(DB::table('migracion_origenes')->count())->toBe(0);
});

test('a wrong file is rejected and never kept', function () {
    Livewire::actingAs($this->superadmin)->test(MigracionCyclone::class)
        ->set('archivo', UploadedFile::fake()->createWithContent('programa.exe', 'MZ'))
        ->assertHasErrors(['archivo' => 'extensions'])
        ->set('archivo', subirRespaldoCyclone("SELECT 1;\n", 'otro.sql'))
        ->assertHasErrors('respaldo')
        ->assertSee('no parece un respaldo de Cyclone')
        ->assertSet('respaldo', null)
        ->assertSet('vista', []);

    expect(Storage::disk('local')->files('migraciones/respaldos'))->toBe([]);
});

test('photos are copied in small batches and can be resumed after closing the page', function () {
    $proveedor = ProveedorExterno::query()->where('codigo', 'cyclone')->firstOrFail();
    $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    $extras = array_map(fn (int $id): array => ['id' => $id, 'codigo' => "FOTO{$id}", 'nombre' => "Producto con foto {$id}", 'foto' => "{$id}.png"], range(100, 107));

    foreach ([3, ...range(100, 107)] as $id) {
        Storage::disk('local')->put("catalogo_central/{$proveedor->id}/{$id}.png", $png);
        DB::table('catalogo_externo_productos')->insert([
            'proveedor_externo_id' => $proveedor->id, 'externo_id' => (string) $id, 'nombre_original' => "Producto {$id}", 'nombre_limpio' => "Producto {$id}",
            'foto_url' => null, 'foto_cache_path' => "catalogo_central/{$proveedor->id}/{$id}.png", 'hash' => str_repeat('a', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    Livewire::actingAs($this->superadmin)->test(MigracionCyclone::class)
        ->set('empresaId', $this->empresa->id)
        ->set('archivo', subirRespaldoCyclone(respaldoCyclone($extras)))
        ->call('migrar')
        ->assertHasNoErrors()
        ->assertSet('copiandoFotos', true)
        ->assertSet('fotos.total', 9)
        ->assertSee('Copiando fotos: 0 de 9');

    // Se cerró la página antes de copiar: al volver, la empresa ofrece completar las que faltan.
    $pantalla = Livewire::actingAs($this->superadmin)->test(MigracionCyclone::class)
        ->set('empresaId', $this->empresa->id)
        ->assertSee('Copiar las 9 fotos que faltan')
        ->call('iniciarFotos')
        ->call('copiarFotos')
        ->assertSet('fotos.copiadas', 8)
        ->assertSet('copiandoFotos', true)
        ->call('copiarFotos')
        ->assertSet('fotos.copiadas', 9)
        ->assertSet('copiandoFotos', false)
        ->assertSee('Fotos copiadas: 9 de 9.');

    expect(Storage::disk('local')->files("empresas/{$this->empresa->id}/productos"))->toHaveCount(9)
        ->and(DB::table('productos')->where('empresa_id', $this->empresa->id)->whereNull('imagen_path')->pluck('sku')->all())->toBe(['LAVA01']);

    $pantalla->call('copiarFotos')->assertSet('fotos.copiadas', 9);
    Livewire::actingAs($this->superadmin)->test(MigracionCyclone::class)->set('empresaId', $this->empresa->id)->assertDontSee('fotos que faltan');
});

test('without the central catalog, photos are downloaded from the Cyclone API by product id', function () {
    Http::preventStrayRequests();
    $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    Http::fake([
        '*/catalogo/productos/3/foto' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        '*/catalogo/productos/100/foto' => Http::response('No encontrado', 404),
    ]);

    Livewire::actingAs($this->superadmin)->test(MigracionCyclone::class)
        ->set('empresaId', $this->empresa->id)
        ->set('archivo', subirRespaldoCyclone(respaldoCyclone([['id' => 100, 'codigo' => 'SINFOTO', 'nombre' => 'Foto borrada en Cyclone', 'foto' => 'borrada.png']])))
        ->call('migrar')
        ->assertHasNoErrors()
        ->assertSet('fotos.total', 2)
        ->call('copiarFotos')
        ->assertSet('copiandoFotos', false)
        ->assertSet('fotos.copiadas', 1)
        ->assertSee('Fotos copiadas: 1 de 2.')
        ->assertSee('Producto 100 (SINFOTO Foto borrada en Cyclone)');

    $imagen = DB::table('productos')->where('empresa_id', $this->empresa->id)->where('sku', '0DIMO05')->value('imagen_path');
    expect($imagen)->toStartWith("empresas/{$this->empresa->id}/productos/")->toEndWith('.png')
        ->and(Storage::disk('local')->get($imagen))->toBe($png)
        // Qué productos tenían foto queda guardado con la migración ('' = sin foto): LAVA01 no se pide a Cyclone.
        ->and(DB::table('migracion_origenes')->where('empresa_id', $this->empresa->id)->where('entidad', 'producto')->orderBy('origen_id')->pluck('foto', 'origen_id')->all())
        ->toBe(['100' => 'borrada.png', '3' => '1682479264_DESINCRUSTANTE.png', '5' => '']);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/catalogo/productos/3/foto'));
});

test('a company migrated before photo names were saved completes them by uploading its backup again', function () {
    Http::preventStrayRequests();
    $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    Http::fake(['*/catalogo/productos/3/foto' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

    Livewire::actingAs($this->superadmin)->test(MigracionCyclone::class)
        ->set('empresaId', $this->empresa->id)
        ->set('archivo', subirRespaldoCyclone(respaldoCyclone()))
        ->call('migrar')
        ->assertHasNoErrors();
    // Así quedaron las empresas migradas antes de la columna «foto».
    DB::table('migracion_origenes')->where('empresa_id', $this->empresa->id)->update(['foto' => null]);
    $antes = DB::table('productos')->where('empresa_id', $this->empresa->id)->orderBy('id')->get(['id', 'sku', 'precio_consumidor', 'updated_at'])->toArray();
    $auditorias = Auditoria::query()->count();

    Livewire::actingAs($this->superadmin)->test(MigracionCyclone::class)
        ->set('empresaId', $this->empresa->id)
        ->assertDontSee('fotos que faltan')
        ->assertSee('Sube otra vez su respaldo .sql para traer las fotos desde Cyclone')
        ->set('archivo', subirRespaldoCyclone(respaldoCyclone()))
        ->assertHasNoErrors()
        ->assertSet('respaldo', null)
        ->assertSet('vista', [])
        ->assertSet('fotos.total', 1)
        ->call('copiarFotos')
        ->assertSet('fotos.copiadas', 1)
        ->assertDontSee('Sube otra vez su respaldo');

    expect(Storage::disk('local')->files('migraciones/respaldos'))->toBe([])
        ->and(DB::table('productos')->where('empresa_id', $this->empresa->id)->where('sku', '0DIMO05')->value('imagen_path'))->not->toBeNull()
        // Ningún otro dato cambió y no hubo otra migración.
        ->and(DB::table('productos')->where('empresa_id', $this->empresa->id)->where('sku', '<>', '0DIMO05')->orderBy('id')->get(['id', 'sku', 'precio_consumidor', 'updated_at'])->toArray())
        ->toEqual(array_values(array_filter($antes, fn (object $p): bool => $p->sku !== '0DIMO05')))
        ->and(Auditoria::query()->count())->toBe($auditorias);

    // Con el dato ya completo, otro respaldo no cambia nada.
    Livewire::actingAs($this->superadmin)->test(MigracionCyclone::class)
        ->set('empresaId', $this->empresa->id)
        ->set('archivo', subirRespaldoCyclone(respaldoCyclone()))
        ->assertHasErrors('respaldo')
        ->assertSee('no tiene productos de esta empresa a los que les falte el dato de la foto');
    expect(Storage::disk('local')->files('migraciones/respaldos'))->toBe([]);
});

test('backups left behind are deleted the next day', function () {
    $disco = Storage::disk('local');
    $disco->put('migraciones/respaldos/viejo.sql', 'datos');
    $disco->put('migraciones/respaldos/reciente.sql', 'datos');
    touch($disco->path('migraciones/respaldos/viejo.sql'), now()->subDays(2)->getTimestamp());

    Livewire::actingAs($this->superadmin)->test(MigracionCyclone::class)->assertOk();

    expect($disco->files('migraciones/respaldos'))->toBe(['migraciones/respaldos/reciente.sql']);
});
