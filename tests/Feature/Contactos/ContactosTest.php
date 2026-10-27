<?php

use App\Domain\Compras\Actions\GuardarProveedor;
use App\Livewire\Contactos\ClienteFicha;
use App\Livewire\Contactos\Clientes;
use App\Livewire\Contactos\Proveedores;
use App\Livewire\Pedidos\Editor;
use App\Models\Cliente;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Clientes y proveedores (§ 3.10). Decisión del propietario (23/10/2026): clientes para todos
 * los usuarios de la empresa (activar/desactivar solo administrador); proveedores solo
 * administradores. Datos privados de cada empresa (T01–T03).
 */

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    $this->producto = productoDe($this->empresa, ['precio_consumidor' => '20.00']);
    $this->vendedor = User::factory()->vendedor($this->matriz)->create();
    $this->actingAs($this->admin);
    cargarStock($this->admin, $this->matriz, $this->producto, '20');
    cargarStock($this->admin, $this->norte, $this->producto, '20');
});

test('anyone in the company registers and edits clients; the document is unique per company only', function () {
    $this->actingAs($this->vendedor);
    Livewire::test(Clientes::class)->call('nuevoCliente')
        ->set('clienteNombre', 'María Rodríguez')->set('clienteDocumento', '7788990')
        ->set('clienteTelefono', '712 33445')->set('clienteCorreo', 'Maria@Email.test')
        ->call('crearCliente')->assertHasNoErrors()->assertSee('María Rodríguez')->assertSee('maria@email.test');

    Livewire::test(Clientes::class)->call('nuevoCliente')
        ->set('clienteNombre', 'Otra María')->set('clienteDocumento', '7788990')
        ->call('crearCliente')->assertHasErrors('clienteDocumento');
    Livewire::test(Clientes::class)->call('nuevoCliente')
        ->set('clienteNombre', 'Sin correo válido')->set('clienteCorreo', 'no-es-correo')
        ->call('crearCliente')->assertHasErrors('clienteCorreo');

    $cliente = Cliente::query()->sole();
    Livewire::test(ClienteFicha::class, ['cliente' => $cliente])->call('editar')
        ->set('clienteTelefono', '700 00000')->call('guardar')->assertHasNoErrors();
    expect($cliente->refresh()->telefono)->toBe('700 00000');

    // El vendedor no activa ni desactiva clientes.
    Livewire::test(ClienteFicha::class, ['cliente' => $cliente])->assertDontSee('Desactivar')
        ->call('cambiarEstado')->assertForbidden();

    // T01: otra empresa puede registrar el mismo CI/NIT.
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    Livewire::test(Clientes::class)->call('nuevoCliente')
        ->set('clienteNombre', 'María de B')->set('clienteDocumento', '7788990')
        ->call('crearCliente')->assertHasNoErrors();
});

test('a deactivated client keeps its history and disappears from new orders', function () {
    $cliente = new Cliente(['nombre' => 'Comercial López', 'documento' => '1020304']);
    $cliente->save();

    Livewire::test(ClienteFicha::class, ['cliente' => $cliente])->call('cambiarEstado')->assertSee('Inactivo');

    expect($cliente->refresh()->activo)->toBeFalse();
    Livewire::test(Editor::class)->set('buscarCliente', 'López')->assertDontSee('Comercial López');
});

test('the client file shows sales and credit, and a seller only sees their own branch', function () {
    $cliente = new Cliente(['nombre' => 'Carlos Mendoza', 'documento' => '4455667']);
    $cliente->save();
    $matriz = confirmarVenta($this->admin, crearPedido($this->admin, $this->matriz, [['producto_id' => $this->producto->id, 'cantidad' => '10']],
        ['cliente_id' => $cliente->id]), ['metodo' => 'efectivo', 'en_cuotas' => true, 'cuota_inicial' => '50']);
    $norte = confirmarVenta($this->admin, crearPedido($this->admin, $this->norte, [['producto_id' => $this->producto->id, 'cantidad' => '1']],
        ['cliente_id' => $cliente->id]), ['metodo' => 'efectivo']);

    Livewire::test(ClienteFicha::class, ['cliente' => $cliente])
        ->assertSee($matriz->codigo())->assertSee($norte->codigo())
        ->assertSee('Bs 220,00')   // valor vendido
        ->assertSee('Bs 70,00')    // cobrado: 50 + 20
        ->assertSee('Bs 150,00');  // saldo
    Livewire::test(Clientes::class)->assertSee('2 ventas');

    $this->actingAs($this->vendedor);
    Livewire::test(ClienteFicha::class, ['cliente' => $cliente])
        ->assertSee($matriz->codigo())->assertDontSee($norte->codigo())->assertSee('Bs 200,00');
    Livewire::test(Clientes::class)->assertSee('1 venta');
    expect($this->get(route('clientes.exportar'))->streamedContent())->toContain('"Carlos Mendoza";4455667;;;1;Activo');
});

test('T02 and T03 clients of another company are never visible', function () {
    $cliente = new Cliente(['nombre' => 'Cliente Secreto A']);
    $cliente->save();

    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);

    $this->get(route('clientes.ver', $cliente))->assertNotFound();
    Livewire::test(Clientes::class)->assertDontSee('Cliente Secreto A');
    expect($this->get(route('clientes.exportar'))->streamedContent())->not->toContain('Cliente Secreto A');
});

test('suppliers are admin only, unique per company and isolated', function () {
    $accion = app(GuardarProveedor::class);

    Livewire::test(Proveedores::class)->call('nuevoProveedor')
        ->set('nombre', 'Distribuidora Oriente')->set('documento', '1029384756')->set('ciudad', 'Santa Cruz')
        ->call('crearProveedor')->assertHasNoErrors()->assertSee('Distribuidora Oriente')->assertSee('Santa Cruz');

    expect(fn () => $accion->crear($this->admin, ['nombre' => 'Copia', 'documento' => '1029384756']))->toThrow(ValidationException::class);

    $proveedor = Proveedor::query()->sole();
    $this->get(route('proveedores.ver', $proveedor))->assertOk()->assertSee('Distribuidora Oriente');
    expect($this->get(route('proveedores.exportar'))->streamedContent())->toContain('"Distribuidora Oriente";1029384756;"Santa Cruz"');

    $this->actingAs($this->vendedor);
    expect(fn () => $accion->crear($this->vendedor, ['nombre' => 'No permitido']))->toThrow(AuthorizationException::class);
    $this->get(route('proveedores.ver', $proveedor))->assertForbidden();

    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $this->get(route('proveedores.ver', $proveedor))->assertNotFound();
    Livewire::test(Proveedores::class)->assertDontSee('Distribuidora Oriente');
    expect($accion->crear($adminB, ['nombre' => 'Distribuidora Oriente', 'documento' => '1029384756'])->empresa_id)->toBe($empresaB->id);
});
