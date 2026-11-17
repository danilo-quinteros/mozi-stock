<?php

use App\Livewire\Compras\NuevaCompra;
use App\Livewire\Inventario\NuevoAjuste;
use App\Livewire\Inventario\NuevoTraspaso;
use App\Livewire\Pedidos\Editor;
use App\Livewire\Ventas\Pos;
use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Sucursal;
use Livewire\Livewire;

/*
 * Panel «Agregar productos» del buscador compartido: al hacer clic en el buscador de compras, ajustes, traspasos y
 * recetas se abre una lista de hasta 18 productos con pestañas Todos / Stock bajo / categorías, que se filtra con lo
 * que se escribe; los marcados se agregan juntos a la tabla. Pedidos y POS no lo ofrecen.
 */

function categoriaDe(Empresa $empresa, string $nombre): Categoria
{
    $categoria = new Categoria(['nombre' => $nombre]);
    $categoria->empresa_id = $empresa->id;
    $categoria->save();

    return $categoria;
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    $this->actingAs($this->admin);
    $this->limpieza = categoriaDe($this->empresa, 'Limpieza');
    $this->bebidas = categoriaDe($this->empresa, 'Bebidas');
    // Stock bajo en Casa Matriz: sin stock, o en o bajo su mínimo.
    $this->lavandina = productoDe($this->empresa, ['nombre' => 'Lavandina 1 L', 'sku' => 'LIM-01', 'categoria_id' => $this->limpieza->id, 'stock_minimo' => '5']);
    $this->detergente = productoDe($this->empresa, ['nombre' => 'Detergente 1 kg', 'sku' => 'LIM-02', 'categoria_id' => $this->limpieza->id, 'stock_minimo' => '5']);
    $this->gaseosa = productoDe($this->empresa, ['nombre' => 'Gaseosa 2 L', 'sku' => 'BEB-01', 'categoria_id' => $this->bebidas->id]);
    $this->agua = productoDe($this->empresa, ['nombre' => 'Agua 600 ml', 'sku' => 'BEB-02', 'categoria_id' => $this->bebidas->id]);
    cargarStock($this->admin, $this->matriz, $this->lavandina, '2');
    cargarStock($this->admin, $this->matriz, $this->detergente, '20');
    cargarStock($this->admin, $this->matriz, $this->agua, '12');
    // No aparecen: inactivo y sin control de stock.
    productoDe($this->empresa, ['nombre' => 'Jabón descontinuado', 'activo' => false, 'categoria_id' => $this->limpieza->id]);
    productoDe($this->empresa, ['nombre' => 'Servicio de entrega', 'controla_stock' => false]);
});

test('clic en el buscador de compras abre el panel con pestañas, stock de la sucursal y solo productos que se pueden comprar', function () {
    Livewire::test(NuevaCompra::class)
        ->set('sucursal_id', (string) $this->matriz->id)
        ->assertDontSeeHtml('data-test="listado-masivo"')
        ->call('abrirListado')
        ->assertSet('listadoAbierto', true)
        ->assertSeeHtml('data-test="listado-masivo"')
        ->assertSeeHtml('Todos<small>4</small>')
        ->assertSeeHtml('Stock bajo<small>2</small>')
        ->assertSeeHtml('Bebidas<small>2</small>')
        ->assertSeeHtml('Limpieza<small>2</small>')
        ->assertSeeInOrder(['Agua 600 ml', 'Detergente 1 kg', 'Gaseosa 2 L', 'Lavandina 1 L'])
        ->assertSee('Stock 2')
        ->assertSee('mín. 5')
        ->assertSee('Agregar marcados a la tabla')
        ->assertSee('Limpiar selección')
        ->assertDontSee('Jabón descontinuado')
        ->assertDontSee('Servicio de entrega')
        // Stock bajo: la gaseosa (sin stock) y la lavandina (2 de mínimo 5); no el detergente (20) ni el agua.
        ->set('listadoTab', 'stock_bajo')
        ->assertSee('Gaseosa 2 L')->assertSee('Lavandina 1 L')
        ->assertDontSee('Detergente 1 kg')->assertDontSee('Agua 600 ml')
        // En otra sucursal todo está sin stock.
        ->set('sucursal_id', (string) $this->norte->id)
        ->assertSeeHtml('Stock bajo<small>4</small>')
        // Categoría.
        ->set('listadoTab', (string) $this->bebidas->id)
        ->assertSee('Gaseosa 2 L')->assertSee('Agua 600 ml')
        ->assertDontSee('Lavandina 1 L')
        ->call('cerrarListado')
        ->assertSet('listadoAbierto', false)
        ->assertSet('listadoTab', 'todos')
        ->assertDontSeeHtml('data-test="listado-masivo"');
});

test('escribir abre el panel aunque no se haya hecho clic, y la lista desplegable antigua está inhabilitada', function () {
    Livewire::test(NuevoAjuste::class)->set('sucursal_id', (string) $this->matriz->id)
        ->assertSet('listadoAbierto', false)
        ->set('busqueda', 'lavan')
        ->assertSet('listadoAbierto', true)
        ->assertSeeHtml('data-test="listado-masivo"')
        ->assertSee('Lavandina 1 L')
        ->assertDontSeeHtml('role="listbox"')
        // Cerrado y vuelto a escribir: se abre otra vez; vaciar el texto no lo cierra.
        ->call('cerrarListado')
        ->set('busqueda', 'gaseosa')
        ->assertSet('listadoAbierto', true)
        ->assertDontSeeHtml('role="listbox"')
        ->set('busqueda', '')
        ->assertSet('listadoAbierto', true);

    // Sin nada que buscar no se abre ni muestra la lista antigua; tampoco «no encontrado» en la lista antigua.
    Livewire::test(NuevaCompra::class)->set('busqueda', 'xyz-no-existe')
        ->assertSet('listadoAbierto', true)
        ->assertDontSeeHtml('data-test="sin-productos"')
        ->assertSeeHtml('data-test="listado-vacio"');
});

test('Enter sin una coincidencia única abre el panel con lo escrito; el escáner con código exacto agrega sin abrirlo', function () {
    Livewire::test(NuevaCompra::class)->set('sucursal_id', (string) $this->matriz->id)
        ->call('agregarDesdeBuscador', 'LIM-01')
        ->assertSet('listadoAbierto', false)
        ->assertCount('lineas', 1)
        ->call('agregarDesdeBuscador', 'lim')
        ->assertSet('listadoAbierto', true)
        ->assertSet('busqueda', 'lim')
        ->assertSee('Hay varias coincidencias')
        ->assertSee('Detergente 1 kg');
});

test('Pedidos conserva la lista desplegable de siempre al escribir', function () {
    Livewire::test(Editor::class)
        ->set('busqueda', 'gaseosa')
        ->assertSet('listadoAbierto', false)
        ->assertSeeHtml('role="listbox"')
        ->assertSee('Gaseosa 2 L');
});

test('lo escrito en el buscador filtra el panel, junto con la pestaña, y la lista desplegable no se muestra encima', function () {
    $pantalla = Livewire::test(NuevaCompra::class)->set('sucursal_id', (string) $this->matriz->id)->call('abrirListado')
        ->set('busqueda', 'lim')
        ->assertSee('Lavandina 1 L')->assertSee('Detergente 1 kg')
        ->assertDontSee('Gaseosa 2 L')
        ->assertDontSeeHtml('role="listbox"')
        ->set('listadoTab', (string) $this->bebidas->id)
        ->assertSee('Nada coincide con «lim» en Bebidas.')
        ->assertSee('Buscar en Todos')
        ->set('listadoTab', 'todos')
        ->set('busqueda', 'entrega')
        ->assertSee('Servicio de entrega no controla stock');

    // Cerrado, volver a escribir lo abre otra vez: la lista desplegable antigua ya no se usa en compras.
    $pantalla->call('cerrarListado')->set('busqueda', 'lavan')->assertSet('listadoAbierto', true)
        ->assertDontSeeHtml('role="listbox"')->assertSee('Lavandina 1 L');
});

test('muestra como máximo 18 productos y pide escribir para encontrar los demás', function () {
    foreach (range(1, 20) as $i) {
        productoDe($this->empresa, ['nombre' => sprintf('Galleta %02d', $i)]);
    }

    Livewire::test(NuevoAjuste::class)->set('sucursal_id', (string) $this->matriz->id)->call('abrirListado')
        ->set('busqueda', 'Galleta')
        ->assertSee('Galleta 18')
        ->assertDontSee('Galleta 19')
        ->assertSee('Se muestran 18 productos. Escribe en el buscador para encontrar otros.')
        ->set('busqueda', 'Galleta 2')
        ->assertSee('Galleta 20')
        ->assertDontSee('Se muestran 18 productos');
});

test('los marcados se agregan juntos: suman uno si ya están en la tabla, y el panel se cierra', function () {
    $pantalla = Livewire::test(NuevaCompra::class)->set('sucursal_id', (string) $this->matriz->id)
        ->call('agregarProducto', $this->agua->id)
        ->call('abrirListado')
        ->assertSee('en la tabla')
        ->call('agregarMarcados', [(string) $this->lavandina->id, $this->agua->id, $this->gaseosa->id, $this->gaseosa->id])
        ->assertReturned(3)
        ->assertSet('listadoAbierto', false)
        ->assertSee('3 productos agregados a la tabla.');

    $lineas = collect($pantalla->get('lineas'))->map(fn (array $l): array => [$l['id'], $l['cantidad']])->all();
    expect($lineas)->toBe([[$this->agua->id, '2'], [$this->lavandina->id, '1'], [$this->gaseosa->id, '1']]);

    // Sin marcados no hace nada.
    $pantalla->call('agregarMarcados', [])->assertReturned(0)->assertSee('Marca al menos un producto.');
    expect($pantalla->get('lineas'))->toHaveCount(3);
});

test('aislamiento: el panel no muestra ni agrega productos de otra empresa, inactivos o sin control de stock', function () {
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $categoriaB = categoriaDe($empresaB, 'Categoría de B');
    $productoB = productoDe($empresaB, ['nombre' => 'Producto de B', 'categoria_id' => $categoriaB->id]);
    $this->actingAs($this->admin);
    $inactivo = Producto::query()->where('nombre', 'Jabón descontinuado')->sole();
    $sinControl = Producto::query()->where('nombre', 'Servicio de entrega')->sole();

    $pantalla = Livewire::test(NuevaCompra::class)->set('sucursal_id', (string) $this->matriz->id)->call('abrirListado')
        ->assertDontSee('Producto de B')
        ->assertDontSee('Categoría de B')
        // Pestaña manipulada: la categoría de B no devuelve nada; un valor raro vale como «Todos».
        ->set('listadoTab', (string) $categoriaB->id)
        ->assertDontSee('Producto de B')
        ->set('listadoTab', 'cualquier-cosa')
        ->assertSee('Lavandina 1 L')
        ->call('agregarMarcados', [$productoB->id, $inactivo->id, $sinControl->id, $this->agua->id, 'x', -3])
        ->assertReturned(1)
        ->assertSee('1 producto agregado a la tabla. 3 ya no están disponibles.');

    expect(collect($pantalla->get('lineas'))->pluck('id')->all())->toBe([$this->agua->id]);

    // Más de 100 marcados de una vez se rechazan sin agregar nada.
    $pantalla->call('agregarMarcados', range(1, 101))->assertReturned(0)->assertSee('Marca hasta 100 productos por vez.');
    expect($pantalla->get('lineas'))->toHaveCount(1);
});

test('traspasos ofrecen el panel con el stock del origen', function () {
    Livewire::test(NuevoTraspaso::class)
        ->set('sucursal_id', (string) $this->matriz->id)
        ->set('sucursal_destino_id', (string) $this->norte->id)
        ->call('abrirListado')
        ->assertSet('listadoAbierto', true)
        ->assertSeeHtml('Stock bajo<small>2</small>')
        ->call('agregarMarcados', [$this->detergente->id, $this->agua->id])
        ->assertReturned(2);
});

test('Pedidos y POS no ofrecen el panel: venden de a uno', function () {
    Livewire::test(Editor::class)
        ->call('abrirListado')
        ->assertSet('listadoAbierto', false)
        ->assertDontSeeHtml('data-test="listado-masivo"')
        ->call('agregarMarcados', [$this->agua->id])
        ->assertReturned(0)
        ->assertSet('lineas', []);

    Livewire::test(Pos::class)
        ->call('abrirListado')
        ->assertSet('listadoAbierto', false)
        ->assertDontSeeHtml('data-test="listado-masivo"')
        ->call('agregarMarcados', [$this->agua->id])
        ->assertReturned(0);
});
