<?php

use App\Enums\Rol;
use App\Livewire\Admin\Empresas;
use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('the superadmin registers a company with its first administrator', function () {
    $superadmin = User::factory()->superadmin()->create();

    Livewire::actingAs($superadmin)->test(Empresas::class)
        ->call('crear')
        ->set('nombre', 'Comercial Santa Cruz')->set('nit', '1234567')->set('email', 'contacto@comercial.test')
        ->set('admin_nombre', 'Administradora')->set('admin_email', 'Admin@Comercial.test')
        ->set('admin_password', 'ClaveSegura12345')->set('admin_password_confirmation', 'ClaveSegura12345')
        ->call('guardar')
        ->assertHasNoErrors()
        ->assertSee('Comercial Santa Cruz');

    $empresa = Empresa::query()->sole();
    $admin = User::query()->where('email', 'admin@comercial.test')->sole();

    expect($empresa->activa)->toBeTrue()
        ->and($empresa->siat_habilitado)->toBeFalse()
        ->and($admin->empresa_id)->toBe($empresa->id)
        ->and($admin->rol)->toBe(Rol::Administrador)
        ->and($admin->sucursal_id)->toBeNull()
        ->and(Hash::check('ClaveSegura12345', $admin->password))->toBeTrue()
        ->and(Auditoria::query()->pluck('accion')->all())->toBe(['empresa.creada', 'sucursal.creada', 'usuario.creado']);

    $this->post(route('logout'));
    $this->post(route('login.store'), ['email' => 'admin@comercial.test', 'password' => 'ClaveSegura12345']);
    $this->assertAuthenticatedAs($admin);
});

test('company creation is atomic when the administrator email already exists', function () {
    $superadmin = User::factory()->superadmin()->create();
    User::factory()->create(['email' => 'ocupado@example.test']);

    Livewire::actingAs($superadmin)->test(Empresas::class)
        ->call('crear')
        ->set('nombre', 'Empresa duplicada')
        ->set('admin_nombre', 'Admin')->set('admin_email', 'ocupado@example.test')
        ->set('admin_password', 'ClaveSegura12345')->set('admin_password_confirmation', 'ClaveSegura12345')
        ->call('guardar')
        ->assertHasErrors(['admin_email' => 'unique']);

    expect(Empresa::query()->count())->toBe(0);
});

test('the superadmin edits company data without touching access or SIAT', function () {
    $empresa = Empresa::factory()->create(['nombre' => 'Mercado Norte']);
    $superadmin = User::factory()->superadmin()->create();

    Livewire::actingAs($superadmin)->test(Empresas::class)
        ->call('editar', $empresa->id)
        ->set('nombre', 'Mercado Norte SRL')->set('telefono', '70000000')
        ->call('guardar')
        ->assertHasNoErrors();

    $empresa->refresh();
    expect($empresa->nombre)->toBe('Mercado Norte SRL')
        ->and($empresa->telefono)->toBe('70000000')
        ->and($empresa->activa)->toBeTrue()
        ->and($empresa->siat_habilitado)->toBeFalse();
});

test('companies list supports search and access filter', function () {
    Empresa::factory()->create(['nombre' => 'Tienda Los Pinos']);
    Empresa::factory()->inactiva()->create(['nombre' => 'Distribuidora Sol']);
    $superadmin = User::factory()->superadmin()->create();

    Livewire::actingAs($superadmin)->test(Empresas::class)
        ->assertSee('Tienda Los Pinos')->assertSee('Distribuidora Sol')
        ->set('estado', 'inactiva')->assertDontSee('Tienda Los Pinos')->assertSee('Distribuidora Sol')
        ->set('estado', '')->set('buscar', 'Pinos')->assertSee('Tienda Los Pinos')->assertDontSee('Distribuidora Sol');
});

test('the superadmin panel does not show business data of companies', function () {
    [$empresa] = empresaConAdministrador(['nombre' => 'Cliente Privado']);
    $superadmin = User::factory()->superadmin()->create();

    $this->actingAs($superadmin)->get(route('admin.panel'))->assertOk()
        ->assertSee('Panel general')->assertSee('Cliente Privado')
        ->assertDontSee('Sucursales')->assertDontSee('Pedidos');
});

test('the global panel does not exist in standalone mode', function () {
    config(['mozistock.deployment_mode' => 'standalone']);
    $superadmin = User::factory()->superadmin()->create();

    $this->actingAs($superadmin)->get(route('admin.empresas'))->assertNotFound();
    $this->actingAs($superadmin)->get(route('dashboard'))->assertOk()->assertSee('Sin empresa asignada');
});

test('the superadmin command creates the account and refuses company users', function () {
    $this->artisan('mozistock:superadmin')
        ->expectsQuestion('Correo electrónico', 'Root@MoziStudios.test')
        ->expectsQuestion('Nombre', 'MoziStudios')
        ->expectsQuestion('Contraseña (mínimo 12 caracteres, letras y números)', 'ClaveSegura12345')
        ->expectsQuestion('Confirmar contraseña', 'ClaveSegura12345')
        ->assertSuccessful();

    $superadmin = User::query()->where('email', 'root@mozistudios.test')->sole();
    expect($superadmin->esSuperadmin())->toBeTrue()
        ->and($superadmin->empresa_id)->toBeNull()
        ->and($superadmin->hasVerifiedEmail())->toBeTrue();

    [, $admin] = empresaConAdministrador();
    $this->artisan('mozistock:superadmin')
        ->expectsQuestion('Correo electrónico', $admin->email)
        ->assertFailed();
    expect($admin->refresh()->rol)->toBe(Rol::Administrador);
});

test('the superadmin command promotes an existing account without company', function () {
    $cuenta = User::factory()->create(['email' => 'local@example.test']);

    $this->artisan('mozistock:superadmin')
        ->expectsQuestion('Correo electrónico', 'local@example.test')
        ->expectsConfirmation("¿Conceder el rol de superadministrador a {$cuenta->name}?", 'yes')
        ->assertSuccessful();

    expect($cuenta->refresh()->rol)->toBe(Rol::Superadmin)
        ->and(Auditoria::query()->where('accion', 'usuario.rol_superadmin')->count())->toBe(1);
});
