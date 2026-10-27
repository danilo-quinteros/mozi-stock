<?php

use App\Livewire\Compras\Lista as ComprasLista;
use App\Livewire\Pedidos\Lista as PedidosLista;
use App\Models\Sucursal;
use App\Models\User;
use Livewire\Livewire;

/*
 * Sucursal de trabajo del administrador (menú lateral): filtra lo que ve en todas las
 * pantallas, pero no amplía permisos ni puede apuntar a otra empresa.
 */

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    $this->producto = productoDe($this->empresa, ['nombre' => 'Leche 1L', 'precio_consumidor' => '20.00']);
    $this->actingAs($this->admin);
    $this->pedidoMatriz = crearPedido($this->admin, $this->matriz, [['producto_id' => $this->producto->id, 'cantidad' => '1']]);
    $this->pedidoNorte = crearPedido($this->admin, $this->norte, [['producto_id' => $this->producto->id, 'cantidad' => '2']]);
});

test('the administrator fixes a branch and the lists only show that branch', function () {
    Livewire::test(PedidosLista::class)->assertSee($this->pedidoMatriz->codigo())->assertSee($this->pedidoNorte->codigo());

    $this->from(route('pedidos.index', ['sucursal' => $this->matriz->id]))
        ->post(route('sucursal-activa'), ['sucursal_id' => $this->norte->id])
        ->assertRedirect(route('pedidos.index'));

    expect($this->admin->sucursalActivaId())->toBe($this->norte->id);
    Livewire::test(PedidosLista::class)->assertSee($this->pedidoNorte->codigo())->assertDontSee($this->pedidoMatriz->codigo());
    Livewire::test(ComprasLista::class)->assertSet('sucursal', (string) $this->norte->id)->assertSet('sucursalFija', true);
    $this->get(route('dashboard'))->assertOk()->assertSee('Sucursal Norte');

    $this->post(route('sucursal-activa'), ['sucursal_id' => ''])->assertRedirect();
    expect($this->admin->sucursalActivaId())->toBeNull();
});

test('a branch of another company or an inactive branch cannot be fixed', function () {
    auth()->logout();
    [$otra] = empresaConAdministrador();
    $ajena = Sucursal::factory()->create(['empresa_id' => $otra->id]);
    $inactiva = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'activa' => false]);
    $this->actingAs($this->admin);

    $this->post(route('sucursal-activa'), ['sucursal_id' => $ajena->id])->assertSessionHasErrors('sucursal_id');
    $this->post(route('sucursal-activa'), ['sucursal_id' => $inactiva->id])->assertSessionHasErrors('sucursal_id');

    // Aunque la sesión se manipule, una sucursal ajena se ignora.
    $this->withSession([User::SESION_SUCURSAL => $ajena->id]);
    expect($this->admin->sucursalActivaId())->toBeNull();
});

test('a seller cannot change the branch assigned by the administrator', function () {
    $vendedor = User::factory()->vendedor($this->matriz)->create();

    $this->actingAs($vendedor)->post(route('sucursal-activa'), ['sucursal_id' => $this->norte->id])->assertForbidden();
    expect($vendedor->sucursalActivaId())->toBe($this->matriz->id);
});
