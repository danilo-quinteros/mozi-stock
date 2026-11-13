<?php

use App\Livewire\Compras\NuevaCompra;
use App\Livewire\Inventario\NuevoAjuste;
use App\Livewire\Inventario\NuevoTraspaso;
use App\Livewire\Pedidos\Editor;
use App\Livewire\Ventas\Pos;
use App\Models\Cliente;
use App\Models\Sucursal;
use Livewire\Livewire;

/*
 * Pedidos del propietario (09/11/2026): los autocompletados dicen «no encontrado» en vez de quedar en blanco, y las
 * pantallas llevan los textos del aviso rojo de validación (resources/js/app.js).
 */

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->actingAs($this->admin);
    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id]);
    $this->producto = productoDe($this->empresa, ['nombre' => 'Aceite 1L']);
    (new Cliente(['nombre' => 'Carlos Mendoza', 'documento' => '4455667']))->save();
});

test('the product search says when nothing matches', function () {
    Livewire::test(NuevoAjuste::class)
        ->set('busqueda', 'a')->assertDontSee('Producto no encontrado')   // una letra todavía no busca
        ->set('busqueda', 'zzzz')->assertSee('Producto no encontrado')
        ->set('busqueda', 'Acei')->assertDontSee('Producto no encontrado')->assertSee('Aceite 1L');
});

test('a product without stock control is explained instead of «not found» in purchases, adjustments and transfers', function () {
    // Reporte del propietario (10/11/2026): «Coca» decía «Producto no encontrado» porque la Coca-Cola no controla stock.
    productoDe($this->empresa, ['nombre' => 'Coca-Cola 2L', 'controla_stock' => false]);

    foreach ([NuevaCompra::class, NuevoAjuste::class, NuevoTraspaso::class] as $pantalla) {
        Livewire::test($pantalla)
            ->set('busqueda', 'Coca')
            ->assertDontSee('Producto no encontrado')
            ->assertSee('Coca-Cola 2L no controla stock')
            ->assertSee('marca «Controla stock» en Productos')
            ->call('agregarDesdeBuscador', 'Coca')
            ->assertSet('lineas', [])
            ->assertSet('mensajeCaptura', 'Coca-Cola 2L no controla stock. Para comprarlo, ajustarlo o traspasarlo, marca «Controla stock» en Productos.');
    }

    // Lo que no existe sigue diciendo «no encontrado»; las ventas sí lo ofrecen.
    Livewire::test(NuevoAjuste::class)->set('busqueda', 'zzzz')->assertSee('Producto no encontrado')->assertDontSee('no controla stock');
    Livewire::test(Editor::class)->set('busqueda', 'Coca')->assertSee('Coca-Cola 2L')->assertDontSee('no controla stock');
});

test('the client search says when nothing matches in orders and in the POS', function () {
    Livewire::test(Editor::class)
        ->set('buscarCliente', 'zzzz')->assertSee('Cliente no encontrado')
        ->set('buscarCliente', 'Carl')->assertDontSee('Cliente no encontrado')->assertSee('Carlos Mendoza');

    Livewire::test(Pos::class)
        ->set('buscarCliente', 'zzzz')->assertSee('Cliente no encontrado')
        ->set('buscarCliente', '4455')->assertDontSee('Cliente no encontrado')->assertSee('Carlos Mendoza');
});

test('another company\'s products and clients are never suggested', function () {
    productoDe($this->empresa, ['nombre' => 'Coca-Cola 2L', 'controla_stock' => false]);
    [$otra, $adminOtra] = empresaConAdministrador();
    $this->actingAs($adminOtra);
    Sucursal::factory()->create(['empresa_id' => $otra->id]);

    Livewire::test(NuevoAjuste::class)->set('busqueda', 'Aceite')->assertSee('Producto no encontrado')->assertDontSee('Aceite 1L');
    // Tampoco se revela por nombre el producto sin control de stock de otra empresa.
    Livewire::test(NuevoAjuste::class)->set('busqueda', 'Coca')->assertSee('Producto no encontrado')->assertDontSee('Coca-Cola 2L');
    Livewire::test(Pos::class)->set('buscarCliente', 'Carlos')->assertSee('Cliente no encontrado')->assertDontSee('Carlos Mendoza');
});

test('pages carry the texts of the red validation notice and the first error of classic forms', function () {
    $this->get(route('inventario.ajuste'))->assertOk()
        ->assertSee('name="mozistock-validacion"', false)
        ->assertSee('(y :n más por revisar)', false)
        ->assertDontSee('data-primero', false);

    // Formulario clásico (inicio de sesión de Fortify): los errores vuelven en la sesión tras la redirección.
    auth()->logout();
    $this->from(route('login'))->post(route('login'), ['email' => 'nadie@ejemplo.test', 'password' => 'incorrecta'])->assertRedirect(route('login'));
    $this->get(route('login'))->assertOk()
        ->assertSee('data-primero="El correo o la contraseña no son correctos."', false)
        ->assertSee('data-total="1"', false);
});
