<?php

use App\Domain\Inventario\Consultas\HistorialMovimientos;
use App\Livewire\Compras\NuevaCompra;
use App\Livewire\Inventario\Movimientos;
use App\Livewire\Inventario\NuevoAjuste;
use App\Livewire\Ventas\Pos;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * El nombre de un producto con contenido se muestra completo en todas partes, como en Productos: «Desengrasante de
 * cocina 650 ml» y «Desengrasante de cocina 1 L», no dos «Desengrasante de cocina» iguales.
 */

function unidadConAbreviatura(Empresa $empresa, string $nombre, string $abreviatura): UnidadMedida
{
    $unidad = new UnidadMedida(['nombre' => $nombre, 'abreviatura' => $abreviatura, 'admite_decimales' => true, 'unidad_medida_sin' => 57]);
    $unidad->empresa_id = $empresa->id;
    $unidad->save();

    return $unidad;
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->actingAs($this->admin);
    $ml = unidadConAbreviatura($this->empresa, 'Mililitro', 'ml');
    $litro = unidadConAbreviatura($this->empresa, 'Litro', 'L');
    $this->chico = Producto::factory()->enUnidad($ml)->create(['nombre' => 'Desengrasante de cocina', 'contenido' => '650', 'sku' => 'DES-650']);
    $this->grande = Producto::factory()->enUnidad($litro)->create(['nombre' => 'Desengrasante de cocina', 'contenido' => '1', 'sku' => 'DES-1L']);
    $this->medio = Producto::factory()->enUnidad($litro)->create(['nombre' => 'Desengrasante de cocina', 'contenido' => '1.5', 'sku' => 'DES-15']);
    cargarStock($this->admin, $this->matriz, $this->chico, '5');
});

test('el nombre en SQL es el mismo que el del modelo: sin ceros sobrantes, con la unidad y sin contenido queda el nombre', function () {
    $sinContenido = productoDe($this->empresa, ['nombre' => 'Esponja doble uso', 'contenido' => null]);
    $nombres = DB::table('productos as p')->leftJoin('unidades_medida as um', 'um.id', '=', 'p.unidad_medida_id')
        ->whereIn('p.id', [$this->chico->id, $this->grande->id, $this->medio->id, $sinContenido->id])
        ->orderBy('p.id')->selectRaw(Producto::sqlNombreCompleto().' as nombre')->pluck('nombre')->all();

    expect($nombres)->toBe(['Desengrasante de cocina 650 ml', 'Desengrasante de cocina 1 L', 'Desengrasante de cocina 1.5 L', 'Esponja doble uso'])
        ->and(Producto::query()->whereKey($this->medio->id)->sole()->nombre)->toBe('Desengrasante de cocina 1.5 L');
});

test('el buscador y el panel «Agregar productos» muestran cada presentación con su medida', function () {
    Livewire::test(NuevaCompra::class)->set('sucursal_id', (string) $this->matriz->id)
        ->set('busqueda', 'desengrasante de cocina')
        ->assertSee('Desengrasante de cocina 650 ml')
        ->assertSee('Desengrasante de cocina 1 L')
        ->assertSee('Desengrasante de cocina 1.5 L')
        ->call('abrirListado')
        ->assertSeeHtml('title="Desengrasante de cocina 650 ml"')
        ->assertSeeHtml('title="Desengrasante de cocina 1 L"')
        ->assertSeeHtml('title="Desengrasante de cocina 1.5 L"');
});

test('el aviso de «no controla stock» nombra el producto con su medida', function () {
    Producto::factory()->enUnidad($this->chico->unidad)->create(['nombre' => 'Limpiavidrios', 'contenido' => '500', 'controla_stock' => false]);

    Livewire::test(NuevoAjuste::class)->set('busqueda', 'limpiavidrios')
        ->assertSee('Limpiavidrios 500 ml no controla stock');
});

test('el historial de ajustes y traspasos muestra la medida y la encuentra al buscar', function () {
    $fila = HistorialMovimientos::consulta($this->empresa->id, ['buscar' => 'cocina 650'])->get();

    expect($fila)->toHaveCount(1)
        ->and($fila->first()->producto)->toBe('Desengrasante de cocina 650 ml');

    Livewire::test(Movimientos::class)->assertSee('Desengrasante de cocina 650 ml');
});

test('el POS avisa con el nombre completo', function () {
    Livewire::test(Pos::class)
        ->call('agregarProducto', $this->chico->id)
        ->assertSee('Desengrasante de cocina 650 ml agregado al carrito.');
});
