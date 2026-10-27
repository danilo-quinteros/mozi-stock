<?php

use App\Livewire\Ventas\Pos;
use App\Models\Sucursal;
use Livewire\Livewire;

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->producto = productoDe($this->empresa, [
        'nombre' => 'Café Yungas 500g',
        'codigo_barras' => '7779998881112',
        'precio_consumidor' => '45.00',
    ]);
    $this->actingAs($this->admin);
    cargarStock($this->admin, $this->sucursal, $this->producto, '10');
});

test('agregar un producto al carrito en POS dispara un aviso toast con el nombre del producto', function () {
    Livewire::test(Pos::class)
        ->assertSet('aviso', null)
        ->call('agregarProducto', $this->producto->id)
        ->assertSet('aviso', 'Café Yungas 500g agregado al carrito.')
        ->assertSet('avisoTipo', 'success')
        ->assertSee('Café Yungas 500g agregado al carrito.');
});

test('en POS no se muestra la seccion de emitir factura SIAT', function () {
    $this->empresa->update(['siat_habilitado' => true]);

    Livewire::test(Pos::class)
        ->assertDontSee('Emitir factura SIAT')
        ->assertDontSee('Emisión manual al confirmar la venta');
});

test('en el menu principal Ventas y finanzas no contiene Ventas POS y Pedidos y ventas se renombro a Pedidos', function () {
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Pedidos')
        ->assertDontSee('Pedidos y ventas')
        ->assertDontSee('Ventas POS');
});

test('en la pagina de pedidos junto al boton Nuevo pedido esta Venta rapida hacia pos', function () {
    $this->get(route('pedidos.index'))
        ->assertOk()
        ->assertSee('Venta rápida')
        ->assertSee(route('pos'), false)
        ->assertSee('Nuevo pedido')
        ->assertSee(route('pedidos.crear'), false);
});

test('debajo de Mi cuenta aparece Mi empresa y contiene los 3 tabs Empresa, Mi suscripcion y Facturacion', function () {
    $this->empresa->update(['siat_habilitado' => true]);

    // Ver navegación general
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Mi cuenta')
        ->assertSee('Mi empresa')
        ->assertSee(route('configuracion'), false);

    // En Mi cuenta (profile.edit) están Perfil, Seguridad, Apariencia y NO Empresa, Mi suscripción, Facturación
    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('Perfil')
        ->assertSee('Seguridad')
        ->assertSee('Apariencia')
        ->assertDontSee('Mi suscripción')
        ->assertDontSee('Facturación');

    // En Mi empresa (configuracion) están Empresa, Mi suscripción, Facturación
    $this->get(route('configuracion'))
        ->assertOk()
        ->assertSee('Empresa')
        ->assertSee('Mi suscripción')
        ->assertSee('Facturación');
});
