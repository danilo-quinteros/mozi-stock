<?php

use App\Enums\Rol;
use App\Livewire\Empresa\Usuarios;
use App\Models\Auditoria;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('an administrator creates a seller fixed to an active branch', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);

    Livewire::actingAs($admin)->test(Usuarios::class)
        ->call('crear')
        ->set('name', 'Juan Pérez')->set('email', 'JUAN@example.test')
        ->set('rol', 'vendedor')->set('sucursal_id', (string) $sucursal->id)
        ->set('password', 'ClaveSegura12345')->set('password_confirmation', 'ClaveSegura12345')
        ->call('guardar')
        ->assertHasNoErrors();

    $vendedor = User::query()->where('email', 'juan@example.test')->sole();
    expect($vendedor->empresa_id)->toBe($empresa->id)
        ->and($vendedor->rol)->toBe(Rol::Vendedor)
        ->and($vendedor->sucursal_id)->toBe($sucursal->id)
        ->and($vendedor->activo)->toBeTrue()
        ->and($vendedor->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('ClaveSegura12345', $vendedor->password))->toBeTrue();

    $registro = Auditoria::query()->where('accion', 'usuario.creado')->sole();
    expect($registro->usuario_id)->toBe($admin->id)
        ->and($registro->empresa_id)->toBe($empresa->id)
        ->and(json_encode($registro->despues))->not->toContain('ClaveSegura12345');
});

test('a seller requires a branch and an administrator never keeps one', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);

    Livewire::actingAs($admin)->test(Usuarios::class)
        ->call('crear')
        ->set('name', 'Sin sucursal')->set('email', 'sin@example.test')->set('rol', 'vendedor')
        ->set('password', 'ClaveSegura12345')->set('password_confirmation', 'ClaveSegura12345')
        ->call('guardar')
        ->assertHasErrors(['sucursal_id' => 'required']);

    Livewire::actingAs($admin)->test(Usuarios::class)
        ->call('crear')
        ->set('name', 'Admin dos')->set('email', 'admin2@example.test')
        ->set('rol', 'administrador')->set('sucursal_id', (string) $sucursal->id)
        ->set('password', 'ClaveSegura12345')->set('password_confirmation', 'ClaveSegura12345')
        ->call('guardar')
        ->assertHasNoErrors();

    expect(User::query()->where('email', 'admin2@example.test')->sole()->sucursal_id)->toBeNull();
});

test('a manipulated request cannot grant the superadmin role', function () {
    [, $admin] = empresaConAdministrador();

    Livewire::actingAs($admin)->test(Usuarios::class)
        ->call('crear')
        ->set('name', 'Intruso')->set('email', 'intruso@example.test')->set('rol', 'superadmin')
        ->set('password', 'ClaveSegura12345')->set('password_confirmation', 'ClaveSegura12345')
        ->call('guardar')
        ->assertHasErrors('rol');

    expect(User::query()->where('rol', Rol::Superadmin)->exists())->toBeFalse();
});

test('weak or unconfirmed passwords are rejected', function () {
    [, $admin] = empresaConAdministrador();

    Livewire::actingAs($admin)->test(Usuarios::class)
        ->call('crear')
        ->set('name', 'Débil')->set('email', 'debil@example.test')->set('rol', 'administrador')
        ->set('password', 'corta')->set('password_confirmation', 'otra')
        ->call('guardar')
        ->assertHasErrors('password');
});

test('an administrator can neither demote nor deactivate the own account', function () {
    [, $admin] = empresaConAdministrador();

    Livewire::actingAs($admin)->test(Usuarios::class)
        ->call('editar', $admin->id)
        ->set('activo', false)
        ->call('guardar')
        ->assertHasErrors('rol');

    Livewire::actingAs($admin)->test(Usuarios::class)
        ->call('editar', $admin->id)
        ->set('rol', 'vendedor')
        ->call('guardar')
        ->assertHasErrors();

    expect($admin->refresh()->rol)->toBe(Rol::Administrador)->and($admin->activo)->toBeTrue();
});

test('role, branch and status changes are audited without passwords', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $norte = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $sur = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $vendedor = User::factory()->vendedor($norte)->create();

    Livewire::actingAs($admin)->test(Usuarios::class)
        ->call('editar', $vendedor->id)
        ->set('sucursal_id', (string) $sur->id)
        ->set('activo', false)
        ->set('password', 'NuevaClave123456')->set('password_confirmation', 'NuevaClave123456')
        ->call('guardar')
        ->assertHasNoErrors();

    $vendedor->refresh();
    $registro = Auditoria::query()->where('accion', 'usuario.actualizado')->sole();

    expect($vendedor->sucursal_id)->toBe($sur->id)
        ->and($vendedor->activo)->toBeFalse()
        ->and(Hash::check('NuevaClave123456', $vendedor->password))->toBeTrue()
        ->and($registro->antes)->toMatchArray(['sucursal_id' => $norte->id, 'activo' => true])
        ->and($registro->despues)->toMatchArray(['sucursal_id' => $sur->id, 'activo' => false, 'password_restablecida' => true])
        ->and(json_encode([$registro->antes, $registro->despues]))->not->toContain('NuevaClave123456');
});

test('the users screen lists only the own company with search', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id, 'nombre' => 'Casa Matriz']);
    User::factory()->vendedor($sucursal)->create(['name' => 'Andrea Salazar']);
    User::factory()->vendedor($sucursal)->create(['name' => 'Luis Rojas']);

    Livewire::actingAs($admin)->test(Usuarios::class)
        ->assertSee('Andrea Salazar')->assertSee('Luis Rojas')->assertSee('Casa Matriz')
        ->set('buscar', 'Andrea')
        ->assertSee('Andrea Salazar')->assertDontSee('Luis Rojas');
});
