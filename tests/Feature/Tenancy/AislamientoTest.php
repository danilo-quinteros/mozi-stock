<?php

use App\Livewire\Empresa\Configuracion;
use App\Livewire\Empresa\Sucursales;
use App\Livewire\Empresa\Usuarios;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Aislamiento multiempresa (T01–T03 aplicables a esta etapa). Productos, ventas y stock
 * aún no existen: sus casos IDOR se añadirán en cada etapa.
 */

test('T01 two companies may reuse a branch code but one company may not repeat it', function () {
    [$empresaA, $adminA] = empresaConAdministrador();
    [, $adminB] = empresaConAdministrador();

    Livewire::actingAs($adminA)->test(Sucursales::class)
        ->call('crear')->set('nombre', 'Casa Matriz')->set('codigo', 'matriz')->call('guardar')
        ->assertHasNoErrors();
    Livewire::actingAs($adminB)->test(Sucursales::class)
        ->call('crear')->set('nombre', 'Casa Matriz')->set('codigo', 'MATRIZ')->call('guardar')
        ->assertHasNoErrors();
    Livewire::actingAs($adminA)->test(Sucursales::class)
        ->call('crear')->set('nombre', 'Otra')->set('codigo', 'MATRIZ')->call('guardar')
        ->assertHasErrors(['codigo' => 'unique']);

    expect(Sucursal::withoutGlobalScopes()->where('codigo', 'MATRIZ')->count())->toBe(2)
        ->and(Sucursal::withoutGlobalScopes()->where('empresa_id', $empresaA->id)->count())->toBe(1);
});

test('T02 an administrator cannot read or edit branches of another company by guessing ids', function () {
    [, $adminA] = empresaConAdministrador();
    [$empresaB] = empresaConAdministrador();
    $propia = Sucursal::factory()->create(['empresa_id' => $adminA->empresa_id, 'nombre' => 'Sucursal Propia']);
    $ajena = Sucursal::factory()->create(['empresa_id' => $empresaB->id, 'nombre' => 'Sucursal Ajena', 'codigo' => 'AJENA']);

    $this->actingAs($adminA)->get(route('sucursales.index'))->assertOk()
        ->assertSee('Sucursal Propia')->assertDontSee('Sucursal Ajena')->assertDontSee('AJENA');

    Livewire::actingAs($adminA)->test(Sucursales::class)->call('editar', $ajena->id)->assertNotFound();
    Livewire::actingAs($adminA)->test(Sucursales::class)->call('confirmarEstado', $ajena->id)->assertNotFound();

    expect($ajena->refresh()->nombre)->toBe('Sucursal Ajena')
        ->and($propia->refresh()->activa)->toBeTrue();
});

test('T02 an administrator cannot read, edit or reassign users of another company', function () {
    [, $adminA] = empresaConAdministrador();
    [$empresaB, $adminB] = empresaConAdministrador();
    $sucursalB = Sucursal::factory()->create(['empresa_id' => $empresaB->id]);
    $vendedorB = User::factory()->vendedor($sucursalB)->create(['name' => 'Vendedor Ajeno']);

    $this->actingAs($adminA)->get(route('usuarios.index'))->assertOk()
        ->assertDontSee('Vendedor Ajeno')->assertDontSee($adminB->email);

    Livewire::actingAs($adminA)->test(Usuarios::class)->call('editar', $vendedorB->id)->assertNotFound();
    Livewire::actingAs($adminA)->test(Usuarios::class)->call('editar', $adminB->id)->assertNotFound();

    // Asignar una sucursal de B a un vendedor nuevo de A se rechaza.
    Livewire::actingAs($adminA)->test(Usuarios::class)
        ->call('crear')
        ->set('name', 'Intento cruzado')->set('email', 'cruzado@example.test')
        ->set('rol', 'vendedor')->set('sucursal_id', (string) $sucursalB->id)
        ->set('password', 'ClaveSegura12345')->set('password_confirmation', 'ClaveSegura12345')
        ->call('guardar')
        ->assertHasErrors(['sucursal_id' => 'exists']);

    expect(User::query()->where('email', 'cruzado@example.test')->exists())->toBeFalse()
        ->and($vendedorB->refresh()->empresa_id)->toBe($empresaB->id);
});

test('T02 company settings always edit the authenticated company, never one sent by the browser', function () {
    [$empresaA, $adminA] = empresaConAdministrador(['nombre' => 'Empresa A']);
    [$empresaB] = empresaConAdministrador(['nombre' => 'Empresa B']);

    Livewire::actingAs($adminA)->test(Configuracion::class)
        ->set('nombre', 'Empresa A renombrada')
        ->call('guardar')
        ->assertHasNoErrors();

    expect($empresaA->refresh()->nombre)->toBe('Empresa A renombrada')
        ->and($empresaB->refresh()->nombre)->toBe('Empresa B');
});

test('T03 the users CSV export of company A never includes company B', function () {
    [$empresaA, $adminA] = empresaConAdministrador();
    [$empresaB] = empresaConAdministrador();
    $sucursalA = Sucursal::factory()->create(['empresa_id' => $empresaA->id, 'nombre' => 'Norte A']);
    User::factory()->vendedor($sucursalA)->create(['name' => 'Vendedora A', 'email' => 'a@example.test']);
    User::factory()->vendedor(Sucursal::factory()->create(['empresa_id' => $empresaB->id]))
        ->create(['name' => 'Vendedora B', 'email' => 'b@example.test']);

    $csv = $this->actingAs($adminA)->get(route('usuarios.exportar'))->assertOk()->streamedContent();

    expect($csv)->toStartWith("\u{FEFF}Nombre;Correo;Rol;Sucursal;Estado")
        ->toContain('"Vendedora A";a@example.test;Vendedor;"Norte A";Activo')
        ->toContain($adminA->email)
        ->not->toContain('Vendedora B')
        ->not->toContain('b@example.test');
});

test('T03 the CSV export applies the same search filter as the screen', function () {
    [$empresa, $admin] = empresaConAdministrador();
    // Nombres y correos fijos: la búsqueda también mira correo y sucursal, y un valor aleatorio podría contener «ana».
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id, 'nombre' => 'Centro']);
    User::factory()->vendedor($sucursal)->create(['name' => 'Ana Rojas', 'email' => 'rojas.vendedora@example.test']);
    User::factory()->vendedor($sucursal)->create(['name' => 'Luis Pérez', 'email' => 'perez.vendedor@example.test']);

    $csv = $this->actingAs($admin)->get(route('usuarios.exportar', ['buscar' => 'Ana']))->streamedContent();

    expect($csv)->toContain('Ana Rojas')->not->toContain('Luis Pérez');
});

test('T03 a seller cannot open company management screens nor export users', function () {
    [$empresa] = empresaConAdministrador();
    $vendedor = User::factory()->vendedor(Sucursal::factory()->create(['empresa_id' => $empresa->id]))->create();

    $this->actingAs($vendedor);
    $this->get(route('sucursales.index'))->assertForbidden();
    $this->get(route('usuarios.index'))->assertForbidden();
    $this->get(route('usuarios.exportar'))->assertForbidden();
    $this->get(route('configuracion'))->assertForbidden();
    $this->get(route('dashboard'))->assertOk()->assertDontSee(route('usuarios.index'));
});

test('superadmin and company panels are separated', function () {
    [, $admin] = empresaConAdministrador();
    $superadmin = User::factory()->superadmin()->create();

    $this->actingAs($superadmin)->get(route('sucursales.index'))->assertForbidden();
    $this->actingAs($superadmin)->get(route('dashboard'))->assertRedirect(route('admin.panel'));
    $this->actingAs($admin)->get(route('admin.empresas'))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.panel'))->assertForbidden();
});

test('tenant scope fails closed without a company context', function () {
    [$empresa] = empresaConAdministrador();
    Sucursal::factory()->count(2)->create(['empresa_id' => $empresa->id]);

    expect(Sucursal::query()->count())->toBe(0);

    $this->actingAs(User::factory()->superadmin()->create());
    expect(Sucursal::query()->count())->toBe(0);
});

test('tenant records cannot be created for another company nor moved between companies', function () {
    [$empresaA, $adminA] = empresaConAdministrador();
    [$empresaB] = empresaConAdministrador();
    $sucursalA = Sucursal::factory()->create(['empresa_id' => $empresaA->id]);

    $this->actingAs($adminA);

    expect(fn () => Sucursal::factory()->create(['empresa_id' => $empresaB->id]))
        ->toThrow(LogicException::class);

    $sucursalA->empresa_id = $empresaB->id;
    expect(fn () => $sucursalA->save())->toThrow(LogicException::class);
});

test('the database rejects a user pointing to a branch of another company', function () {
    $empresaA = Empresa::factory()->create();
    $sucursalB = Sucursal::factory()->create();
    $usuario = User::factory()->administrador($empresaA)->create();

    $usuario->sucursal_id = $sucursalB->id;

    expect(fn () => $usuario->save())->toThrow(QueryException::class);
});

test('the database rejects inconsistent role, company and branch combinations', function () {
    $empresa = Empresa::factory()->create();
    $insertar = fn (array $datos) => DB::table('users')->insert($datos + [
        'name' => 'X', 'email' => uniqid().'@example.test', 'password' => 'x', 'activo' => true,
    ]);

    expect(fn () => $insertar(['rol' => 'vendedor', 'empresa_id' => $empresa->id]))->toThrow(QueryException::class)
        ->and(fn () => $insertar(['rol' => 'superadmin', 'empresa_id' => $empresa->id]))->toThrow(QueryException::class)
        ->and(fn () => $insertar(['rol' => 'administrador']))->toThrow(QueryException::class)
        ->and(fn () => $insertar(['rol' => 'dueño', 'empresa_id' => $empresa->id]))->toThrow(QueryException::class);
});

test('empresa_id, role and branch cannot be mass assigned', function () {
    $user = new User;
    $user->fill(['name' => 'X', 'email' => 'x@example.test', 'password' => 'secreta', 'empresa_id' => 9, 'rol' => 'superadmin', 'sucursal_id' => 3, 'activo' => false]);

    expect($user->empresa_id)->toBeNull()
        ->and($user->rol)->toBeNull()
        ->and($user->sucursal_id)->toBeNull()
        ->and($user->activo)->toBeTrue();

    $sucursal = new Sucursal;
    $sucursal->fill(['nombre' => 'X', 'codigo' => 'X', 'empresa_id' => 9, 'activa' => false]);
    expect($sucursal->empresa_id)->toBeNull()->and($sucursal->activa)->toBeTrue();
});
