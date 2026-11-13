<?php

use App\Domain\Caja\Actions\AbrirTurnoCaja;
use App\Domain\Catalogo\Actions\CambiarPrecioVariante;
use App\Domain\Catalogo\Actions\GuardarColor;
use App\Domain\Catalogo\Actions\GuardarGrupoTalla;
use App\Domain\Catalogo\Actions\GuardarModelo;
use App\Domain\Catalogo\Actions\GuardarProducto;
use App\Domain\Catalogo\Actions\GuardarTalla;
use App\Domain\Catalogo\Consultas\CuadriculaStock;
use App\Domain\Compras\Actions\RegistrarCompra;
use App\Domain\Inventario\Actions\RegistrarTraspaso;
use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Domain\Ventas\Actions\RegistrarDevolucion;
use App\Enums\Capacidad;
use App\Enums\Rubro;
use App\Livewire\Catalogo\ModeloDetalle;
use App\Livewire\Catalogo\ModeloFormulario;
use App\Livewire\Catalogo\ProductoFormulario;
use App\Livewire\Catalogo\Productos;
use App\Livewire\Catalogo\TallasColores;
use App\Livewire\Ventas\Pos;
use App\Models\Auditoria;
use App\Models\Color;
use App\Models\Empresa;
use App\Models\GrupoTalla;
use App\Models\Modelo;
use App\Models\MovimientoStock;
use App\Models\OperacionInventario;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\Talla;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Models\VentaItem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * Tallas y colores, etapa V1 (ESPECIFICACION_TALLAS_Y_COLORES.md § 10): listas de la empresa, modelos y generación de
 * variantes, precio del modelo y precio propio, catálogo agrupado y cuadrícula de stock. Cada variante es un producto
 * normal: ventas, compras, traspasos y devoluciones pasan por las mismas acciones de siempre.
 */

function tcUnidad(Empresa $empresa, string $nombre = 'Unidad'): UnidadMedida
{
    $unidad = new UnidadMedida(['nombre' => $nombre, 'abreviatura' => 'u', 'admite_decimales' => false]);
    $unidad->empresa_id = $empresa->id;
    $unidad->save();

    return $unidad;
}

/**
 * Ids de tallas del grupo Letras (lista sugerida) en el orden pedido.
 *
 * @param  list<string>  $nombres
 * @return list<int>
 */
function tcTallas(array $nombres, string $grupo = 'Letras'): array
{
    $ids = Talla::query()->whereHas('grupo', fn ($q) => $q->where('nombre', $grupo))->whereIn('nombre', $nombres)->pluck('id', 'nombre');

    return array_map(fn (string $n): int => (int) $ids[$n], $nombres);
}

/**
 * @param  list<string>  $nombres
 * @return list<int>
 */
function tcColores(array $nombres): array
{
    $ids = Color::query()->whereIn('nombre', $nombres)->pluck('id', 'nombre');

    return array_map(fn (string $n): int => (int) $ids[$n], $nombres);
}

/**
 * Datos de GuardarModelo para una Polera Básica con las tallas y colores indicados.
 *
 * @param  list<string>  $tallas
 * @param  list<string>  $colores
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function tcDatos(array $tallas, array $colores, array $extra = []): array
{
    return $extra + [
        'nombre' => 'Polera Básica', 'categoria_id' => null, 'unidad_medida_id' => test()->unidad->id, 'sku_base' => 'POL001',
        'descripcion' => null, 'precio_consumidor' => '49.90', 'precio_minorista' => '45.50', 'precio_mayorista' => '40.25',
        'stock_minimo' => '2', 'grupo_talla_id' => $tallas === [] ? null : GrupoTalla::query()->where('nombre', 'Letras')->value('id'),
        'tallas' => tcTallas($tallas), 'colores' => tcColores($colores), 'combinaciones' => [],
    ];
}

function tcModelo(array $tallas = ['S', 'M', 'L'], array $colores = ['Negro', 'Blanco'], array $extra = []): Modelo
{
    return app(GuardarModelo::class)->crear(test()->admin, tcDatos($tallas, $colores, $extra));
}

function tcVariante(Modelo $modelo, ?string $talla, ?string $color): Producto
{
    return Producto::query()->where('modelo_id', $modelo->id)
        ->where('talla_id', $talla === null ? null : Talla::query()->where('grupo_talla_id', $modelo->grupo_talla_id)->where('nombre', $talla)->value('id'))
        ->where('color_id', $color === null ? null : Color::query()->where('nombre', $color)->value('id'))
        ->sole();
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    $this->unidad = tcUnidad($this->empresa);
    $this->actingAs($this->admin);
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::Ropa, [Capacidad::Variantes]);
});

test('turning variantes on loads the suggested lists once and never duplicates or overwrites them', function () {
    expect(GrupoTalla::query()->orderBy('orden')->pluck('nombre')->all())->toBe(['Letras', 'Calzado', 'Pantalón', 'Niños'])
        ->and(Talla::query()->whereHas('grupo', fn ($q) => $q->where('nombre', 'Letras'))->orderBy('orden')->pluck('nombre')->all())
        ->toBe(['XS', 'S', 'M', 'L', 'XL', 'XXL'])
        ->and(Color::query()->count())->toBe(10)
        ->and(Color::query()->where('nombre', 'Azul marino')->value('codigo'))->toBe('AZM');

    // El administrador edita la lista; apagar y volver a encender la capacidad no la pisa ni la duplica.
    app(GuardarColor::class)->actualizar($this->admin, Color::query()->where('nombre', 'Rosado')->sole(), 'Fucsia', 'FUC', '#E0218A', true);
    $acciones = app(CambiarFuncionesEmpresa::class);
    $acciones->ejecutar($this->admin, $this->empresa, Rubro::General, []);
    $acciones->ejecutar($this->admin, $this->empresa, Rubro::Ropa, [Capacidad::Variantes]);

    expect(GrupoTalla::query()->count())->toBe(4)
        ->and(Talla::query()->count())->toBe(6 + 11 + 7 + 8)
        ->and(Color::query()->count())->toBe(10)
        ->and(Color::query()->where('nombre', 'Fucsia')->exists())->toBeTrue()
        ->and(Color::query()->where('nombre', 'Rosado')->exists())->toBeFalse();

    // Otra empresa sin la capacidad no recibe listas.
    [$otra] = empresaConAdministrador();
    expect(DB::table('colores')->where('empresa_id', $otra->id)->count())->toBe(0);
});

test('the data migration loads the suggested lists in companies that had variantes on before V1, and only there', function () {
    // A ya tiene su lista (editada); X tenía la capacidad activa desde R0 sin listas; Y la tiene apagada.
    app(GuardarColor::class)->actualizar($this->admin, Color::query()->where('nombre', 'Rosado')->sole(), 'Fucsia', 'FUC', '#E0218A', true);
    [$conCapacidad] = empresaConAdministrador();
    [$apagada] = empresaConAdministrador();
    [$sinFila] = empresaConAdministrador();
    DB::table('empresa_capacidades')->insert([
        ['empresa_id' => $conCapacidad->id, 'capacidad' => 'variantes', 'activa' => true, 'created_at' => now(), 'updated_at' => now()],
        ['empresa_id' => $apagada->id, 'capacidad' => 'variantes', 'activa' => false, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $contar = fn (Empresa $empresa, string $tabla): int => DB::table($tabla)->where('empresa_id', $empresa->id)->count();

    $migracion = require database_path('migrations/2026_11_09_100100_cargar_tallas_y_colores_sugeridos.php');
    $migracion->up();
    $migracion->up(); // repetirla no duplica

    expect($contar($conCapacidad, 'grupos_talla'))->toBe(4)
        ->and($contar($conCapacidad, 'tallas'))->toBe(6 + 11 + 7 + 8)
        ->and($contar($conCapacidad, 'colores'))->toBe(10)
        ->and($contar($apagada, 'grupos_talla') + $contar($apagada, 'colores'))->toBe(0)
        ->and($contar($sinFila, 'grupos_talla') + $contar($sinFila, 'colores'))->toBe(0)
        ->and($contar($this->empresa, 'grupos_talla'))->toBe(4)
        ->and($contar($this->empresa, 'colores'))->toBe(10)
        ->and(DB::table('colores')->where('empresa_id', $this->empresa->id)->where('nombre', 'Fucsia')->exists())->toBeTrue()
        ->and(DB::table('colores')->where('empresa_id', $this->empresa->id)->where('nombre', 'Rosado')->exists())->toBeFalse();
});

test('a model with 3 sizes × 2 colors creates 6 variants in one bulk insert, without stock', function () {
    $inserciones = 0;
    DB::listen(function ($consulta) use (&$inserciones): void {
        $inserciones += str_starts_with(strtolower($consulta->sql), 'insert into `productos`') ? 1 : 0;
    });

    $modelo = tcModelo();
    $variantes = Producto::query()->where('modelo_id', $modelo->id)->orderBy('id')->get();

    expect($inserciones)->toBe(1)
        ->and($variantes)->toHaveCount(6)
        ->and($variantes->pluck('nombre')->all())->toBe([
            'Polera Básica · S · Negro', 'Polera Básica · M · Negro', 'Polera Básica · L · Negro',
            'Polera Básica · S · Blanco', 'Polera Básica · M · Blanco', 'Polera Básica · L · Blanco',
        ])
        ->and($variantes->pluck('sku')->all())->toBe(['POL001-S-NEG', 'POL001-M-NEG', 'POL001-L-NEG', 'POL001-S-BLA', 'POL001-M-BLA', 'POL001-L-BLA'])
        ->and($variantes->every(fn (Producto $v): bool => $v->precio_consumidor === '49.90' && $v->precio_minorista === '45.50'
            && $v->precio_mayorista === '40.25' && ! $v->precio_propio && $v->stock_minimo === '2.000' && $v->tipo_venta === 'unidad'
            && $v->controla_stock && $v->unidad_medida_id === $this->unidad->id && $v->activo && $v->empresa_id === $this->empresa->id))->toBeTrue()
        ->and(DB::table('stock_niveles')->whereIn('producto_id', $variantes->pluck('id'))->count())->toBe(0)
        ->and(Auditoria::query()->where('accion', 'modelo.creado')->sole()->despues['variantes'])->toBe(['creadas' => 6]);

    [$talla, $color] = [tcTallas(['M'])[0], tcColores(['Blanco'])[0]];
    expect(tcVariante($modelo, 'M', 'Blanco')->clave_variante)->toBe("{$modelo->id}-{$talla}-{$color}");
});

test('if one combination fails (a duplicate SKU or barcode) nothing is created', function () {
    productoDe($this->empresa, ['nombre' => 'Polera vieja', 'sku' => 'POL001-M-NEG', 'codigo_barras' => '7790000000017']);
    $antes = Producto::query()->count();
    $clave = fn (string $talla, string $color) => tcTallas([$talla])[0].'-'.tcColores([$color])[0];

    expect(fn () => tcModelo())->toThrow(ValidationException::class, 'Ya existe un producto con ese código en tu empresa.');

    try {
        tcModelo(extra: ['sku_base' => 'POL002', 'combinaciones' => [
            $clave('S', 'Negro') => ['codigo_barras' => '7790000000017'],
            $clave('M', 'Negro') => ['sku' => 'POL002-X'], $clave('L', 'Negro') => ['sku' => 'pol002-x'],
        ]]);
        $this->fail('Debió rechazar los códigos repetidos.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKeys([
            "combinaciones.{$clave('S', 'Negro')}.codigo_barras", "combinaciones.{$clave('M', 'Negro')}.sku", "combinaciones.{$clave('L', 'Negro')}.sku",
        ]);
    }

    expect(Modelo::query()->count())->toBe(0)
        ->and(Producto::query()->count())->toBe($antes)
        ->and(Auditoria::query()->where('accion', 'modelo.creado')->exists())->toBeFalse();

    // Doble envío: el segundo modelo con el mismo nombre se rechaza y no duplica variantes.
    tcModelo(extra: ['sku_base' => 'POL003']);
    expect(fn () => tcModelo(extra: ['sku_base' => 'POL004']))->toThrow(ValidationException::class, 'Ya existe un modelo con ese nombre.')
        ->and(Producto::query()->whereNotNull('modelo_id')->count())->toBe(6);
});

test('a model with only sizes, only colors or neither gets the right names and keys', function () {
    $medias = tcModelo(['S', 'M'], [], ['nombre' => 'Medias', 'sku_base' => 'MED']);
    $gorra = tcModelo([], ['Negro', 'Rojo'], ['nombre' => 'Gorra', 'sku_base' => 'GOR']);
    $bolso = tcModelo([], [], ['nombre' => 'Bolso', 'sku_base' => null]);

    $s = tcTallas(['S'])[0];
    $rojo = tcColores(['Rojo'])[0];

    expect(Producto::query()->where('modelo_id', $medias->id)->orderBy('id')->pluck('nombre')->all())->toBe(['Medias · S', 'Medias · M'])
        ->and(tcVariante($medias, 'S', null)->only(['sku', 'clave_variante']))->toBe(['sku' => 'MED-S', 'clave_variante' => "{$medias->id}-{$s}-0"])
        ->and(Producto::query()->where('modelo_id', $gorra->id)->orderBy('id')->pluck('nombre')->all())->toBe(['Gorra · Negro', 'Gorra · Rojo'])
        ->and(tcVariante($gorra, null, 'Rojo')->only(['sku', 'clave_variante']))->toBe(['sku' => 'GOR-ROJ', 'clave_variante' => "{$gorra->id}-0-{$rojo}"])
        ->and(tcVariante($bolso, null, null)->only(['nombre', 'sku', 'clave_variante']))->toBe(['nombre' => 'Bolso', 'sku' => null, 'clave_variante' => "{$bolso->id}-0-0"]);

    // Tallas sin grupo, o de otro grupo, se rechazan.
    expect(fn () => tcModelo(['S'], [], ['nombre' => 'Short', 'grupo_talla_id' => GrupoTalla::query()->where('nombre', 'Calzado')->value('id')]))
        ->toThrow(ValidationException::class, 'Elige tallas del grupo seleccionado.');
});

test('adding a size later creates only the missing combinations; removing one deactivates it if it has history', function () {
    $modelo = tcModelo(['S', 'M'], ['Negro']);
    $antes = Producto::query()->where('modelo_id', $modelo->id)->orderBy('id')->pluck('id')->all();

    app(GuardarModelo::class)->actualizar($this->admin, $modelo, tcDatos(['S', 'M', 'L'], ['Negro', 'Blanco']));
    $ahora = Producto::query()->where('modelo_id', $modelo->id)->orderBy('id')->pluck('id')->all();

    expect(array_slice($ahora, 0, 2))->toBe($antes)
        ->and($ahora)->toHaveCount(6)
        ->and(Auditoria::query()->where('accion', 'modelo.actualizado')->latest('id')->first()->despues['variantes'])->toBe(['creadas' => 4]);

    // S-Negro tiene historial (una entrada); L-Blanco nunca se usó.
    cargarStock($this->admin, $this->matriz, tcVariante($modelo, 'S', 'Negro'), '3');
    $sNegro = tcVariante($modelo, 'S', 'Negro');
    $lBlanco = tcVariante($modelo, 'L', 'Blanco');
    $clave = fn (string $t, string $c) => tcTallas([$t])[0].'-'.tcColores([$c])[0];

    app(GuardarModelo::class)->actualizar($this->admin, $modelo, tcDatos(['S', 'M', 'L'], ['Negro', 'Blanco'], ['combinaciones' => [
        $clave('S', 'Negro') => ['crear' => false], $clave('L', 'Blanco') => ['crear' => false],
    ]]));

    expect($sNegro->fresh()->activo)->toBeFalse()
        ->and(stockDe($this->matriz, $sNegro))->toBe('3.000')
        ->and(Producto::query()->whereKey($lBlanco->id)->exists())->toBeFalse()
        ->and(Auditoria::query()->where('accion', 'modelo.actualizado')->latest('id')->first()->despues['variantes'])->toBe(['desactivadas' => 1, 'eliminadas' => 1]);

    // Volver a marcarla la reactiva con el mismo id, stock e historial.
    app(GuardarModelo::class)->actualizar($this->admin, $modelo, tcDatos(['S', 'M', 'L'], ['Negro', 'Blanco']));
    expect($sNegro->fresh()->activo)->toBeTrue()
        ->and(Producto::query()->where('modelo_id', $modelo->id)->count())->toBe(6);

    // Desactivar el modelo desactiva todas sus combinaciones (§ 8.6).
    app(GuardarModelo::class)->actualizar($this->admin, $modelo, tcDatos(['S', 'M', 'L'], ['Negro', 'Blanco'], ['activo' => false]));
    expect(Producto::query()->where('modelo_id', $modelo->id)->where('activo', true)->count())->toBe(0);
});

test('the database rejects two equal variants of a model and a size without a model, even skipping validation', function () {
    $modelo = tcModelo(['S'], ['Negro']);
    $variante = tcVariante($modelo, 'S', 'Negro');
    $fila = fn (array $cambios) => array_merge(
        collect($variante->getAttributes())->except(['id', 'clave_variante', 'sku', 'codigo_barras', 'created_at', 'updated_at'])->all(), $cambios);

    expect(fn () => DB::table('productos')->insert($fila(['nombre' => 'Copia'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('productos')->insert($fila(['nombre' => 'Suelto con talla', 'modelo_id' => null, 'color_id' => null])))->toThrow(QueryException::class)
        ->and(Producto::query()->where('modelo_id', $modelo->id)->count())->toBe(1);

    // Una talla o un color de otra empresa no se puede asociar (FK compuesta por empresa).
    [$otra, $adminOtra] = empresaConAdministrador();
    $this->actingAs($adminOtra);
    app(CambiarFuncionesEmpresa::class)->ejecutar($adminOtra, $otra, Rubro::Ropa, [Capacidad::Variantes]);
    $tallaAjena = DB::table('tallas')->where('empresa_id', $otra->id)->value('id');
    expect(fn () => DB::table('productos')->where('id', $variante->id)->update(['talla_id' => $tallaAjena]))->toThrow(QueryException::class);
});

test('changing the model price updates only the variants without their own price, with exact cents', function () {
    $modelo = tcModelo();
    $propia = tcVariante($modelo, 'L', 'Negro');
    app(CambiarPrecioVariante::class)->ejecutar($this->admin, $propia, ['precio_consumidor' => '55.35', 'precio_minorista' => null, 'precio_mayorista' => '50']);

    expect($propia->fresh()->only(['precio_consumidor', 'precio_minorista', 'precio_mayorista', 'precio_propio']))
        ->toBe(['precio_consumidor' => '55.35', 'precio_minorista' => null, 'precio_mayorista' => '50.00', 'precio_propio' => true]);

    app(GuardarModelo::class)->actualizar($this->admin, $modelo, tcDatos(['S', 'M', 'L'], ['Negro', 'Blanco'], ['precio_consumidor' => '59.99', 'precio_minorista' => '0.01']));

    $precios = Producto::query()->where('modelo_id', $modelo->id)->where('id', '!=', $propia->id)->pluck('precio_consumidor')->unique()->all();
    expect($precios)->toBe(['59.99'])
        ->and(Producto::query()->where('modelo_id', $modelo->id)->where('precio_minorista', '0.01')->count())->toBe(5)
        ->and($propia->fresh()->precio_consumidor)->toBe('55.35')
        ->and(Auditoria::query()->where('accion', 'modelo.actualizado')->latest('id')->first()->despues['variantes'])->toBe(['con_precio_del_modelo' => 5]);

    // «Usar precio del modelo» la devuelve, y queda en auditoría.
    app(CambiarPrecioVariante::class)->ejecutar($this->admin, $propia->fresh(), null);
    expect($propia->fresh()->only(['precio_consumidor', 'precio_minorista', 'precio_mayorista', 'precio_propio']))
        ->toBe(['precio_consumidor' => '59.99', 'precio_minorista' => '0.01', 'precio_mayorista' => '40.25', 'precio_propio' => false])
        ->and(Auditoria::query()->where('accion', 'producto.precio_variante')->count())->toBe(2);
});

test('a sale uses the variant price, own or from the model, and a pending order keeps its quoted price like any product', function () {
    $modelo = tcModelo();
    $delModelo = tcVariante($modelo, 'M', 'Negro');
    $propia = tcVariante($modelo, 'M', 'Blanco');
    $suelto = productoDe($this->empresa, ['nombre' => 'Cinturón', 'precio_consumidor' => '20.10']);
    app(CambiarPrecioVariante::class)->ejecutar($this->admin, $propia, ['precio_consumidor' => '52.35', 'precio_minorista' => null, 'precio_mayorista' => null]);

    foreach ([$delModelo, $propia, $suelto] as $producto) {
        cargarStock($this->admin, $this->matriz, $producto, '10');
    }

    $venta = confirmarVenta($this->admin, crearPedido($this->admin, $this->matriz, [
        ['producto_id' => $delModelo->id, 'cantidad' => '2'], ['producto_id' => $propia->id, 'cantidad' => '1'],
    ]), ['metodo' => 'efectivo']);

    expect($venta->total)->toBe('152.15')
        ->and(VentaItem::query()->where('venta_id', $venta->id)->orderBy('orden')->get(['descripcion', 'precio_unitario', 'total'])->toArray())->toBe([
            ['descripcion' => 'Polera Básica · M · Negro', 'precio_unitario' => '49.90', 'total' => '99.80'],
            ['descripcion' => 'Polera Básica · M · Blanco', 'precio_unitario' => '52.35', 'total' => '52.35'],
        ]);

    // Pedido pendiente: no vende ni descuenta stock; cambiar después el precio del modelo (o el del suelto) no cambia lo
    // cotizado, igual que con cualquier producto.
    $pendiente = crearPedido($this->admin, $this->matriz, [['producto_id' => $delModelo->id, 'cantidad' => '1'], ['producto_id' => $suelto->id, 'cantidad' => '1']]);
    expect(stockDe($this->matriz, $delModelo))->toBe('8.000');

    app(GuardarModelo::class)->actualizar($this->admin, $modelo, tcDatos(['S', 'M', 'L'], ['Negro', 'Blanco'], ['precio_consumidor' => '60.00']));
    app(GuardarProducto::class)->actualizarPrecioConsumidor($this->admin, $suelto, '25.00');
    $confirmada = confirmarVenta($this->admin, $pendiente->refresh(), ['metodo' => 'efectivo']);

    expect(VentaItem::query()->where('venta_id', $confirmada->id)->orderBy('orden')->pluck('precio_unitario')->all())->toBe(['49.90', '20.10'])
        ->and($confirmada->total)->toBe('70.00')
        ->and($delModelo->fresh()->precio_consumidor)->toBe('60.00');
});

test('selling, buying, transferring and returning a variant moves only that variant', function () {
    $modelo = tcModelo(['M', 'L'], ['Negro']);
    $m = tcVariante($modelo, 'M', 'Negro');
    $l = tcVariante($modelo, 'L', 'Negro');
    cargarStock($this->admin, $this->matriz, $m, '10');
    cargarStock($this->admin, $this->matriz, $l, '10');
    $proveedor = new Proveedor(['nombre' => 'Textiles Andinos']);
    $proveedor->empresa_id = $this->empresa->id;
    $proveedor->save();

    app(RegistrarCompra::class)->ejecutar($this->admin, [
        'sucursal_id' => $this->matriz->id, 'proveedor_id' => $proveedor->id, 'referencia' => null,
        'lineas' => [$m->id => ['cantidad' => '5', 'costo' => '30.50']],
        'metodo' => 'efectivo', 'efectivo' => null, 'electronico' => null, 'externo' => true, 'clave' => (string) Str::uuid(),
    ]);
    app(RegistrarTraspaso::class)->ejecutar($this->admin, [
        'sucursal_id' => $this->matriz->id, 'sucursal_destino_id' => $this->norte->id, 'nota' => null,
        'lineas' => [$m->id => '3'], 'clave' => (string) Str::uuid(),
    ]);
    $venta = confirmarVenta($this->admin, crearPedido($this->admin, $this->matriz, [['producto_id' => $m->id, 'cantidad' => '2']]), ['metodo' => 'efectivo']);
    app(RegistrarDevolucion::class)->ejecutar($this->admin, $venta, [
        'lineas' => [$venta->items()->value('id') => ['cantidad' => '1', 'reingresa' => true]],
        'motivo' => 'Cambio de talla', 'metodo' => 'efectivo', 'efectivo' => null, 'electronico' => null, 'externo' => true, 'clave' => (string) Str::uuid(),
    ]);

    expect([stockDe($this->matriz, $m), stockDe($this->norte, $m)])->toBe(['11.000', '3.000'])
        ->and([stockDe($this->matriz, $l), stockDe($this->norte, $l)])->toBe(['10.000', '0.000'])
        ->and(MovimientoStock::query()->where('producto_id', $l->id)->count())->toBe(1);

    // La cuadrícula del detalle coincide con stock_niveles en cada sucursal, y se arma con UNA consulta.
    foreach ([$this->matriz, $this->norte] as $sucursal) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $cuadricula = CuadriculaStock::delModelo($this->empresa->id, $modelo->id, $sucursal->id);
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($consultas)->toBe(1)
            ->and(collect($cuadricula['celdas'])->mapWithKeys(fn (array $c): array => [$c['id'] => $c['stock']])->all())
            ->toBe([$m->id => stockDe($sucursal, $m), $l->id => stockDe($sucursal, $l)])
            ->and($cuadricula['total'])->toBe(bcadd(stockDe($sucursal, $m), stockDe($sucursal, $l), 3));
    }
});

test('scanning a variant barcode or SKU adds it in the POS', function () {
    $clave = tcTallas(['M'])[0].'-'.tcColores(['Negro'])[0];
    $modelo = tcModelo(['M'], ['Negro', 'Blanco'], ['combinaciones' => [$clave => ['codigo_barras' => '7790000000017']]]);
    $m = tcVariante($modelo, 'M', 'Negro');
    $blanco = tcVariante($modelo, 'M', 'Blanco');
    cargarStock($this->admin, $this->matriz, $m, '4');
    cargarStock($this->admin, $this->matriz, $blanco, '4');
    app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->matriz->cajaPrincipal(), '100.00');

    Livewire::test(Pos::class)
        ->call('agregarDesdeBuscador', '7790000000017')
        ->assertSet('lineas.0.id', $m->id)
        ->call('agregarDesdeBuscador', 'POL001-M-BLA')
        ->assertSet('lineas.1.id', $blanco->id)
        ->assertSee('Polera Básica · M · Blanco');
});

test('renaming a size, a color or the model renames its variants with one statement, but not past sales', function () {
    $modelo = tcModelo(['M'], ['Negro']);
    $variante = tcVariante($modelo, 'M', 'Negro');
    cargarStock($this->admin, $this->matriz, $variante, '5');
    $venta = confirmarVenta($this->admin, crearPedido($this->admin, $this->matriz, [['producto_id' => $variante->id, 'cantidad' => '1']]), ['metodo' => 'efectivo']);

    $datos = tcDatos(['M'], ['Negro'], ['nombre' => 'Polera Clásica']);
    $renombradas = app(GuardarColor::class)->actualizar($this->admin, Color::query()->where('nombre', 'Negro')->sole(), 'Negro azabache', 'NEG', null, true);
    app(GuardarTalla::class)->actualizar($this->admin, Talla::query()->findOrFail(tcTallas(['M'])[0]), 'Mediana', 3, true);
    app(GuardarModelo::class)->actualizar($this->admin, $modelo, $datos);

    expect($renombradas)->toBe(1)
        ->and($variante->fresh()->nombre)->toBe('Polera Clásica · Mediana · Negro azabache')
        ->and($variante->fresh()->sku)->toBe('POL001-M-NEG')
        ->and(VentaItem::query()->where('venta_id', $venta->id)->value('descripcion'))->toBe('Polera Básica · M · Negro')
        ->and(Auditoria::query()->where('accion', 'color.actualizado')->sole()->despues['variantes_renombradas'])->toBe(1);
});

test('a size, color or group in use is deactivated instead of deleted; an unused one is deleted', function () {
    $modelo = tcModelo(['M'], ['Negro']);
    $talla = Talla::query()->findOrFail(tcTallas(['M'])[0]);
    $libre = Talla::query()->findOrFail(tcTallas(['XXL'])[0]);
    $negro = Color::query()->where('nombre', 'Negro')->sole();
    $beige = Color::query()->where('nombre', 'Beige')->sole();
    $letras = GrupoTalla::query()->where('nombre', 'Letras')->sole();
    $ninos = GrupoTalla::query()->where('nombre', 'Niños')->sole();

    expect(app(GuardarTalla::class)->eliminar($this->admin, $talla))->toBeFalse()
        ->and($talla->fresh()->activa)->toBeFalse()
        ->and(app(GuardarTalla::class)->eliminar($this->admin, $libre))->toBeTrue()
        ->and(Talla::query()->whereKey($libre->id)->exists())->toBeFalse()
        ->and(app(GuardarColor::class)->eliminar($this->admin, $negro))->toBeFalse()
        ->and($negro->fresh()->activo)->toBeFalse()
        ->and(app(GuardarColor::class)->eliminar($this->admin, $beige))->toBeTrue()
        ->and(app(GuardarGrupoTalla::class)->eliminar($this->admin, $letras))->toBeFalse()
        ->and($letras->fresh()->activo)->toBeFalse()
        ->and(app(GuardarGrupoTalla::class)->eliminar($this->admin, $ninos))->toBeTrue()
        ->and(Talla::query()->where('grupo_talla_id', $ninos->id)->exists())->toBeFalse()
        // La variante sigue igual, y una talla o un color desactivados no se usan en combinaciones nuevas.
        ->and(tcVariante($modelo, 'M', 'Negro')->activo)->toBeTrue()
        ->and(fn () => tcModelo(['M'], ['Blanco'], ['nombre' => 'Camisa', 'sku_base' => 'CAM', 'grupo_talla_id' => $letras->id]))
        ->toThrow(ValidationException::class, 'Elige un grupo de tallas activo de tu empresa.');

    // Conservar la combinación que ya existe (talla y color desactivados) se permite; crear una nueva con la talla M
    // desactivada, no.
    expect(fn () => app(GuardarModelo::class)->actualizar($this->admin, $modelo, tcDatos(['M'], ['Negro'], ['precio_consumidor' => '51.00'])))
        ->not->toThrow(Throwable::class)
        ->and(fn () => app(GuardarModelo::class)->actualizar($this->admin, $modelo, tcDatos(['M'], ['Negro', 'Blanco'])))
        ->toThrow(ValidationException::class, 'La talla M está desactivada: no se usa en combinaciones nuevas.')
        ->and(Producto::query()->where('modelo_id', $modelo->id)->count())->toBe(1)
        ->and(tcVariante($modelo, 'M', 'Negro')->precio_consumidor)->toBe('51.00');
});

test('company B cannot see or use the models, lists, variants or photos of company A, even changing IDs', function () {
    Storage::fake('local');
    $modelo = app(GuardarModelo::class)->crear($this->admin, tcDatos(['M'], ['Negro']), imagenPng('polera.png'));
    $variante = tcVariante($modelo, 'M', 'Negro');
    $tallaA = Talla::query()->findOrFail(tcTallas(['M'])[0]);
    $colorA = Color::query()->where('nombre', 'Negro')->sole();
    $this->get(route('productos.modelos.imagen', $modelo))->assertOk();

    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $unidadB = tcUnidad($empresaB);
    app(CambiarFuncionesEmpresa::class)->ejecutar($adminB, $empresaB, Rubro::Ropa, [Capacidad::Variantes]);
    $propio = app(GuardarModelo::class)->crear($adminB, ['nombre' => 'Polera B', 'unidad_medida_id' => $unidadB->id, 'precio_consumidor' => '10',
        'stock_minimo' => '0', 'tallas' => [], 'colores' => []]);

    foreach (['productos.modelos.ver', 'productos.modelos.editar', 'productos.modelos.imagen'] as $ruta) {
        $this->get(route($ruta, $modelo))->assertNotFound();
    }

    $this->get(route('productos.editar', $variante))->assertNotFound();
    Livewire::test(Productos::class)->assertSee('Polera B')->assertDontSee('Polera Básica');
    expect($this->get(route('productos.exportar'))->assertOk()->streamedContent())->toContain('Polera B')->not->toContain('Polera Básica');

    // IDs de A en Livewire y en las acciones.
    Livewire::test(ModeloDetalle::class, ['modelo' => $propio])->call('cambiarPrecio', $variante->id)->assertNotFound();
    Livewire::test(ModeloDetalle::class, ['modelo' => $propio])->call('usarPrecioModelo', $variante->id)->assertNotFound();
    Livewire::test(TallasColores::class)->call('editarTalla', $tallaA->id)->assertNotFound();
    Livewire::test(TallasColores::class)->call('eliminarColor', $colorA->id)->assertNotFound();
    Livewire::test(ModeloDetalle::class, ['modelo' => $modelo])->assertForbidden();

    expect(fn () => app(GuardarModelo::class)->actualizar($adminB, $modelo, tcDatos(['M'], ['Negro'])))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CambiarPrecioVariante::class)->ejecutar($adminB, $variante, null))->toThrow(AuthorizationException::class)
        ->and(fn () => app(GuardarTalla::class)->actualizar($adminB, $tallaA, 'X', 1, true))->toThrow(NotFoundHttpException::class)
        ->and(fn () => app(GuardarModelo::class)->actualizar($adminB, $propio, ['nombre' => 'Polera B', 'unidad_medida_id' => $unidadB->id,
            'precio_consumidor' => '10', 'stock_minimo' => '0', 'grupo_talla_id' => $tallaA->grupo_talla_id, 'tallas' => [$tallaA->id], 'colores' => [$colorA->id]]))
        ->toThrow(ValidationException::class);

    expect($tallaA->fresh()->nombre)->toBe('M')
        ->and($colorA->fresh()->activo)->toBeTrue()
        ->and(Producto::query()->withoutGlobalScopes()->where('modelo_id', $propio->id)->count())->toBe(1)
        ->and(Producto::query()->withoutGlobalScopes()->where('modelo_id', $modelo->id)->count())->toBe(1);
});

test('a seller sees the stock grid of their branch but cannot create models, edit lists or change prices', function () {
    $modelo = tcModelo(['M'], ['Negro']);
    $variante = tcVariante($modelo, 'M', 'Negro');
    cargarStock($this->admin, $this->norte, $variante, '7');
    $vendedor = User::factory()->vendedor($this->norte)->create();
    $this->actingAs($vendedor);

    $this->get(route('productos.modelos.ver', $modelo))->assertOk()->assertSee('Polera Básica · M · Negro')->assertDontSee('Cambiar precio');
    Livewire::test(ModeloDetalle::class, ['modelo' => $modelo])->set('sucursal', (string) $this->matriz->id)
        ->assertSeeHtml('data-test="stock-'.$variante->id.'"')->assertSee('7');
    $this->get(route('productos.modelos.crear'))->assertForbidden();
    $this->get(route('productos.modelos.editar', $modelo))->assertForbidden();
    $this->get(route('configuracion.tallas-colores'))->assertForbidden();

    expect(fn () => app(GuardarModelo::class)->crear($vendedor, tcDatos(['S'], ['Negro'], ['nombre' => 'Otra'])))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CambiarPrecioVariante::class)->ejecutar($vendedor, $variante, ['precio_consumidor' => '1']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(GuardarColor::class)->crear($vendedor, 'Lila', null, null))->toThrow(AuthorizationException::class)
        ->and($variante->fresh()->precio_consumidor)->toBe('49.90');
    Livewire::test(ModeloDetalle::class, ['modelo' => $modelo])->call('cambiarPrecio', $variante->id)->assertForbidden();
    Livewire::test(ModeloDetalle::class, ['modelo' => $modelo])->call('usarPrecioModelo', $variante->id)->assertForbidden();
});

test('the model form suggests SKUs, marks duplicates before saving and creates only the checked combinations', function () {
    productoDe($this->empresa, ['nombre' => 'Polera vieja', 'sku' => 'POL001-L-NEG']);
    [$s, $m, $l] = tcTallas(['S', 'M', 'L']);
    [$negro, $blanco] = tcColores(['Negro', 'Blanco']);

    $pantalla = Livewire::test(ModeloFormulario::class)
        ->set('nombre', 'Polera Básica')->set('precio_consumidor', '49,90')->set('sku_base', 'pol001')
        ->set('grupo_talla_id', (string) GrupoTalla::query()->where('nombre', 'Letras')->value('id'))
        ->set('tallas', [(string) $s, (string) $m, (string) $l])->set('colores', [(string) $negro, (string) $blanco])
        ->assertSet("combinaciones.{$s}-{$negro}.sku", 'POL001-S-NEG')
        ->assertSet("combinaciones.{$m}-{$blanco}.sku", 'POL001-M-BLA')
        ->assertSee('Ya existe en otro producto.')
        ->assertSee('6 nuevas');

    // Cambiar el SKU base actualiza las sugerencias que nadie editó; la editada se respeta.
    $pantalla->set("combinaciones.{$s}-{$negro}.sku", 'PROPIO-1')->set('sku_base', 'PB')
        ->assertSet("combinaciones.{$s}-{$negro}.sku", 'PROPIO-1')
        ->assertSet("combinaciones.{$l}-{$negro}.sku", 'PB-L-NEG')
        ->assertDontSee('Ya existe en otro producto.')
        ->set("combinaciones.{$s}-{$blanco}.crear", false)
        ->assertSee('5 nuevas')
        ->call('guardar')->assertHasNoErrors();

    $modelo = Modelo::query()->sole();
    $pantalla->assertRedirect(route('productos.modelos.ver', $modelo));

    expect(Producto::query()->where('modelo_id', $modelo->id)->orderBy('id')->pluck('sku')->all())->toBe(['PROPIO-1', 'PB-M-NEG', 'PB-L-NEG', 'PB-M-BLA', 'PB-L-BLA'])
        ->and($modelo->precio_consumidor)->toBe('49.90')
        ->and(DB::table('stock_niveles')->count())->toBe(0);

    // Editar: las existentes se conservan y quitar un color muestra qué combinaciones dejan el modelo.
    Livewire::test(ModeloFormulario::class, ['modelo' => $modelo])
        ->assertSet('tallas', [(string) $s, (string) $m, (string) $l])
        ->assertSet("combinaciones.{$s}-{$blanco}.crear", false)
        ->assertSee('0 nuevas')
        ->set('colores', [(string) $negro])
        ->assertSee('Estas combinaciones dejan el modelo')
        ->assertSee('2 se quitan')
        ->call('guardar')->assertHasNoErrors();

    expect(Producto::query()->where('modelo_id', $modelo->id)->count())->toBe(3);
});

test('the catalog shows each model as one row with its total stock and its variants, with the Modelos / Sueltos filter', function () {
    $modelo = tcModelo(['M', 'L'], ['Negro']);
    $suelto = productoDe($this->empresa, ['nombre' => 'Cinturón de cuero', 'sku' => 'CIN-01']);
    cargarStock($this->admin, $this->matriz, tcVariante($modelo, 'M', 'Negro'), '4');
    cargarStock($this->admin, $this->matriz, tcVariante($modelo, 'L', 'Negro'), '3');

    Livewire::test(Productos::class)
        ->assertSeeHtml('data-test="fila-modelo-'.$modelo->id.'"')
        ->assertSee('2 combinaciones')
        ->assertViewHas('resumenModelos', fn (array $r): bool => $r[$modelo->id] === ['variantes' => 2, 'stock' => '7.000', 'alertas' => 0, 'precio_propio' => 0, 'sin_control' => 0])
        ->assertSee('Polera Básica · L · Negro')
        ->assertSee('Cinturón de cuero')
        // Un solo «Nuevo producto»: el modelo se crea desde él con «Este producto tiene tallas y colores».
        ->assertDontSeeHtml('data-test="nuevo-modelo"')->assertSee('Nuevo producto')
        ->set('tipo', 'sueltos')->assertDontSee('Polera Básica')->assertSee('Cinturón de cuero')
        ->set('tipo', 'modelos')->assertSee('Polera Básica')->assertDontSee('Cinturón de cuero')
        // Buscar por el SKU de una combinación encuentra su modelo y solo esa combinación.
        ->set('tipo', '')->set('buscar', 'POL001-L')
        ->assertSee('Polera Básica · L · Negro')->assertDontSee('Polera Básica · M · Negro')->assertDontSee('Cinturón de cuero');

    // La ficha de una combinación muestra de qué modelo es y no deja cambiar lo que gobierna el modelo.
    Livewire::test(ProductoFormulario::class, ['producto' => tcVariante($modelo, 'M', 'Negro')])
        ->assertSeeHtml('data-test="aviso-variante"')->assertSee('Combinación del modelo Polera Básica')
        ->set('precio_consumidor', '1.00')->set('nombre', 'Otra cosa')->set('sku', 'POL001-M-NEGRO')
        ->call('guardar')->assertHasNoErrors();

    expect(tcVariante($modelo, 'M', 'Negro')->only(['nombre', 'precio_consumidor', 'sku']))
        ->toBe(['nombre' => 'Polera Básica · M · Negro', 'precio_consumidor' => '49.90', 'sku' => 'POL001-M-NEGRO'])
        ->and(fn () => app(GuardarProducto::class)->actualizarPrecioConsumidor($this->admin, tcVariante($modelo, 'M', 'Negro'), '5.00'))
        ->toThrow(ValidationException::class, 'Esta combinación usa el precio de su modelo.');

    // Un producto suelto se edita como siempre.
    app(GuardarProducto::class)->actualizarPrecioConsumidor($this->admin, $suelto, '35.10');
    expect($suelto->fresh()->precio_consumidor)->toBe('35.10');
});

test('without the variantes capability the screens answer 404 and the actions are rejected', function () {
    $modelo = tcModelo(['M'], ['Negro']);
    $variante = tcVariante($modelo, 'M', 'Negro');
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::General, []);

    foreach ([route('configuracion.tallas-colores'), route('productos.modelos.crear'), route('productos.modelos.ver', $modelo),
        route('productos.modelos.editar', $modelo), route('productos.modelos.imagen', $modelo)] as $url) {
        $this->get($url)->assertNotFound();
    }

    $sinCapacidad = 'La empresa no tiene activa la función';
    expect(fn () => tcModelo(['S'], ['Negro'], ['nombre' => 'Otra']))->toThrow(ValidationException::class, $sinCapacidad)
        ->and(fn () => app(CambiarPrecioVariante::class)->ejecutar($this->admin, $variante, null))->toThrow(ValidationException::class, $sinCapacidad)
        ->and(fn () => app(GuardarColor::class)->crear($this->admin, 'Lila', null, null))->toThrow(ValidationException::class, $sinCapacidad);

    // La combinación queda como un producto suelto más: se lista y se edita completa.
    Livewire::test(Productos::class)->assertSee('Polera Básica · M · Negro')->assertDontSee('Nuevo modelo')->assertDontSeeHtml('data-test="filtro-tipo"');
    // «Nuevo producto» no ofrece tallas y colores, y forzar el interruptor no hace nada.
    Livewire::test(ProductoFormulario::class)->assertDontSeeHtml('data-test="producto-con-variantes"')
        ->set('con_variantes', true)->assertSet('con_variantes', false)->assertDontSeeHtml('data-test="modelo-cuadricula"');
    app(GuardarProducto::class)->actualizarPrecioConsumidor($this->admin, $variante, '45.00');
    expect($variante->fresh()->only(['precio_consumidor', 'precio_propio']))->toBe(['precio_consumidor' => '45.00', 'precio_propio' => true]);
});

test('Nuevo producto with «tiene tallas y colores» creates the model and one product per combination; unchecked it is a normal product', function () {
    productoDe($this->empresa, ['nombre' => 'Polera vieja', 'sku' => 'POL001-L-NEG']);
    [$s, $m, $l] = tcTallas(['S', 'M', 'L']);
    [$negro, $blanco] = tcColores(['Negro', 'Blanco']);

    $pantalla = Livewire::test(ProductoFormulario::class)
        ->assertSeeHtml('data-test="producto-con-variantes"')
        ->assertSeeHtml('id="codigo_barras"')->assertDontSeeHtml('data-test="modelo-cuadricula"')
        ->set('con_variantes', true)
        // Con tallas y colores: SKU y código de barras pasan a cada celda; no se pregunta cómo se vende ni el control de stock.
        ->assertSeeHtml('data-test="modelo-cuadricula"')->assertSeeHtml('id="sku_base"')
        ->assertDontSeeHtml('id="codigo_barras"')->assertDontSeeHtml('data-test="producto-controla-stock"')
        ->assertDontSee('¿Cómo se vende este producto? *')
        ->set('nombre', 'Polera Básica')->set('precio_consumidor', '49,90')->set('precio_minorista', '45,50')
        ->set('unidad_medida_id', (string) $this->unidad->id)->set('stock_minimo', '2')->set('sku_base', 'pol001')
        ->set('grupo_talla_id', (string) GrupoTalla::query()->where('nombre', 'Letras')->value('id'))
        ->set('tallas', [(string) $s, (string) $m, (string) $l])->set('colores', [(string) $negro, (string) $blanco])
        ->assertSet("combinaciones.{$s}-{$negro}.sku", 'POL001-S-NEG')
        ->assertSee('Ya existe en otro producto.')->assertSee('6 nuevas')
        ->set("combinaciones.{$l}-{$negro}.sku", 'POL001-L-NEG-2')
        ->set("combinaciones.{$s}-{$blanco}.crear", false)
        ->set("combinaciones.{$m}-{$negro}.codigo_barras", '7790001112223')
        ->call('guardar')->assertHasNoErrors();

    $modelo = Modelo::query()->sole();
    $pantalla->assertRedirect(route('productos.modelos.ver', $modelo));
    $variantes = Producto::query()->where('modelo_id', $modelo->id)->orderBy('id')->get();

    expect($modelo->only(['nombre', 'sku_base', 'precio_consumidor', 'precio_minorista', 'unidad_medida_id']))
        ->toBe(['nombre' => 'Polera Básica', 'sku_base' => 'POL001', 'precio_consumidor' => '49.90', 'precio_minorista' => '45.50', 'unidad_medida_id' => $this->unidad->id])
        ->and($variantes->pluck('sku')->all())->toBe(['POL001-S-NEG', 'POL001-M-NEG', 'POL001-L-NEG-2', 'POL001-M-BLA', 'POL001-L-BLA'])
        ->and($variantes->firstWhere('sku', 'POL001-M-NEG')->codigo_barras)->toBe('7790001112223')
        ->and($variantes->pluck('nombre')->first())->toBe('Polera Básica · S · Negro')
        ->and($variantes->every(fn (Producto $v): bool => $v->controla_stock && $v->tipo_venta === 'unidad' && $v->stock_minimo === '2.000'))->toBeTrue()
        ->and(DB::table('stock_niveles')->count())->toBe(0)
        ->and(Auditoria::query()->where('accion', 'modelo.creado')->count())->toBe(1);

    // Sin marcar, «Nuevo producto» crea un producto normal como siempre.
    Livewire::test(ProductoFormulario::class)
        ->set('nombre', 'Cinturón de cuero')->set('precio_consumidor', '80')
        ->set('unidad_medida_id', (string) $this->unidad->id)->set('sku', 'CIN-01')
        ->call('guardar')->assertHasNoErrors()->assertRedirect(route('productos.index'));

    expect(Producto::query()->where('sku', 'CIN-01')->sole()->only(['modelo_id', 'talla_id', 'color_id']))
        ->toBe(['modelo_id' => null, 'talla_id' => null, 'color_id' => null])
        ->and(Modelo::query()->count())->toBe(1);
});

test('with tallas y colores the name is limited to the model length and nothing is created on an error', function () {
    [$m] = tcTallas(['M']);

    Livewire::test(ProductoFormulario::class)->set('con_variantes', true)
        ->set('nombre', str_repeat('a', 81))->set('precio_consumidor', '10')->set('unidad_medida_id', (string) $this->unidad->id)
        ->set('grupo_talla_id', (string) GrupoTalla::query()->where('nombre', 'Letras')->value('id'))->set('tallas', [(string) $m])
        ->call('guardar')->assertHasErrors(['nombre' => 'max']);

    expect(Modelo::query()->count())->toBe(0)->and(Producto::query()->count())->toBe(0);
});

test('editing a product never offers tallas y colores, and a seller cannot create products at all', function () {
    $producto = productoDe($this->empresa, ['nombre' => 'Cinturón de cuero']);

    Livewire::test(ProductoFormulario::class, ['producto' => $producto])
        ->assertDontSeeHtml('data-test="producto-con-variantes"')
        ->set('con_variantes', true)->assertSet('con_variantes', false)->assertDontSeeHtml('data-test="modelo-cuadricula"');

    $this->actingAs(User::factory()->vendedor($this->norte)->create());
    $this->get(route('productos.crear'))->assertForbidden();
});

test('Nuevo producto with tallas y colores loads the initial stock of each combination in one entry of one branch', function () {
    [$s, $m] = tcTallas(['S', 'M']);
    [$negro, $blanco] = tcColores(['Negro', 'Blanco']);
    $letras = (string) GrupoTalla::query()->where('nombre', 'Letras')->value('id');

    $pantalla = Livewire::test(ProductoFormulario::class)
        ->set('con_variantes', true)
        ->set('nombre', 'Polera Básica')->set('precio_consumidor', '49,90')->set('unidad_medida_id', (string) $this->unidad->id)
        ->set('grupo_talla_id', $letras)->set('tallas', [(string) $s, (string) $m])->set('colores', [(string) $negro, (string) $blanco])
        ->assertDontSeeHtml("data-test=\"stock-{$s}-{$negro}\"")
        ->set('con_stock_inicial', true)
        // La casilla está en «Inventario», la de tallas y colores en su tarjeta y las cantidades en la cuadrícula.
        ->assertSeeInOrder(['Inventario', 'Cargar stock inicial', 'Tallas y colores', 'Este producto tiene tallas y colores', 'Combinaciones', 'Guardar producto'])
        ->assertSeeHtml("data-test=\"stock-{$s}-{$negro}\"")->assertDontSeeHtml('id="stock_cantidad"');

    // Sin ninguna cantidad no se guarda nada (ni el modelo).
    $pantalla->set('stock_sucursal_id', (string) $this->norte->id)->call('guardar')->assertHasErrors(['stock_inicial']);
    expect(Modelo::query()->count())->toBe(0)->and(Producto::query()->count())->toBe(0);

    // S·Negro 5, M·Negro 3, S·Blanco vacía (sin stock) y M·Blanco no se crea: su cantidad se ignora.
    $pantalla->set("stock_combinaciones.{$s}-{$negro}", '5')->set("stock_combinaciones.{$m}-{$negro}", '3')
        ->set("combinaciones.{$m}-{$blanco}.crear", false)->set("stock_combinaciones.{$m}-{$blanco}", '9')
        ->call('guardar')->assertHasNoErrors();

    $modelo = Modelo::query()->sole();
    $pantalla->assertRedirect(route('productos.modelos.ver', $modelo));
    $operacion = OperacionInventario::query()->sole();

    expect(Producto::query()->where('modelo_id', $modelo->id)->count())->toBe(3)
        ->and(stockDe($this->norte, tcVariante($modelo, 'S', 'Negro')))->toBe('5.000')
        ->and(stockDe($this->norte, tcVariante($modelo, 'M', 'Negro')))->toBe('3.000')
        ->and(stockDe($this->norte, tcVariante($modelo, 'S', 'Blanco')))->toBe('0.000')
        ->and(stockDe($this->matriz, tcVariante($modelo, 'S', 'Negro')))->toBe('0.000')
        ->and($operacion->motivo)->toBe('inventario_inicial')
        ->and($operacion->sucursal_id)->toBe($this->norte->id)
        ->and($operacion->nota)->toBe('Stock inicial de Polera Básica')
        ->and(DB::table('movimientos_stock')->where('operacion_id', $operacion->id)->count())->toBe(2)
        ->and(DB::table('movimientos_caja')->count())->toBe(0)
        ->and(session('aviso'))->toBe('Producto Polera Básica creado con sus tallas y colores y su stock inicial en Sucursal Norte.');

    // Una cantidad con decimales en unidades no guarda nada: ni el modelo ni sus combinaciones.
    Livewire::test(ProductoFormulario::class)->set('con_variantes', true)
        ->set('nombre', 'Polera Dos')->set('precio_consumidor', '30')->set('unidad_medida_id', (string) $this->unidad->id)
        ->set('grupo_talla_id', $letras)->set('tallas', [(string) $s])->set('colores', [(string) $negro])
        ->set('con_stock_inicial', true)->set('stock_sucursal_id', (string) $this->norte->id)
        ->set("stock_combinaciones.{$s}-{$negro}", '1,5')
        ->call('guardar')->assertHasErrors(['stock_inicial'])->assertSee('se mide en unidades enteras');

    expect(Modelo::query()->count())->toBe(1)->and(OperacionInventario::query()->count())->toBe(1);
});

test('the consumer price of a combination is edited in the catalog list: it gets its own price and keeps the other two', function () {
    // Pedido del propietario (11/11/2026): al desplegar un modelo, el precio de cada combinación no se podía editar.
    $modelo = tcModelo(['M', 'L'], ['Negro']);
    $variante = tcVariante($modelo, 'L', 'Negro');
    $otra = tcVariante($modelo, 'M', 'Negro');

    Livewire::test(Productos::class)
        ->assertSeeHtml('wire:click="iniciarEdicionPrecio('.$variante->id.')"')
        ->call('iniciarEdicionPrecio', $variante->id)->assertSet('editandoPrecio', '49.9')
        ->set('editandoPrecio', 'abc')->call('guardarPrecio')->assertHasErrors('editandoPrecio')
        ->set('editandoPrecio', '54,50')->call('guardarPrecio')->assertHasNoErrors()
        ->assertSet('editandoProductoId', null)->assertSee('Precio consumidor actualizado a Bs 54,50');

    expect($variante->fresh()->only(['precio_consumidor', 'precio_minorista', 'precio_mayorista', 'precio_propio']))
        ->toBe(['precio_consumidor' => '54.50', 'precio_minorista' => '45.50', 'precio_mayorista' => '40.25', 'precio_propio' => true])
        ->and($otra->fresh()->precio_consumidor)->toBe('49.90')
        ->and(Auditoria::query()->where('accion', 'producto.precio_variante')->where('entidad_id', $variante->id)->count())->toBe(1);

    // Volver a escribir el precio del modelo la devuelve al precio del modelo (sigue sus cambios).
    Livewire::test(Productos::class)->call('iniciarEdicionPrecio', $variante->id)->set('editandoPrecio', '49.90')->call('guardarPrecio');
    expect($variante->fresh()->only(['precio_consumidor', 'precio_propio']))->toBe(['precio_consumidor' => '49.90', 'precio_propio' => false]);

    // Un vendedor no la edita; otra empresa tampoco, ni cambiando el id.
    $vendedor = User::factory()->vendedor($this->norte)->create();
    $this->actingAs($vendedor);
    Livewire::test(Productos::class)->assertDontSeeHtml('iniciarEdicionPrecio('.$variante->id.')')
        ->call('iniciarEdicionPrecio', $variante->id)->assertForbidden();

    [$otraEmpresa, $adminOtra] = empresaConAdministrador();
    $this->actingAs($adminOtra);
    Livewire::test(Productos::class)->call('iniciarEdicionPrecio', $variante->id)->assertNotFound();
    expect($variante->fresh()->precio_consumidor)->toBe('49.90');
});
