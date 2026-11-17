<?php

use App\Domain\Compras\Actions\RegistrarCompra;
use App\Livewire\Compras\NuevaCompra;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Nueva compra: al agregar un producto, la línea empieza con el costo unitario de su última compra en la empresa
 * (cualquier proveedor). Es solo una sugerencia editable; un producto que nunca se compró queda en blanco.
 */

function ucProveedor(Empresa $empresa, string $nombre = 'Distribuidora Oriente'): Proveedor
{
    $proveedor = new Proveedor(['nombre' => $nombre]);
    $proveedor->empresa_id = $empresa->id;
    $proveedor->save();

    return $proveedor;
}

/** @param array<int, array{cantidad: string, costo: string}> $lineas */
function ucComprar(User $admin, Sucursal $sucursal, Proveedor $proveedor, array $lineas): Compra
{
    return app(RegistrarCompra::class)->ejecutar($admin, [
        'sucursal_id' => $sucursal->id, 'proveedor_id' => $proveedor->id, 'referencia' => null, 'lineas' => $lineas,
        'metodo' => 'efectivo', 'efectivo' => null, 'electronico' => null, 'externo' => true, 'clave' => (string) Str::uuid(),
    ]);
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->proveedor = ucProveedor($this->empresa);
    $this->otroProveedor = ucProveedor($this->empresa, 'Mayorista Sur');
    $this->arroz = productoDe($this->empresa, ['nombre' => 'Arroz 5kg']);
    $this->aceite = productoDe($this->empresa, ['nombre' => 'Aceite 1L']);
    $this->azucar = productoDe($this->empresa, ['nombre' => 'Azúcar 1kg']);
    $this->actingAs($this->admin);
});

test('a product bought before starts with the unit cost of its last purchase, from any supplier, and stays editable', function () {
    ucComprar($this->admin, $this->sucursal, $this->proveedor, [$this->arroz->id => ['cantidad' => '10', 'costo' => '28.50']]);
    ucComprar($this->admin, $this->sucursal, $this->otroProveedor, [
        $this->arroz->id => ['cantidad' => '5', 'costo' => '29.75'],
        $this->aceite->id => ['cantidad' => '3', 'costo' => '10.05'],
    ]);

    $pantalla = Livewire::test(NuevaCompra::class)
        ->set('sucursal_id', (string) $this->sucursal->id)
        ->set('proveedor_id', (string) $this->proveedor->id)
        ->call('agregarProducto', $this->arroz->id)
        ->call('agregarProducto', $this->azucar->id)
        ->assertSet('lineas.0.costo', '29.75')   // la última compra, aunque fue de otro proveedor
        ->assertSet('lineas.1.costo', '')        // nunca se compró
        ->call('agregarProducto', $this->arroz->id)
        ->assertSet('lineas.0.cantidad', '2')
        ->assertSet('lineas.0.costo', '29.75')
        // Se cambia en la línea y se guarda lo escrito.
        ->set('lineas.0.costo', '31,20')
        ->set('lineas.1.costo', '8');

    $pantalla->call('revisar')->assertSet('confirmando', true)->call('confirmar')->assertHasNoErrors();

    $compra = Compra::query()->latest('id')->firstOrFail();
    expect(CompraItem::query()->where('compra_id', $compra->id)->orderBy('orden')->pluck('costo_unitario')->all())
        ->toBe(['31.20', '8.00']);

    // La siguiente compra ya sugiere el costo nuevo.
    Livewire::test(NuevaCompra::class)
        ->set('sucursal_id', (string) $this->sucursal->id)
        ->call('agregarProducto', $this->arroz->id)
        ->call('agregarProducto', $this->azucar->id)
        ->call('agregarProducto', $this->aceite->id)
        ->assertSet('lineas.0.costo', '31.20')
        ->assertSet('lineas.1.costo', '8.00')
        ->assertSet('lineas.2.costo', '10.05');
});

test('marking several products in the panel prefills every cost with two queries in total', function () {
    ucComprar($this->admin, $this->sucursal, $this->proveedor, [
        $this->arroz->id => ['cantidad' => '1', 'costo' => '28.50'],
        $this->aceite->id => ['cantidad' => '1', 'costo' => '10.75'],
    ]);

    $pantalla = Livewire::test(NuevaCompra::class)->set('sucursal_id', (string) $this->sucursal->id)->call('abrirListado');

    $consultas = 0;
    DB::listen(function ($query) use (&$consultas): void {
        if (str_contains($query->sql, 'compra_items')) {
            $consultas++;
        }
    });

    $pantalla->call('agregarMarcados', [$this->arroz->id, $this->aceite->id, $this->azucar->id]);

    expect($consultas)->toBe(2);
    $costos = collect($pantalla->get('lineas'))->pluck('costo', 'id')->all();
    expect($costos)->toBe([$this->arroz->id => '28.50', $this->aceite->id => '10.75', $this->azucar->id => '']);
});

test('the cost of another company is never suggested', function () {
    [$otra, $adminOtra] = empresaConAdministrador();
    $this->actingAs($adminOtra);
    $sucursalOtra = Sucursal::factory()->create(['empresa_id' => $otra->id]);
    $productoOtra = productoDe($otra, ['nombre' => 'Arroz 5kg']);
    $compraOtra = ucComprar($adminOtra, $sucursalOtra, ucProveedor($otra), [$productoOtra->id => ['cantidad' => '1', 'costo' => '99.00']]);
    $this->actingAs($this->admin);

    // Su producto no se puede agregar aquí, ni cambiando el id.
    Livewire::test(NuevaCompra::class)
        ->set('sucursal_id', (string) $this->sucursal->id)
        ->call('agregarProducto', $productoOtra->id)
        ->assertCount('lineas', 0)
        ->assertDontSee('99.00');

    // Una línea de compra ajena no puede apuntar a un producto de esta empresa (clave foránea empresa + producto), y
    // la consulta del último costo además filtra por empresa.
    expect(fn () => DB::table('compra_items')->where('compra_id', $compraOtra->id)->update(['producto_id' => $this->arroz->id]))
        ->toThrow(QueryException::class);

    Livewire::test(NuevaCompra::class)
        ->set('sucursal_id', (string) $this->sucursal->id)
        ->call('agregarProducto', $this->arroz->id)
        ->assertSet('lineas.0.costo', '');
});
