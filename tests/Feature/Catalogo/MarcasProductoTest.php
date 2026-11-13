<?php

use App\Livewire\Catalogo\ProductoFormulario;
use App\Livewire\Catalogo\Productos;
use App\Livewire\Inventario\NuevoAjuste;
use App\Livewire\Pedidos\Editor;
use App\Models\Auditoria;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Marca del producto (decisión del propietario, 11/11/2026): una farmacia vende Paracetamol 500 mg genérico a Bs 1 y
 * de marca a Bs 2. Cada marca es un producto distinto; la marca los distingue en buscadores, listado, ticket y factura.
 */

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador(['rubro' => 'farmacia']);
    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id]);
    $this->actingAs($this->admin);
    $this->generico = productoDe($this->empresa, ['nombre' => 'Paracetamol 500 mg', 'marca' => 'Genérico', 'sku' => 'PAR-001', 'precio_consumidor' => '1.00']);
    $this->bayer = productoDe($this->empresa, ['nombre' => 'Paracetamol 500 mg', 'marca' => 'Bayer', 'sku' => 'PAR-002', 'precio_consumidor' => '2.00']);
});

test('the product form saves the brand cleaned, audits it and labels it by line of business', function () {
    $unidad = new UnidadMedida(['nombre' => 'Unidad', 'abreviatura' => 'u', 'admite_decimales' => false]);
    $unidad->empresa_id = $this->empresa->id;
    $unidad->save();

    Livewire::test(ProductoFormulario::class)
        ->assertSee('Marca / laboratorio (opcional)')
        ->assertSeeHtml('<option value="Bayer">')          // marcas ya usadas, para escribirlas siempre igual
        ->set('nombre', 'Ibuprofeno 400 mg')->set('marca', '  Bayer   Bolivia ')
        ->set('unidad_medida_id', (string) $unidad->id)->set('precio_consumidor', '3')
        ->call('guardar')->assertHasNoErrors();

    $nuevo = Producto::query()->where('nombre', 'Ibuprofeno 400 mg')->sole();
    expect($nuevo->marca)->toBe('Bayer Bolivia')
        ->and(Auditoria::query()->where('accion', 'producto.creado')->where('entidad_id', $nuevo->id)->value('despues'))->toMatchArray(['marca' => 'Bayer Bolivia']);

    // Vaciarla la quita; demasiado larga no se guarda.
    Livewire::test(ProductoFormulario::class, ['producto' => $nuevo])->assertSet('marca', 'Bayer Bolivia')
        ->set('marca', str_repeat('x', 61))->call('guardar')->assertHasErrors('marca')
        ->set('marca', '')->call('guardar')->assertHasNoErrors();
    expect($nuevo->refresh()->marca)->toBeNull();

    // En los demás rubros la etiqueta es «Marca».
    $this->empresa->forceFill(['rubro' => 'general'])->save();
    $this->admin->unsetRelation('empresa');
    Livewire::test(ProductoFormulario::class)->assertSee('Marca (opcional)')->assertDontSee('Marca / laboratorio');
});

test('the capture search finds by brand, shows it and tells the two brands apart', function () {
    Livewire::test(NuevoAjuste::class)
        ->set('busqueda', 'bayer')->assertSee('Bayer · PAR-002')->assertDontSee('PAR-001')
        ->set('busqueda', 'Paracetamol 500 mg Bayer')->assertSee('PAR-002')->assertDontSee('PAR-001')
        ->set('busqueda', 'Paracetamol')->assertSee('Genérico · PAR-001')->assertSee('Bayer · PAR-002');

    Livewire::test(Editor::class)->set('busqueda', 'genérico')->assertSee('PAR-001')->assertDontSee('PAR-002');
});

test('the product list filters by brand and another company never sees its brands', function () {
    Livewire::test(Productos::class)
        ->assertSeeHtml('data-test="filtro-marca"')
        ->set('marca', 'Bayer')->assertSee('PAR-002')->assertDontSee('PAR-001')
        ->set('marca', '')->set('buscar', 'genérico')->assertSee('PAR-001')->assertDontSee('PAR-002');

    $csv = $this->get(route('productos.exportar', ['marca' => 'Bayer']))->streamedContent();
    expect($csv)->toContain('Producto;Marca;SKU')->toContain('"Paracetamol 500 mg";Bayer;PAR-002')->not->toContain('PAR-001');

    [$otra, $adminOtra] = empresaConAdministrador();
    $this->actingAs($adminOtra);
    Sucursal::factory()->create(['empresa_id' => $otra->id]);

    Livewire::test(Productos::class)->assertDontSeeHtml('data-test="filtro-marca"')
        ->set('marca', 'Bayer')->assertDontSee('PAR-002');
    Livewire::test(ProductoFormulario::class)->assertDontSeeHtml('<option value="Bayer">');
    expect($this->get(route('productos.exportar', ['marca' => 'Bayer']))->streamedContent())->not->toContain('PAR-002');
});

test('the sale keeps the brand in the ticket and in the line description the invoice uses', function () {
    $sinMarca = productoDe($this->empresa, ['nombre' => 'Alcohol 70°', 'sku' => 'ALC-001', 'precio_consumidor' => '5.00']);
    cargarStock($this->admin, $this->sucursal, $this->bayer, '10');
    cargarStock($this->admin, $this->sucursal, $this->generico, '10');
    cargarStock($this->admin, $this->sucursal, $sinMarca, '10');

    $pedido = crearPedido($this->admin, $this->sucursal, [
        ['producto_id' => $this->bayer->id, 'cantidad' => '1'],
        ['producto_id' => $this->generico->id, 'cantidad' => '2'],
        ['producto_id' => $sinMarca->id, 'cantidad' => '1'],
    ]);
    $venta = confirmarVenta($this->admin, $pedido, ['metodo' => 'efectivo']);

    expect(DB::table('venta_items')->where('venta_id', $venta->id)->orderBy('orden')->pluck('descripcion')->all())
        ->toBe(['Paracetamol 500 mg (Bayer)', 'Paracetamol 500 mg (Genérico)', 'Alcohol 70°'])
        ->and($venta->total)->toBe('9.00');

    $this->get(route('pedidos.imprimir', $pedido))->assertOk()
        ->assertSee('Paracetamol 500 mg (Bayer)')->assertSee('Paracetamol 500 mg (Genérico)');
});

test('the line description is cut to the 150 characters the sale line admits', function () {
    expect(Producto::descripcionConMarca(str_repeat('a', 140), 'Laboratorio largo'))->toHaveLength(150)
        ->and(Producto::descripcionConMarca('Paracetamol', '  '))->toBe('Paracetamol');
});
