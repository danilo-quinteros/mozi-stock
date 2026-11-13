<?php

use App\Domain\Caja\Actions\AbrirTurnoCaja;
use App\Domain\Catalogo\Actions\GuardarModelo;
use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Enums\Capacidad;
use App\Enums\Rubro;
use App\Livewire\Compras\NuevaCompra;
use App\Livewire\Inventario\NuevoAjuste;
use App\Livewire\Inventario\NuevoTraspaso;
use App\Livewire\Pedidos\Editor;
use App\Livewire\Ventas\Pos;
use App\Models\Color;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\Empresa;
use App\Models\GrupoTalla;
use App\Models\Modelo;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\Talla;
use App\Models\UnidadMedida;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * Tallas y colores, etapa V2 (ESPECIFICACION_TALLAS_Y_COLORES.md § 5 y § 10): selector de variante en Pedidos y ventas
 * y en el POS; cuadrícula de cantidades en Compras, Ajustes y Traspasos. Las líneas que resultan son normales: las
 * acciones de dominio (ConfirmarVenta, VenderEnPos, RegistrarCompra, RegistrarAjuste, RegistrarTraspaso) no cambian.
 */

function svUnidad(Empresa $empresa): UnidadMedida
{
    $unidad = new UnidadMedida(['nombre' => 'Unidad', 'abreviatura' => 'u', 'admite_decimales' => false]);
    $unidad->empresa_id = $empresa->id;
    $unidad->save();

    return $unidad;
}

/**
 * Polera Básica (POL001) con tallas del grupo Letras y los colores pedidos.
 *
 * @param  list<string>  $tallas
 * @param  list<string>  $colores
 */
function svModelo(User $admin, UnidadMedida $unidad, array $tallas = ['S', 'M', 'L'], array $colores = ['Negro', 'Blanco'], string $nombre = 'Polera Básica'): Modelo
{
    $grupo = GrupoTalla::query()->where('nombre', 'Letras')->value('id');

    return app(GuardarModelo::class)->crear($admin, [
        'nombre' => $nombre, 'categoria_id' => null, 'unidad_medida_id' => $unidad->id, 'sku_base' => mb_strtoupper(mb_substr($nombre, 0, 3)).'001',
        'precio_consumidor' => '49.90', 'precio_minorista' => null, 'precio_mayorista' => null, 'stock_minimo' => '0',
        'grupo_talla_id' => $tallas === [] ? null : $grupo,
        'tallas' => Talla::query()->where('grupo_talla_id', $grupo)->whereIn('nombre', $tallas)->pluck('id')->all(),
        'colores' => Color::query()->whereIn('nombre', $colores)->pluck('id')->all(),
    ]);
}

function svVariante(Modelo $modelo, ?string $talla, ?string $color): Producto
{
    return Producto::query()->where('modelo_id', $modelo->id)
        ->where('talla_id', $talla === null ? null : Talla::query()->where('grupo_talla_id', $modelo->grupo_talla_id)->where('nombre', $talla)->value('id'))
        ->where('color_id', $color === null ? null : Color::query()->where('nombre', $color)->value('id'))
        ->sole();
}

/** Clave «talla-color» de una variante en la cuadrícula. */
function svClave(Producto $variante): string
{
    return ($variante->talla_id ?? 0).'-'.($variante->color_id ?? 0);
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    $this->unidad = svUnidad($this->empresa);
    $this->actingAs($this->admin);
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::Ropa, [Capacidad::Variantes]);
    $this->modelo = svModelo($this->admin, $this->unidad);
    $this->mNegro = svVariante($this->modelo, 'M', 'Negro');
    $this->lNegro = svVariante($this->modelo, 'L', 'Negro');
    $this->sBlanco = svVariante($this->modelo, 'S', 'Blanco');
    $this->suelto = productoDe($this->empresa, ['nombre' => 'Polerón Liso', 'sku' => 'PLN-01', 'unidad_medida_id' => $this->unidad->id]);
});

test('Pedidos y ventas: the search groups the variants into their model and choosing it opens the selector', function () {
    cargarStock($this->admin, $this->matriz, $this->mNegro, '5');
    $pantalla = Livewire::test(Editor::class)->set('sucursal_id', (string) $this->matriz->id)->set('busqueda', 'Pol');

    // Un modelo (no seis combinaciones) y el producto suelto.
    expect($pantalla->instance()->sugerenciasModelos->pluck('nombre')->all())->toBe(['Polera Básica'])
        ->and($pantalla->instance()->sugerenciasModelos->first()->combinaciones)->toBe(6)
        ->and($pantalla->instance()->sugerencias->pluck('nombre')->all())->toBe(['Polerón Liso']);
    $pantalla->assertSee('6 combinaciones')->assertSee('Elegir combinación');

    // Enter con un solo modelo y ningún suelto abre el selector.
    $pantalla->call('agregarDesdeBuscador', 'Polera')
        ->assertSet('selectorModeloId', $this->modelo->id)
        ->assertSet('busqueda', '')
        ->assertSeeHtml('data-test="selector-variante"');

    // Con la talla agotada el botón está deshabilitado; con stock agrega una unidad por toque, como el buscador.
    $selector = $pantalla->instance()->selector;
    expect($selector['celdas'][svClave($this->mNegro)]['disponible'])->toBeTrue()
        ->and($selector['celdas'][svClave($this->lNegro)]['disponible'])->toBeFalse()
        ->and($selector['celdas'][svClave($this->mNegro)]['stock'])->toBe('5.000');

    $pantalla->call('elegirColorSelector', (string) $this->mNegro->color_id)
        ->call('elegirVariante', $this->mNegro->id)
        ->call('elegirVariante', $this->mNegro->id)
        ->call('elegirVariante', $this->lNegro->id)
        ->assertSet('lineas.0.id', $this->mNegro->id)
        ->assertSet('lineas.0.cantidad', '2')
        ->assertCount('lineas', 1)
        ->assertSee('No hay existencias de Polera Básica · L · Negro en esta sucursal.')
        ->call('cerrarSelector')
        ->assertSet('selectorModeloId', null)
        ->assertSee('Polera Básica · M · Negro');
});

test('POS: a model is one card that opens the selector, scanning a variant adds it directly and stock is checked again', function () {
    cargarStock($this->admin, $this->matriz, $this->mNegro, '2');
    $codigo = '7790000000123';
    $this->lNegro->forceFill(['codigo_barras' => $codigo])->save();
    cargarStock($this->admin, $this->matriz, $this->lNegro, '1');
    app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->matriz->cajaPrincipal(), '100.00');

    $pos = Livewire::test(Pos::class)
        ->assertSeeHtml('data-test="pos-modelo-'.$this->modelo->id.'"')
        ->assertDontSeeHtml('wire:key="pos-producto-'.$this->mNegro->id.'"')
        ->assertSeeHtml('wire:key="pos-producto-'.$this->suelto->id.'"')
        ->assertSee('6 combinaciones');

    expect($pos->viewData('totalProductos'))->toBe(2);

    // El código de barras de una combinación la agrega sin abrir el selector.
    $pos->call('agregarDesdeBuscador', $codigo)
        ->assertSet('lineas.0.id', $this->lNegro->id)
        ->assertSet('selectorModeloId', null);

    // Desde la tarjeta: el POS vuelve a revisar el stock al agregar (hay 2 de M · Negro).
    $pos->call('abrirSelector', $this->modelo->id)
        ->assertSet('selectorModeloId', $this->modelo->id)
        ->call('elegirVariante', $this->mNegro->id)
        ->call('elegirVariante', $this->mNegro->id)
        ->call('elegirVariante', $this->mNegro->id)
        ->assertSet('lineas.1.id', $this->mNegro->id)
        ->assertSet('lineas.1.cantidad', '2')
        ->assertSee('No hay más existencias de Polera Básica · M · Negro en esta sucursal.');
});

test('purchases: the grid becomes one normal line per combination with the shared cost, editable per line', function () {
    $proveedor = new Proveedor(['nombre' => 'Textiles Andinos']);
    $proveedor->empresa_id = $this->empresa->id;
    $proveedor->save();

    $pantalla = Livewire::test(NuevaCompra::class)
        ->set('sucursal_id', (string) $this->matriz->id)
        ->set('proveedor_id', (string) $proveedor->id)
        // Elegir el modelo entre las sugerencias abre la cuadrícula y cierra la lista de sugerencias.
        ->set('busqueda', 'Polera')
        ->assertSeeHtml('wire:key="sugerencia-modelo-'.$this->modelo->id.'"')
        ->call('abrirSelector', $this->modelo->id)
        ->assertSet('selectorModeloId', $this->modelo->id)
        ->assertSet('busqueda', '')
        ->assertDontSeeHtml('wire:key="sugerencia-modelo-'.$this->modelo->id.'"')
        ->assertSee('Costo unitario para todas (Bs)')
        ->set('selectorCantidades.'.svClave($this->mNegro), '3')
        ->set('selectorCantidades.'.svClave($this->lNegro), '2')
        ->set('selectorCosto', '30,50')
        ->call('agregarCuadricula')
        ->assertHasNoErrors()
        ->assertSet('selectorModeloId', null)
        ->assertCount('lineas', 2)
        ->assertSee('2 combinaciones agregadas a la lista.');

    $lineas = collect($pantalla->get('lineas'))->keyBy('id');
    expect($lineas[$this->mNegro->id]['cantidad'])->toBe('3')
        ->and($lineas[$this->mNegro->id]['costo'])->toBe('30,50')
        ->and($lineas[$this->lNegro->id]['cantidad'])->toBe('2');

    // Otra vez la cuadrícula sobre una línea que ya existe: suma la cantidad y respeta el costo ya escrito.
    $indiceL = collect($pantalla->get('lineas'))->search(fn (array $l): bool => $l['id'] === $this->lNegro->id);
    $pantalla->set("lineas.{$indiceL}.costo", '31')
        ->call('abrirSelector', $this->modelo->id)
        ->set('selectorCantidades.'.svClave($this->lNegro), '1')
        ->set('selectorCosto', '99')
        ->call('agregarCuadricula')
        ->assertSet("lineas.{$indiceL}.cantidad", '3')
        ->assertSet("lineas.{$indiceL}.costo", '31');

    $pantalla->call('revisar')->assertSet('confirmando', true)->call('confirmar');

    $compra = Compra::query()->sole();
    expect(stockDe($this->matriz, $this->mNegro))->toBe('3.000')
        ->and(stockDe($this->matriz, $this->lNegro))->toBe('3.000')
        ->and(stockDe($this->matriz, $this->sBlanco))->toBe('0.000')
        ->and(CompraItem::query()->where('compra_id', $compra->id)->orderBy('producto_id')->pluck('costo_unitario', 'producto_id')->all())
        ->toEqual([$this->mNegro->id => '30.50', $this->lNegro->id => '31.00'])
        ->and($compra->total)->toBe('184.50');
});

test('adjustments and transfers: the grid validates every quantity and shows the stock of the branch', function () {
    cargarStock($this->admin, $this->matriz, $this->mNegro, '4');

    $ajuste = Livewire::test(NuevoAjuste::class)
        ->set('sucursal_id', (string) $this->matriz->id)
        ->call('abrirSelector', $this->modelo->id)
        ->assertDontSee('Costo unitario para todas (Bs)')
        ->call('agregarCuadricula')
        ->assertHasErrors(['selector'])
        ->set('selectorCantidades.'.svClave($this->mNegro), '1,5')
        ->set('selectorCantidades.'.svClave($this->lNegro), 'abc')
        ->set('selectorCantidades.'.svClave($this->sBlanco), '-2')
        ->call('agregarCuadricula')
        ->assertHasErrors(['selectorCantidades.'.svClave($this->mNegro), 'selectorCantidades.'.svClave($this->lNegro), 'selectorCantidades.'.svClave($this->sBlanco)])
        ->assertCount('lineas', 0)
        ->assertSee('Usa cantidades enteras.')
        ->set('selectorCantidades.'.svClave($this->mNegro), '2')
        ->set('selectorCantidades.'.svClave($this->lNegro), '')
        ->set('selectorCantidades.'.svClave($this->sBlanco), '')
        ->call('agregarCuadricula')
        ->assertHasNoErrors()
        ->assertSet('lineas.0.id', $this->mNegro->id)
        ->assertSet('lineas.0.cantidad', '2');

    expect($ajuste->instance()->selector)->toBeNull();

    // Traspaso: la cuadrícula muestra el stock del origen y produce las líneas que RegistrarTraspaso ya conoce.
    $traspaso = Livewire::test(NuevoTraspaso::class)
        ->set('sucursal_id', (string) $this->matriz->id)
        ->set('sucursal_destino_id', (string) $this->norte->id)
        ->call('abrirSelector', $this->modelo->id)
        ->assertSee('Stock: 4');

    expect($traspaso->instance()->selector['celdas'][svClave($this->mNegro)]['stock'])->toBe('4.000');

    $traspaso->set('selectorCantidades.'.svClave($this->mNegro), '3')
        ->call('agregarCuadricula')
        ->call('revisar')
        ->assertSet('confirmando', true)
        ->call('confirmar');

    expect(stockDe($this->matriz, $this->mNegro))->toBe('1.000')
        ->and(stockDe($this->norte, $this->mNegro))->toBe('3.000')
        ->and(stockDe($this->norte, $this->lNegro))->toBe('0.000');
});

test('company B cannot open, choose or fill the grid of a model of company A, even forging the call', function () {
    cargarStock($this->admin, $this->matriz, $this->mNegro, '5');
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    Sucursal::factory()->create(['empresa_id' => $empresaB->id, 'nombre' => 'Central B']);
    $unidadB = svUnidad($empresaB);
    app(CambiarFuncionesEmpresa::class)->ejecutar($adminB, $empresaB, Rubro::Ropa, [Capacidad::Variantes]);
    $modeloB = svModelo($adminB, $unidadB, ['M'], ['Negro', 'Rojo'], 'Polera B');

    $pantalla = Livewire::test(NuevoAjuste::class)->set('busqueda', 'Polera');
    expect($pantalla->instance()->sugerenciasModelos->pluck('nombre')->all())->toBe(['Polera B']);

    $pantalla->call('abrirSelector', $this->modelo->id)
        ->assertSet('selectorModeloId', null)
        ->assertSee('El producto no está disponible.');

    // Con su propio modelo abierto, una celda o un id de A no agregan nada.
    $pantalla->call('abrirSelector', $modeloB->id)
        ->set('selectorCantidades.'.svClave($this->mNegro), '5')
        ->call('agregarCuadricula')
        ->assertHasErrors(['selector'])
        ->assertCount('lineas', 0);

    Livewire::test(Editor::class)->call('abrirSelector', $modeloB->id)
        ->call('elegirVariante', $this->mNegro->id)
        ->assertCount('lineas', 0)
        ->assertSee('El producto no está disponible.');

    // El modelo abierto lo fija el servidor.
    expect(fn () => Livewire::test(Editor::class)->set('selectorModeloId', $this->modelo->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('a seller always sees the stock of their own branch in the selector, even asking for another one', function () {
    cargarStock($this->admin, $this->matriz, $this->mNegro, '5');
    cargarStock($this->admin, $this->norte, $this->lNegro, '1');
    $vendedor = User::factory()->vendedor($this->norte)->create();
    $this->actingAs($vendedor);

    $pantalla = Livewire::test(Editor::class)->set('sucursal_id', (string) $this->matriz->id)->call('abrirSelector', $this->modelo->id);
    $celdas = $pantalla->instance()->selector['celdas'];

    expect($celdas[svClave($this->mNegro)]['stock'])->toBe('0.000')
        ->and($celdas[svClave($this->mNegro)]['disponible'])->toBeFalse()
        ->and($celdas[svClave($this->lNegro)]['stock'])->toBe('1.000')
        ->and($celdas[svClave($this->lNegro)]['disponible'])->toBeTrue();

    $pantalla->call('elegirVariante', $this->mNegro->id)->assertCount('lineas', 0)
        ->call('elegirVariante', $this->lNegro->id)->assertSet('lineas.0.id', $this->lNegro->id);
});

test('without the capability the search lists variants as loose products and the selector does not open', function () {
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::General, []);
    app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->matriz->cajaPrincipal(), '100.00');

    $pantalla = Livewire::test(Editor::class)->set('busqueda', 'Polera Básica');

    expect($pantalla->instance()->sugerenciasModelos)->toBeEmpty()
        ->and($pantalla->instance()->sugerencias->count())->toBe(6);

    $pantalla->call('abrirSelector', $this->modelo->id)
        ->assertSet('selectorModeloId', null)
        ->assertSee('El producto no está disponible.');

    Livewire::test(Pos::class)->assertDontSeeHtml('data-test="pos-modelo-'.$this->modelo->id.'"')
        ->assertSeeHtml('wire:key="pos-producto-'.$this->mNegro->id.'"');
});

test('a model with a single active combination is added directly, and an inactive model does not open', function () {
    $unico = svModelo($this->admin, $this->unidad, [], ['Rojo'], 'Gorra Trucker');
    $gorra = svVariante($unico, null, 'Rojo');
    cargarStock($this->admin, $this->matriz, $gorra, '3');

    Livewire::test(Editor::class)->set('sucursal_id', (string) $this->matriz->id)
        ->call('abrirSelector', $unico->id)
        ->assertSet('selectorModeloId', null)
        ->assertSet('lineas.0.id', $gorra->id);

    $this->modelo->forceFill(['activo' => false])->save();

    Livewire::test(NuevoAjuste::class)->call('abrirSelector', $this->modelo->id)
        ->assertSet('selectorModeloId', null)
        ->assertSee('El producto no está disponible.');
});
