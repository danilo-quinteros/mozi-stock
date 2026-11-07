<?php

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

test('public registration is closed', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Intruso', 'email' => 'intruso@example.test',
        'password' => 'Password123456', 'empresa_id' => 1, 'rol' => 'superadmin',
    ])->assertNotFound();
    $this->assertDatabaseCount('users', 0);
});

test('login is spanish and does not contain prototype credentials or scripts', function () {
    $this->get(route('login'))->assertOk()
        ->assertSee('Correo electrónico')
        ->assertSee('Iniciar sesión')
        ->assertDontSee('admin@demo.com')
        ->assertDontSee('assets/app.js')
        ->assertDontSee('demoRole')
        ->assertDontSee('register');
});

test('authenticated home contains only the current account and its modules', function () {
    $empresa = Empresa::factory()->create(['nombre' => 'Empresa A']);
    $user = User::factory()->administrador($empresa)->create(['name' => 'Cuenta A']);
    User::factory()->administrador(Empresa::factory()->create(['nombre' => 'Empresa B']))->create(['name' => 'Cuenta B']);
    $this->actingAs($user)->get(route('dashboard'))->assertOk()
        ->assertSee('Cuenta A')->assertSee('Empresa A')
        ->assertDontSee('Cuenta B')->assertDontSee('Empresa B')
        ->assertSee('Pedidos')->assertSee('Cuentas por cobrar')->assertSee('Cuentas por pagar')
        ->assertSee('Ventas y finanzas')->assertSee('Ajustes y traspasos')->assertSee('Reportes generales')
        ->assertSee('Todas las sucursales')->assertDontSee('Análisis y cuenta')->assertDontSee('· Pendiente')
        ->assertDontSee('Bs 500')->assertDontSee('Carlos Mendoza')
        ->assertDontSee('assets/role.js');
});

test('error pages are in spanish', function () {
    $this->get('/no-existe')->assertNotFound()->assertSee('No encontrado')->assertDontSee('Not Found');
    $this->actingAs(User::factory()->create())->get(route('sucursales.index'))
        ->assertForbidden()->assertSee('Sin permiso')->assertDontSee('This action is unauthorized');
});

test('account without company only sees its personal area', function () {
    $this->actingAs(User::factory()->create(['name' => 'Sin empresa']))
        ->get(route('dashboard'))->assertOk()
        ->assertSee('Sin empresa asignada')
        ->assertDontSee('Pedidos')
        ->assertDontSee('Sucursales');
});

test('login throttles repeated invalid credentials', function () {
    $email = 'bloqueo@example.test';
    RateLimiter::clear($email.'|127.0.0.1');
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->post(route('login.store'), ['email' => $email, 'password' => 'incorrecta'])
            ->assertSessionHasErrors('email');
    }
    $this->post(route('login.store'), ['email' => $email, 'password' => 'incorrecta'])
        ->assertTooManyRequests();
    $this->assertGuest();
});

test('logout invalidates access to the dashboard', function () {
    $this->actingAs(User::factory()->create())->post(route('logout'))->assertRedirect();
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('unverified account cannot access dashboard', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('dashboard'))->assertRedirect(route('verification.notice'));
});

test('seed does not create default accounts', function () {
    $this->seed();
    $this->assertDatabaseCount('users', 0);
});

test('local provisioning is forbidden outside local environment', function () {
    $this->artisan('mozistock:usuario-local')->assertFailed();
    $this->assertDatabaseCount('users', 0);
});

test('local provisioning hashes a manually supplied password', function () {
    app()->detectEnvironment(fn () => 'local');
    $this->artisan('mozistock:usuario-local')
        ->expectsQuestion('Nombre', 'Cuenta local')
        ->expectsQuestion('Correo electrónico', 'LOCAL@example.test')
        ->expectsQuestion('Contraseña (mínimo 12 caracteres, letras y números)', 'PruebaLocal123456')
        ->expectsQuestion('Confirmar contraseña', 'PruebaLocal123456')
        ->assertSuccessful();
    $user = User::query()->sole();
    expect($user->email)->toBe('local@example.test')
        ->and(Hash::check('PruebaLocal123456', $user->password))->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue();
});
