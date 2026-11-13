<?php

use App\Domain\Caja\Actions\AbrirTurnoCaja;
use App\Domain\Catalogo\Actions\AgruparEnModelo;
use App\Domain\Catalogo\Actions\GuardarModelo;
use App\Domain\Catalogo\Actions\QuitarDeModelo;
use App\Domain\Catalogo\AdivinarVariante;
use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Enums\Capacidad;
use App\Enums\Rubro;
use App\Livewire\Catalogo\AgruparModelo;
use App\Livewire\Catalogo\ModeloDetalle;
use App\Livewire\Catalogo\ProductoFormulario;
use App\Livewire\Catalogo\Productos;
use App\Livewire\Ventas\Pos;
use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Color;
use App\Models\Empresa;
use App\Models\GrupoTalla;
use App\Models\Modelo;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\Talla;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Models\VentaItem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * Tallas y colores, etapa V3 (ESPECIFICACION_TALLAS_Y_COLORES.md § 4.5, § 9 y § 10): foto por variante con respaldo
 * del modelo, «Agrupar en modelo» de productos sueltos que ya existían (opción B) y «Quitar del modelo».
 */

function agUnidad(Empresa $empresa, string $nombre = 'Unidad'): UnidadMedida
{
    $unidad = new UnidadMedida(['nombre' => $nombre, 'abreviatura' => mb_strtolower(mb_substr($nombre, 0, 2)), 'admite_decimales' => false]);
    $unidad->empresa_id = $empresa->id;
    $unidad->save();

    return $unidad;
}

function agCategoria(string $nombre): Categoria
{
    $categoria = new Categoria(['nombre' => $nombre]);
    $categoria->empresa_id = (int) auth()->user()->empresa_id;
    $categoria->save();

    return $categoria;
}

function agTalla(string $nombre): int
{
    return (int) Talla::query()->whereHas('grupo', fn ($q) => $q->where('nombre', 'Letras'))->where('nombre', $nombre)->value('id');
}

function agColor(string $nombre): int
{
    return (int) Color::query()->where('nombre', $nombre)->value('id');
}

/**
 * @param  array<string, mixed>  $extra
 */
function agSuelto(string $nombre, string $sku, array $extra = []): Producto
{
    return productoDe(test()->empresa, $extra + [
        'nombre' => $nombre, 'sku' => $sku, 'unidad_medida_id' => test()->unidad->id,
        'precio_consumidor' => '49.90', 'precio_minorista' => '45.50', 'precio_mayorista' => '40.25',
    ]);
}

/**
 * @param  list<string>  $tallas
 * @param  list<string>  $colores
 */
function agModelo(array $tallas, array $colores, string $nombre = 'Polera Básica', mixed $imagen = null): Modelo
{
    return app(GuardarModelo::class)->crear(test()->admin, [
        'nombre' => $nombre, 'categoria_id' => null, 'unidad_medida_id' => test()->unidad->id, 'sku_base' => 'POL001',
        'precio_consumidor' => '49.90', 'precio_minorista' => '45.50', 'precio_mayorista' => '40.25', 'stock_minimo' => '0',
        'grupo_talla_id' => $tallas === [] ? null : GrupoTalla::query()->where('nombre', 'Letras')->value('id'),
        'tallas' => array_map('agTalla', $tallas), 'colores' => array_map('agColor', $colores),
    ], $imagen);
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->actingAs($this->admin);
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    $this->unidad = agUnidad($this->empresa);
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::Ropa, [Capacidad::Variantes]);
});

test('the guess reads size and color from the name, longest first, without accents or case', function () {
    $tallas = [['id' => 1, 'nombre' => 'L'], ['id' => 2, 'nombre' => 'XL'], ['id' => 3, 'nombre' => 'XXL'], ['id' => 4, 'nombre' => 'M']];
    $colores = [['id' => 10, 'nombre' => 'Azul'], ['id' => 11, 'nombre' => 'Azul marino'], ['id' => 12, 'nombre' => 'Café']];

    expect(AdivinarVariante::desdeNombre('Polera Básica Azul Marino XL', $tallas, $colores))->toBe(['talla_id' => 2, 'color_id' => 11, 'base' => 'Polera Básica'])
        ->and(AdivinarVariante::desdeNombre('polera m - CAFE', $tallas, $colores))->toBe(['talla_id' => 4, 'color_id' => 12, 'base' => 'polera'])
        ->and(AdivinarVariante::desdeNombre('Gorra Trucker', $tallas, $colores))->toBe(['talla_id' => null, 'color_id' => null, 'base' => 'Gorra Trucker'])
        ->and(AdivinarVariante::desdeNombre('Mochila Mediana Azul', $tallas, $colores))->toBe(['talla_id' => null, 'color_id' => 10, 'base' => 'Mochila Mediana']);
});

test('grouping 3 products with stock and history keeps ids, stock per branch and sales; names change in the catalog, not in past sales', function () {
    $poleras = agCategoria('Poleras');
    $ropa = agCategoria('Ropa');
    $mNegro = agSuelto('Polera Clásica M Negro', 'PC-M-N', ['categoria_id' => $poleras->id]);
    $lNegro = agSuelto('Polera Clásica L Negro', 'PC-L-N', ['categoria_id' => $poleras->id, 'codigo_barras' => '7791234500017']);
    $mBlanco = agSuelto('Polera Clásica M Blanco', 'PC-M-B', ['precio_consumidor' => '55.00']);
    cargarStock($this->admin, $this->matriz, $mNegro, '5');
    cargarStock($this->admin, $this->norte, $mNegro, '2');
    cargarStock($this->admin, $this->matriz, $lNegro, '3');
    cargarStock($this->admin, $this->norte, $mBlanco, '4');
    $venta = confirmarVenta($this->admin, crearPedido($this->admin, $this->matriz, [['producto_id' => $mNegro->id, 'cantidad' => '1']]), ['metodo' => 'efectivo']);
    $movimientos = MovimientoStock::query()->whereIn('producto_id', [$mNegro->id, $lNegro->id, $mBlanco->id])->count();

    // La página propone grupo, talla, color y el nombre del modelo a partir de los nombres.
    $pagina = Livewire::withQueryParams(['productos' => "{$mNegro->id},{$lNegro->id},{$mBlanco->id}"])->test(AgruparModelo::class)
        ->assertSet('grupo_talla_id', (string) GrupoTalla::query()->where('nombre', 'Letras')->value('id'))
        ->assertSet("asignaciones.{$mNegro->id}", ['talla_id' => (string) agTalla('M'), 'color_id' => (string) agColor('Negro')])
        ->assertSet("asignaciones.{$lNegro->id}", ['talla_id' => (string) agTalla('L'), 'color_id' => (string) agColor('Negro')])
        ->assertSet("asignaciones.{$mBlanco->id}", ['talla_id' => (string) agTalla('M'), 'color_id' => (string) agColor('Blanco')])
        ->assertSet('nombre', 'Polera Clásica')
        ->assertSet('categoria_id', (string) $poleras->id)
        ->assertSet('precio_consumidor', '49.9')
        ->set('categoria_id', (string) $ropa->id)
        ->call('revisar')
        ->assertHasNoErrors()
        ->assertSee('Polera Clásica · M · Negro')
        ->assertSeeHtml('data-test="previa-precio-propio"');

    $previa = collect($pagina->get('previa'))->keyBy('id');
    expect($previa[$mNegro->id]['precio_propio'])->toBeFalse()
        ->and($previa[$mBlanco->id]['precio_propio'])->toBeTrue()
        ->and($previa[$mBlanco->id]['nombre_antes'])->toBe('Polera Clásica M Blanco')
        ->and(Modelo::query()->count())->toBe(0);

    $pagina->call('confirmar');
    $modelo = Modelo::query()->sole();
    $pagina->assertRedirect(route('productos.modelos.ver', $modelo));

    // Mismos ids, stock por sucursal, SKU, código e historial. Cambian nombre, categoría y, si difiere, el precio pasa a propio.
    expect(Producto::query()->where('modelo_id', $modelo->id)->orderBy('id')->pluck('id')->all())->toBe([$mNegro->id, $lNegro->id, $mBlanco->id])
        ->and([stockDe($this->matriz, $mNegro), stockDe($this->norte, $mNegro), stockDe($this->matriz, $lNegro), stockDe($this->norte, $mBlanco)])
        ->toBe(['4.000', '2.000', '3.000', '4.000'])
        ->and($mNegro->fresh()->nombre)->toBe('Polera Clásica · M · Negro')
        ->and($lNegro->fresh()->only(['sku', 'codigo_barras', 'categoria_id']))->toBe(['sku' => 'PC-L-N', 'codigo_barras' => '7791234500017', 'categoria_id' => $ropa->id])
        ->and($mNegro->fresh()->precio_propio)->toBeFalse()
        ->and($mBlanco->fresh()->only(['precio_propio', 'precio_consumidor']))->toBe(['precio_propio' => true, 'precio_consumidor' => '55.00'])
        ->and(VentaItem::query()->where('venta_id', $venta->id)->value('descripcion'))->toBe('Polera Clásica M Negro')
        ->and(MovimientoStock::query()->whereIn('producto_id', [$mNegro->id, $lNegro->id, $mBlanco->id])->count())->toBe($movimientos)
        ->and(Auditoria::query()->where('accion', 'modelo.agrupado')->sole()->despues['modelo_nuevo'])->toBeTrue();

    $this->get(route('productos.modelos.ver', $modelo))->assertOk()->assertSee('Polera Clásica · L · Negro');

    // Repetir la confirmación no duplica nada.
    $pagina->call('confirmar')->assertHasErrors();
    expect(Modelo::query()->count())->toBe(1)
        ->and(Auditoria::query()->where('accion', 'modelo.agrupado')->count())->toBe(1);
});

test('grouping into an existing model: equal prices follow the model, a different price stays as its own price', function () {
    $modelo = agModelo(['S'], ['Negro']);
    $igual = agSuelto('Polera Básica M Negro', 'PB-M-N');
    $distinto = agSuelto('Polera Básica L Negro', 'PB-L-N', ['precio_consumidor' => '59.90']);

    app(AgruparEnModelo::class)->ejecutar($this->admin, [$igual->id, $distinto->id], $modelo->id, [], [
        $igual->id => ['talla_id' => agTalla('M'), 'color_id' => agColor('Negro')],
        $distinto->id => ['talla_id' => agTalla('L'), 'color_id' => agColor('Negro')],
    ]);

    expect($igual->fresh()->only(['modelo_id', 'precio_propio', 'nombre']))->toBe(['modelo_id' => $modelo->id, 'precio_propio' => false, 'nombre' => 'Polera Básica · M · Negro'])
        ->and($distinto->fresh()->only(['precio_propio', 'precio_consumidor']))->toBe(['precio_propio' => true, 'precio_consumidor' => '59.90'])
        ->and(Auditoria::query()->where('accion', 'modelo.agrupado')->sole()->despues['modelo_nuevo'])->toBeFalse();

    // El cambio de precio del modelo llega a la que sigue su precio, no a la de precio propio.
    app(GuardarModelo::class)->actualizar($this->admin, $modelo->fresh(), [
        'nombre' => 'Polera Básica', 'unidad_medida_id' => $this->unidad->id, 'sku_base' => 'POL001', 'precio_consumidor' => '52.00',
        'precio_minorista' => '45.50', 'precio_mayorista' => '40.25', 'stock_minimo' => '0', 'grupo_talla_id' => $modelo->grupo_talla_id,
        'tallas' => [agTalla('S'), agTalla('M'), agTalla('L')], 'colores' => [agColor('Negro')],
    ]);

    expect($igual->fresh()->precio_consumidor)->toBe('52.00')
        ->and($distinto->fresh()->precio_consumidor)->toBe('59.90')
        ->and(Producto::query()->where('modelo_id', $modelo->id)->where('activo', true)->count())->toBe(3);
});

test('different units, a repeated combination or a product already in a model are rejected without changes', function () {
    $modelo = agModelo(['M'], ['Negro']);
    $variante = Producto::query()->where('modelo_id', $modelo->id)->sole();
    $kilo = agUnidad($this->empresa, 'Kilo');
    $a = agSuelto('Polera A', 'PA-1');
    $b = agSuelto('Polera B', 'PB-1');
    $otraUnidad = agSuelto('Polera Kilo', 'PK-1', ['unidad_medida_id' => $kilo->id]);
    $accion = app(AgruparEnModelo::class);
    $m = agTalla('M');
    $l = agTalla('L');
    $negro = agColor('Negro');

    $casos = [
        'otra unidad' => [[$a->id, $otraUnidad->id], [$a->id => ['talla_id' => $l, 'color_id' => $negro], $otraUnidad->id => ['talla_id' => agTalla('S'), 'color_id' => $negro]]],
        'repetida entre marcados' => [[$a->id, $b->id], [$a->id => ['talla_id' => $l, 'color_id' => $negro], $b->id => ['talla_id' => $l, 'color_id' => $negro]]],
        'repetida con el modelo' => [[$a->id], [$a->id => ['talla_id' => $m, 'color_id' => $negro]]],
        'ya es variante' => [[$variante->id], [$variante->id => ['talla_id' => $l, 'color_id' => $negro]]],
    ];

    foreach ($casos as $caso => [$ids, $asignaciones]) {
        expect(fn () => $accion->ejecutar($this->admin, $ids, $modelo->id, [], $asignaciones))->toThrow(ValidationException::class);
    }

    // Una talla de otro grupo y un modelo nuevo con nombre repetido tampoco pasan.
    $calzado = (int) Talla::query()->whereHas('grupo', fn ($q) => $q->where('nombre', 'Calzado'))->value('id');
    expect(fn () => $accion->ejecutar($this->admin, [$a->id], $modelo->id, [], [$a->id => ['talla_id' => $calzado, 'color_id' => null]]))->toThrow(ValidationException::class)
        ->and(fn () => $accion->ejecutar($this->admin, [$a->id], null, ['nombre' => 'Polera Básica', 'unidad_medida_id' => $this->unidad->id,
            'precio_consumidor' => '10', 'stock_minimo' => '0'], []))->toThrow(ValidationException::class);

    expect(Producto::query()->whereIn('id', [$a->id, $b->id, $otraUnidad->id])->whereNotNull('modelo_id')->count())->toBe(0)
        ->and(Modelo::query()->count())->toBe(1)
        ->and(Producto::query()->where('modelo_id', $modelo->id)->count())->toBe(1)
        ->and(Auditoria::query()->where('accion', 'modelo.agrupado')->count())->toBe(0);

    // En la página, la repetida se marca en las dos filas antes de confirmar.
    Livewire::withQueryParams(['productos' => "{$a->id},{$b->id}"])->test(AgruparModelo::class)
        ->set("asignaciones.{$a->id}.talla_id", (string) $l)->set("asignaciones.{$a->id}.color_id", (string) $negro)
        ->set("asignaciones.{$b->id}.talla_id", (string) $l)->set("asignaciones.{$b->id}.color_id", (string) $negro)
        ->call('revisar')
        ->assertHasErrors(["asignaciones.{$a->id}", "asignaciones.{$b->id}"])
        ->assertSee('Otro producto marcado tiene la misma talla y color.')
        ->assertSet('previa', []);
});

test('removing from the model returns the product to loose without losing anything; the last active combination stays', function () {
    $modelo = agModelo(['M', 'L'], ['Negro']);
    [$m, $l] = [Producto::query()->where('modelo_id', $modelo->id)->where('talla_id', agTalla('M'))->sole(), Producto::query()->where('modelo_id', $modelo->id)->where('talla_id', agTalla('L'))->sole()];
    cargarStock($this->admin, $this->norte, $m, '6');
    $venta = confirmarVenta($this->admin, crearPedido($this->admin, $this->norte, [['producto_id' => $m->id, 'cantidad' => '1']]), ['metodo' => 'efectivo']);

    Livewire::test(ModeloDetalle::class, ['modelo' => $modelo])
        ->assertSeeHtml('data-test="quitar-'.$m->id.'"')
        ->call('quitarDelModelo', $m->id)
        ->assertHasNoErrors()
        ->assertSee('Polera Básica · M · Negro es ahora un producto suelto.')
        ->call('quitarDelModelo', $l->id)
        ->assertHasErrors(['variante']);

    expect($m->fresh()->only(['id', 'modelo_id', 'talla_id', 'color_id', 'nombre', 'sku', 'precio_propio']))
        ->toBe(['id' => $m->id, 'modelo_id' => null, 'talla_id' => null, 'color_id' => null, 'nombre' => 'Polera Básica · M · Negro', 'sku' => 'POL001-M-NEG', 'precio_propio' => false])
        ->and(stockDe($this->norte, $m))->toBe('5.000')
        ->and(VentaItem::query()->where('venta_id', $venta->id)->value('producto_id'))->toBe($m->id)
        ->and($l->fresh()->modelo_id)->toBe($modelo->id)
        ->and(Auditoria::query()->where('accion', 'producto.quitado_de_modelo')->count())->toBe(1);

    // Ya suelto, se puede volver a agrupar en el mismo modelo.
    app(AgruparEnModelo::class)->ejecutar($this->admin, [$m->id], $modelo->id, [], [$m->id => ['talla_id' => agTalla('M'), 'color_id' => agColor('Negro')]]);
    expect($m->fresh()->modelo_id)->toBe($modelo->id);
});

test('a variant without its own photo shows the model photo, with its own photo its own; company B cannot download them', function () {
    Storage::fake('local');
    $modelo = agModelo(['M', 'L'], ['Negro'], 'Polera Básica', imagenPng('modelo.png'));
    $conFoto = Producto::query()->where('modelo_id', $modelo->id)->where('talla_id', agTalla('M'))->sole();
    $sinFoto = Producto::query()->where('modelo_id', $modelo->id)->where('talla_id', agTalla('L'))->sole();
    Storage::disk('local')->put("empresas/{$this->empresa->id}/productos/propia.png", 'FOTO-PROPIA');
    $conFoto->forceFill(['imagen_path' => "empresas/{$this->empresa->id}/productos/propia.png"])->save();
    $suelto = agSuelto('Gorra Lisa', 'GL-1');

    expect($this->get(route('productos.imagen', $sinFoto))->assertOk()->streamedContent())->toBe(Storage::disk('local')->get($modelo->imagen_path))
        ->and($this->get(route('productos.imagen', $conFoto))->assertOk()->streamedContent())->toBe('FOTO-PROPIA');
    $this->get(route('productos.imagen', $suelto))->assertNotFound();

    expect(Producto::query()->with('modelo:id,imagen_path')->findOrFail($sinFoto->id)->tieneFoto())->toBeTrue()
        ->and(Producto::query()->findOrFail($sinFoto->id)->tieneFoto())->toBeFalse()
        ->and($suelto->tieneFoto())->toBeFalse();

    // Formulario de la combinación, cuadrícula del modelo y miniatura del POS.
    Livewire::test(ProductoFormulario::class, ['producto' => $sinFoto])->assertSeeHtml('data-test="foto-del-modelo"')
        ->assertSee('Sin foto propia: se muestra la del modelo.');
    Livewire::test(ModeloDetalle::class, ['modelo' => $modelo])->assertSeeHtml('data-test="con-foto-'.$conFoto->id.'"')
        ->assertDontSeeHtml('data-test="con-foto-'.$sinFoto->id.'"');
    cargarStock($this->admin, $this->matriz, $sinFoto, '2');
    app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->matriz->cajaPrincipal(), '100.00');
    Livewire::test(Pos::class)->call('agregarDesdeBuscador', 'POL001-L-NEG')->assertSet('lineas.0.id', $sinFoto->id)
        ->assertSeeHtml('src="'.route('productos.imagen', $sinFoto).'"');

    // Otra empresa no descarga ninguna de las dos fotos.
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    app(CambiarFuncionesEmpresa::class)->ejecutar($adminB, $empresaB, Rubro::Ropa, [Capacidad::Variantes]);
    $this->get(route('productos.imagen', $sinFoto))->assertNotFound();
    $this->get(route('productos.imagen', $conFoto))->assertNotFound();
    $this->get(route('productos.modelos.imagen', $modelo))->assertNotFound();

    // Sin la capacidad no hay respaldo: la combinación se comporta como un producto suelto sin foto.
    $this->actingAs($this->admin);
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::General, []);
    $this->get(route('productos.imagen', $sinFoto))->assertNotFound();
    $this->get(route('productos.imagen', $conFoto))->assertOk();
});

test('company B cannot group products of A, group into a model of A, or remove variants of A, even changing IDs', function () {
    $modeloA = agModelo(['M', 'L'], ['Negro']);
    $varianteA = Producto::query()->where('modelo_id', $modeloA->id)->where('talla_id', agTalla('M'))->sole();
    $sueltoA = agSuelto('Polera A M Negro', 'PA-M-N');

    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $unidadB = agUnidad($empresaB);
    app(CambiarFuncionesEmpresa::class)->ejecutar($adminB, $empresaB, Rubro::Ropa, [Capacidad::Variantes]);
    $sueltoB = productoDe($empresaB, ['nombre' => 'Polera B', 'unidad_medida_id' => $unidadB->id]);
    $modeloB = app(GuardarModelo::class)->crear($adminB, ['nombre' => 'Modelo B', 'unidad_medida_id' => $unidadB->id, 'precio_consumidor' => '10',
        'stock_minimo' => '0', 'tallas' => [], 'colores' => []]);
    $accion = app(AgruparEnModelo::class);
    $datosB = ['nombre' => 'Nuevo B', 'unidad_medida_id' => $unidadB->id, 'precio_consumidor' => '10', 'stock_minimo' => '0'];

    expect(fn () => $accion->ejecutar($adminB, [$sueltoA->id], null, $datosB, []))->toThrow(NotFoundHttpException::class)
        ->and(fn () => $accion->ejecutar($adminB, [$sueltoB->id, $sueltoA->id], null, $datosB, []))->toThrow(NotFoundHttpException::class)
        ->and(fn () => $accion->ejecutar($adminB, [$sueltoB->id], $modeloA->id, [], []))->toThrow(NotFoundHttpException::class)
        ->and(fn () => $accion->vistaPrevia($adminB, [$sueltoA->id], $modeloB->id, [], []))->toThrow(NotFoundHttpException::class)
        ->and(fn () => app(QuitarDeModelo::class)->ejecutar($adminB, $varianteA))->toThrow(NotFoundHttpException::class);

    Livewire::withQueryParams(['productos' => (string) $sueltoA->id])->test(AgruparModelo::class)->assertRedirect(route('productos.index'));
    Livewire::test(ModeloDetalle::class, ['modelo' => $modeloB])->call('quitarDelModelo', $varianteA->id)->assertNotFound();

    $this->actingAs($this->admin);
    expect($sueltoA->fresh()->modelo_id)->toBeNull()
        ->and($varianteA->fresh()->modelo_id)->toBe($modeloA->id)
        ->and(Modelo::query()->withoutGlobalScopes()->count())->toBe(2);
});

test('only the administrator groups or removes; the catalog offers the checkboxes only to them and only for loose products', function () {
    $modelo = agModelo(['M', 'L'], ['Negro']);
    $variante = Producto::query()->where('modelo_id', $modelo->id)->where('talla_id', agTalla('M'))->sole();
    $suelto = agSuelto('Polera Suelta M Negro', 'PS-M-N');

    Livewire::test(Productos::class)->assertSeeHtml('data-test="barra-agrupar"')
        ->assertSeeHtml('data-test="marcar-agrupar-'.$suelto->id.'"')
        ->assertDontSeeHtml('data-test="marcar-agrupar-'.$variante->id.'"');
    $this->get(route('productos.modelos.agrupar', ['productos' => $suelto->id]))->assertOk()->assertSee('Agrupar en modelo');

    $vendedor = User::factory()->vendedor($this->matriz)->create();
    $this->actingAs($vendedor);

    Livewire::test(Productos::class)->assertDontSeeHtml('data-test="barra-agrupar"')->assertDontSeeHtml('data-test="marcar-agrupar-');
    $this->get(route('productos.modelos.agrupar', ['productos' => $suelto->id]))->assertForbidden();
    expect(fn () => app(AgruparEnModelo::class)->ejecutar($vendedor, [$suelto->id], $modelo->id, [], [$suelto->id => ['talla_id' => agTalla('S'), 'color_id' => agColor('Negro')]]))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(QuitarDeModelo::class)->ejecutar($vendedor, $variante))->toThrow(AuthorizationException::class);
    Livewire::test(ModeloDetalle::class, ['modelo' => $modelo])->assertDontSee('Quitar del modelo')->call('quitarDelModelo', $variante->id)->assertForbidden();

    expect($suelto->fresh()->modelo_id)->toBeNull()->and($variante->fresh()->modelo_id)->toBe($modelo->id);

    // Sin la capacidad, la página responde 404 y el catálogo no ofrece casillas.
    $this->actingAs($this->admin);
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::General, []);
    $this->get(route('productos.modelos.agrupar', ['productos' => $suelto->id]))->assertNotFound();
    Livewire::test(Productos::class)->assertDontSeeHtml('data-test="marcar-agrupar-');
});
