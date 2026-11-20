<?php

use App\Domain\Tenancy\Actions\GuardarSucursal;
use App\Domain\Tenancy\ResolverSucursal;
use App\Livewire\Empresa\Sucursales;
use App\Models\Auditoria;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('an administrator registers and edits branches with audit trail', function () {
    [$empresa, $admin] = empresaConAdministrador();

    Livewire::actingAs($admin)->test(Sucursales::class)
        ->call('crear')
        ->set('nombre', '  Sucursal Norte ')->set('codigo', 'norte-1')->set('direccion', 'Zona Norte')
        ->call('guardar')
        ->assertHasNoErrors()
        ->assertSet('formularioAbierto', false);

    $sucursal = Sucursal::withoutGlobalScopes()->sole();
    expect($sucursal->empresa_id)->toBe($empresa->id)
        ->and($sucursal->nombre)->toBe('Sucursal Norte')
        ->and($sucursal->codigo)->toBe('NORTE-1')
        ->and($sucursal->activa)->toBeTrue();

    Livewire::actingAs($admin)->test(Sucursales::class)
        ->call('editar', $sucursal->id)
        ->assertSet('nombre', 'Sucursal Norte')
        ->set('nombre', 'Norte Principal')->set('direccion', '')
        ->call('guardar')
        ->assertHasNoErrors();

    expect($sucursal->refresh()->nombre)->toBe('Norte Principal')
        ->and($sucursal->direccion)->toBeNull()
        ->and(Auditoria::query()->where('entidad', 'Sucursal')->pluck('accion')->all())
        ->toBe(['sucursal.creada', 'sucursal.actualizada']);
});

test('a branch keeps an optional phone that other screens never erase', function () {
    [$empresa, $admin] = empresaConAdministrador();

    Livewire::actingAs($admin)->test(Sucursales::class)
        ->call('crear')
        ->set('nombre', 'Sucursal Av. Beni')->set('codigo', 'S1')->set('telefono', ' 75070807 ')
        ->call('guardar')
        ->assertHasNoErrors()
        ->assertSee('75070807');

    $sucursal = Sucursal::withoutGlobalScopes()->where('empresa_id', $empresa->id)->sole();
    expect($sucursal->telefono)->toBe('75070807');

    // Quien actualiza sin enviar el teléfono (otra pantalla o proceso) no lo borra.
    app(GuardarSucursal::class)->actualizar($admin, $sucursal, ['nombre' => 'Beni', 'codigo' => 'S1', 'direccion' => null]);
    expect($sucursal->refresh()->telefono)->toBe('75070807');

    Livewire::actingAs($admin)->test(Sucursales::class)
        ->call('editar', $sucursal->id)
        ->assertSet('telefono', '75070807')
        ->set('telefono', str_repeat('7', 41))->call('guardar')->assertHasErrors(['telefono' => 'max'])
        ->set('telefono', '')->call('guardar')->assertHasNoErrors();

    expect($sucursal->refresh()->telefono)->toBeNull()
        ->and(Auditoria::query()->where('entidad', 'Sucursal')->latest('id')->value('despues'))->toHaveKey('telefono', null);
});

test('branch fields are validated in spanish', function () {
    [, $admin] = empresaConAdministrador();

    Livewire::actingAs($admin)->test(Sucursales::class)
        ->call('crear')
        ->set('nombre', '')->set('codigo', 'con espacios')
        ->call('guardar')
        ->assertHasErrors(['nombre' => 'required', 'codigo' => 'regex'])
        ->assertSee('Usa solo letras, números y guiones.');
});

test('a branch with active sellers cannot be deactivated until they are reassigned', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $vendedor = User::factory()->vendedor($sucursal)->create();

    Livewire::actingAs($admin)->test(Sucursales::class)
        ->call('confirmarEstado', $sucursal->id)
        ->call('cambiarEstado')
        ->assertHasErrors('sucursal');
    expect($sucursal->refresh()->activa)->toBeTrue();

    $vendedor->forceFill(['activo' => false])->save();

    Livewire::actingAs($admin)->test(Sucursales::class)
        ->call('confirmarEstado', $sucursal->id)
        ->call('cambiarEstado')
        ->assertHasNoErrors();

    expect($sucursal->refresh()->activa)->toBeFalse()
        ->and(Sucursal::withoutGlobalScopes()->whereKey($sucursal->id)->exists())->toBeTrue()
        ->and(Auditoria::query()->where('accion', 'sucursal.desactivada')->count())->toBe(1);
});

test('V3 a seller always operates in the assigned branch even if another one is requested', function () {
    [$empresa] = empresaConAdministrador();
    $asignada = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $otra = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $vendedor = User::factory()->vendedor($asignada)->create();

    $this->actingAs($vendedor);
    $resolver = app(ResolverSucursal::class);

    expect($resolver->paraOperacion($vendedor, $otra->id)->id)->toBe($asignada->id)
        ->and($resolver->paraOperacion($vendedor, null)->id)->toBe($asignada->id);
});

test('V3 an administrator chooses any active branch of the own company only', function () {
    [$empresa, $admin] = empresaConAdministrador();
    [$empresaB] = empresaConAdministrador();
    $norte = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $cerrada = Sucursal::factory()->inactiva()->create(['empresa_id' => $empresa->id]);
    $ajena = Sucursal::factory()->create(['empresa_id' => $empresaB->id]);

    $this->actingAs($admin);
    $resolver = app(ResolverSucursal::class);

    expect($resolver->paraOperacion($admin, $norte->id)->id)->toBe($norte->id);

    foreach ([$ajena->id, $cerrada->id, null, 999999] as $solicitada) {
        expect(fn () => $resolver->paraOperacion($admin, $solicitada))->toThrow(ValidationException::class);
    }
});

test('V3 a seller whose branch was deactivated cannot operate elsewhere', function () {
    [$empresa] = empresaConAdministrador();
    $asignada = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $otra = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $vendedor = User::factory()->vendedor($asignada)->create();
    $asignada->forceFill(['activa' => false])->save();

    $this->actingAs($vendedor);

    expect(fn () => app(ResolverSucursal::class)->paraOperacion($vendedor, $otra->id))
        ->toThrow(ValidationException::class);
});
