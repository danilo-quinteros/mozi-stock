<?php

use App\Domain\Inventario\Actions\RegistrarTraspaso;
use App\Livewire\Inventario\Movimientos;
use App\Models\Sucursal;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    $this->arroz = productoDe($this->empresa, ['nombre' => 'Arroz 5kg']);
    $this->aceite = productoDe($this->empresa, ['nombre' => 'Aceite 1L']);
    $this->actingAs($this->admin);
    cargarStock($this->admin, $this->matriz, $this->arroz, '20');
    cargarStock($this->admin, $this->matriz, $this->aceite, '5');
    app(RegistrarTraspaso::class)->ejecutar($this->admin, [
        'sucursal_id' => $this->matriz->id, 'sucursal_destino_id' => $this->norte->id, 'nota' => 'Reposición',
        'lineas' => [$this->arroz->id => '8'], 'clave' => (string) Str::uuid(),
    ]);
});

test('the history shows one row per transfer with origin and destination', function () {
    Livewire::test(Movimientos::class)
        ->assertSee('TRA-00001')->assertSee('Casa Matriz → Sucursal Norte')->assertSee('−8 / +8')
        ->assertSee('AJU-00001')->assertSee('Reposición manual');
});

test('history filters by type, branch and text', function () {
    Livewire::test(Movimientos::class)
        ->set('tipo', 'traspaso')->assertSee('TRA-00001')->assertDontSee('AJU-00002')
        ->set('tipo', '')->set('sucursal', (string) $this->norte->id)->assertSee('TRA-00001')->assertDontSee('AJU-00001')
        ->set('sucursal', '')->set('buscar', 'Aceite')->assertSee('AJU-00002')->assertDontSee('TRA-00001');
});

test('T23 the CSV export matches the screen filters', function () {
    $csv = $this->get(route('inventario.exportar', ['tipo' => 'traspaso']))->assertOk()->streamedContent();

    expect($csv)->toStartWith("\u{FEFF}Fecha;Referencia;Tipo;Producto")
        ->toContain(';TRA-00001;Traspaso;"Arroz 5kg";')
        ->toContain('"Casa Matriz";"Sucursal Norte";-8.000;20.000;12.000;')
        ->not->toContain('AJU-00001');
});

test('T03 history and its CSV never include another company', function () {
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $sucursalB = Sucursal::factory()->create(['empresa_id' => $empresaB->id]);
    $productoB = productoDe($empresaB, ['nombre' => 'Producto de B']);
    cargarStock($adminB, $sucursalB, $productoB, '3');

    $this->actingAs($this->admin);
    Livewire::test(Movimientos::class)->assertDontSee('Producto de B');
    expect($this->get(route('inventario.exportar'))->streamedContent())->not->toContain('Producto de B');

    $this->actingAs($adminB);
    Livewire::test(Movimientos::class)->assertSee('Producto de B')->assertDontSee('Arroz 5kg')->assertSee('AJU-00001');
});
