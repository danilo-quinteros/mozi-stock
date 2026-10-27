<?php

use App\Domain\Tenancy\Actions\CambiarAccesoEmpresa;
use App\Livewire\Admin\Empresas;
use App\Models\Auditoria;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/*
 * T04: solo el superadministrador desactiva o reactiva una empresa. La desactivación
 * corta el acceso incluso con sesión abierta y no borra datos.
 */

test('T04 deactivating a company logs out its open sessions and blocks new logins', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $superadmin = User::factory()->superadmin()->create();

    Livewire::actingAs($superadmin)->test(Empresas::class)
        ->call('confirmarAcceso', $empresa->id)
        ->call('cambiarAcceso');

    expect($empresa->refresh()->activa)->toBeFalse();

    // Sesión ya abierta antes de la desactivación.
    $this->actingAs($admin)->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('bloqueo');
    $this->assertGuest();

    // Nuevo inicio de sesión con credenciales correctas.
    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'Tu empresa está desactivada. Comunícate con MoziStudios para reactivar el acceso.']);
    $this->assertGuest();
});

test('T04 Livewire requests of a deactivated company are rejected too', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $empresa->forceFill(['activa' => false])->save();

    $this->actingAs($admin)
        ->withHeader('X-Livewire', 'true')
        ->get(route('sucursales.index'))
        ->assertForbidden();
    $this->assertGuest();
});

test('T04 reactivating restores access and no data was deleted', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $vendedor = User::factory()->vendedor($sucursal)->create();
    $superadmin = User::factory()->superadmin()->create();

    $componente = Livewire::actingAs($superadmin)->test(Empresas::class);
    $componente->call('confirmarAcceso', $empresa->id)->call('cambiarAcceso');
    $componente->call('confirmarAcceso', $empresa->id)->call('cambiarAcceso');

    expect($empresa->refresh()->activa)->toBeTrue()
        ->and(Sucursal::withoutGlobalScopes()->whereKey($sucursal->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey([$admin->id, $vendedor->id])->count())->toBe(2);

    $this->post(route('logout'));
    $this->post(route('login.store'), ['email' => $vendedor->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($vendedor);

    expect(Auditoria::query()->where('entidad', 'Empresa')->where('entidad_id', $empresa->id)
        ->pluck('accion')->all())->toBe(['empresa.desactivada', 'empresa.activada']);
    expect(Auditoria::query()->where('accion', 'empresa.desactivada')->sole()->usuario_id)->toBe($superadmin->id);
});

test('T04 only the superadmin can change company access', function () {
    [$empresa, $admin] = empresaConAdministrador();

    Livewire::actingAs($admin)->test(Empresas::class)->assertForbidden();

    expect(fn () => app(CambiarAccesoEmpresa::class)->ejecutar($admin, $empresa, false))
        ->toThrow(AuthorizationException::class);
    expect($empresa->refresh()->activa)->toBeTrue();
});

test('a deactivated user loses an open session and cannot log in', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $vendedor = User::factory()->vendedor(Sucursal::factory()->create(['empresa_id' => $empresa->id]))->create();

    $this->actingAs($vendedor)->get(route('dashboard'))->assertOk();
    $vendedor->forceFill(['activo' => false])->save();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();

    $this->post(route('login.store'), ['email' => $vendedor->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'Tu usuario está desactivado. Comunícate con el administrador de tu empresa.']);
    $this->assertGuest();
});

test('wrong credentials of a deactivated account do not reveal its state', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $empresa->forceFill(['activa' => false])->save();

    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'incorrecta'])
        ->assertSessionHasErrors('email')
        ->assertSessionDoesntHaveErrors(['email' => 'Tu empresa está desactivada. Comunícate con MoziStudios para reactivar el acceso.']);
});
