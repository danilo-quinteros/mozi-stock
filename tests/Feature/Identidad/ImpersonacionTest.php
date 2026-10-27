<?php

use App\Http\Controllers\ImpersonacionController;
use App\Models\Auditoria;
use App\Models\Sucursal;
use App\Models\User;

/*
 * «Entrar como»: superadmin a cuentas de empresa y administrador a sus vendedores, con
 * auditoría, sin cruzar empresas y sin tocar el perfil del usuario suplantado.
 */

beforeEach(function () {
    config(['mozistock.deployment_mode' => 'saas']);
    [$this->empresa, $this->admin] = empresaConAdministrador(['nombre' => 'Empresa A']);
    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id]);
    $this->vendedor = User::factory()->vendedor($this->sucursal)->create(['name' => 'Vendedora A']);
});

test('an administrator enters as a seller of the company and goes back', function () {
    $this->actingAs($this->admin)->post(route('impersonar', $this->vendedor))->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($this->vendedor);
    expect(session(ImpersonacionController::SESION))->toBe($this->admin->id);
    $this->get(route('dashboard'))->assertOk()->assertSee('Estás usando la cuenta de')->assertSee('Vendedora A');
    $this->get(route('profile.edit'))->assertRedirect(route('appearance.edit'));
    $this->post(route('impersonar', $this->admin))->assertForbidden();

    $this->post(route('impersonar.salir'))->assertRedirect(route('usuarios.index'));
    $this->assertAuthenticatedAs($this->admin);
    expect(session()->has(ImpersonacionController::SESION))->toBeFalse()
        ->and(Auditoria::query()->pluck('accion')->all())->toBe(['usuario.impersonacion_iniciada', 'usuario.impersonacion_finalizada']);
});

test('an administrator cannot enter as another administrator or a user of another company', function () {
    $otroAdmin = User::factory()->administrador($this->empresa)->create();
    [$otra, $adminAjeno] = empresaConAdministrador();
    $vendedorAjeno = User::factory()->vendedor(Sucursal::factory()->create(['empresa_id' => $otra->id]))->create();

    $this->actingAs($this->admin);
    $this->post(route('impersonar', $otroAdmin))->assertForbidden();
    $this->post(route('impersonar', $adminAjeno))->assertForbidden();
    $this->post(route('impersonar', $vendedorAjeno))->assertForbidden();
    $this->actingAs($this->vendedor)->post(route('impersonar', $this->admin))->assertForbidden();
    $this->assertAuthenticatedAs($this->vendedor);
});

test('the superadmin enters as a company administrator and returns to the global panel', function () {
    $superadmin = User::factory()->superadmin()->create();

    $this->actingAs($superadmin)->get(route('admin.empresas'))->assertOk()->assertSee('Entrar');
    $this->post(route('impersonar', $this->admin))->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($this->admin);
    $this->get(route('pedidos.index'))->assertOk();

    $this->post(route('impersonar.salir'))->assertRedirect(route('admin.empresas'));
    $this->assertAuthenticatedAs($superadmin);
});

test('inactive accounts cannot be impersonated', function () {
    $inactivo = User::factory()->vendedor($this->sucursal)->inactivo()->create();

    $this->actingAs($this->admin)->post(route('impersonar', $inactivo))->assertForbidden();
});
