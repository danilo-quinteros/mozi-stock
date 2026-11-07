<?php

use App\Domain\Caja\Actions\AbrirTurnoCaja;
use App\Domain\Caja\Actions\GuardarCaja;
use App\Livewire\Empresa\Sucursales;
use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\Sucursal;
use App\Models\User;
use Livewire\Livewire;

/*
 * Sucursales y cajas (propuesta A aprobada el 01/11/2026): una página con cada sucursal, sus cajas
 * y sus vendedores. Las reglas se comprueban en el servidor, no solo en la pantalla.
 */

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->actingAs($this->admin);
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz', 'codigo' => 'CM-01']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte', 'codigo' => 'SN-01']);
    $this->principal = $this->matriz->cajaPrincipal();
    $this->caja2 = app(GuardarCaja::class)->crear($this->admin, ['sucursal_id' => $this->matriz->id, 'nombre' => 'Caja 2', 'modalidad' => 'individual']);
    $this->maria = User::factory()->vendedor($this->matriz)->create(['name' => 'María López']);
    $this->carla = User::factory()->vendedor($this->matriz)->create(['name' => 'Carla Rojas', 'caja_id' => $this->caja2->id]);
    $this->lucia = User::factory()->vendedor($this->matriz)->create(['name' => 'Lucía Mamani', 'caja_id' => null]);
    $this->pedro = User::factory()->vendedor($this->norte)->create(['name' => 'Pedro Arce']);
});

test('cada sucursal muestra sus cajas, su estado y sus vendedores, y avisa del vendedor sin caja', function () {
    app(AbrirTurnoCaja::class)->ejecutar($this->maria, $this->principal, '100.00');
    [, $adminAjeno] = empresaConAdministrador();
    $this->actingAs($adminAjeno);
    Sucursal::factory()->create(['empresa_id' => $adminAjeno->empresa_id, 'nombre' => 'Sucursal Ajena']);
    $this->actingAs($this->admin);

    Livewire::test(Sucursales::class)
        ->assertSee('Sucursales y cajas')
        ->assertSee('Casa Matriz')->assertSee('Sucursal Norte')
        ->assertSee('Caja 2')->assertSee('Individual · solo cobra quien abre el turno')
        ->assertSee('Abierta hoy')->assertSee('María López')
        ->assertSee('Lucía Mamani no tiene caja asignada')
        ->assertSee('vendedor sin caja')
        ->assertDontSee('Sucursal Ajena');

    $this->get(route('sucursales.index'))->assertOk()->assertSee('Turnos y arqueo de Casa Matriz');
    // El enlace antiguo de cada sucursal lleva a la misma página.
    $this->get('/sucursales/'.$this->matriz->id.'/cajas')->assertRedirect(route('sucursales.index'));
});

test('los vendedores se asignan desde la caja: solo de su sucursal, quien abrió un turno queda fijo y todo o nada', function () {
    app(AbrirTurnoCaja::class)->ejecutar($this->maria, $this->principal, '0');

    // María abrió el turno de la Caja principal: no se mueve y nadie más cambia en esa solicitud.
    Livewire::test(Sucursales::class)
        ->call('elegirVendedores', $this->caja2->id)
        ->assertSee('Vendedores de Caja 2')
        ->assertSee('ciérralo para moverlo')
        ->set('seleccion', [$this->lucia->id, $this->maria->id])
        ->call('guardarVendedores')
        ->assertHasErrors('caja_id');
    expect([$this->maria->refresh()->caja_id, $this->lucia->refresh()->caja_id])->toBe([$this->principal->id, null]);

    // Un vendedor de otra sucursal no entra en una caja de Casa Matriz.
    Livewire::test(Sucursales::class)
        ->call('elegirVendedores', $this->caja2->id)
        ->set('seleccion', [$this->pedro->id])
        ->call('guardarVendedores')
        ->assertHasErrors('caja_id');
    expect($this->pedro->refresh()->caja_id)->toBe($this->norte->cajaPrincipal()->id);

    Livewire::test(Sucursales::class)
        ->call('elegirVendedores', $this->caja2->id)
        ->set('seleccion', [$this->lucia->id])
        ->assertSee('empieza a usar esta caja')
        ->call('guardarVendedores')
        ->assertHasNoErrors()
        ->assertSet('vendedoresCajaId', null);

    expect($this->lucia->refresh()->caja_id)->toBe($this->caja2->id)
        ->and(Auditoria::query()->where('accion', 'usuario.caja_asignada')->where('entidad_id', $this->lucia->id)->exists())->toBeTrue();
});

test('un vendedor sin caja recibe una caja activa de su propia sucursal', function () {
    $ajena = $this->norte->cajaPrincipal();

    Livewire::test(Sucursales::class)
        ->call('asignar', $this->lucia->id)
        ->assertSee('Caja de Lucía Mamani')
        ->assertSet('cajaElegida', (string) $this->principal->id)
        ->set('cajaElegida', (string) $ajena->id)
        ->call('guardarAsignacion')
        ->assertHasErrors('caja_id')
        ->set('cajaElegida', (string) $this->principal->id)
        ->call('guardarAsignacion')
        ->assertHasNoErrors()
        ->assertDontSee('Lucía Mamani no tiene caja asignada');

    expect($this->lucia->refresh()->caja_id)->toBe($this->principal->id);
});

test('las cajas se crean y editan en su sucursal; con turno abierto no cambian de modalidad ni se desactivan', function () {
    Livewire::test(Sucursales::class)
        ->call('crearCaja', $this->norte->id)
        ->assertSee('Pertenece a Sucursal Norte')
        ->set('nombreCaja', 'Caja express')->set('codigoCaja', 'SN-EX')->set('modalidad', 'individual')
        ->call('guardarCaja')
        ->assertHasNoErrors();

    $nueva = Caja::query()->where('nombre', 'Caja express')->sole();
    expect($nueva->sucursal_id)->toBe($this->norte->id)->and($nueva->modalidad)->toBe('individual');

    $turno = app(AbrirTurnoCaja::class)->ejecutar($this->maria, $this->principal, '0');

    Livewire::test(Sucursales::class)
        ->call('editarCaja', $this->principal->id)
        ->assertSee('Tiene abierto el turno #'.$turno->id)
        ->set('modalidad', 'individual')
        ->call('guardarCaja')
        ->assertHasErrors('modalidad')
        ->call('confirmarEstadoCaja', $this->principal->id)
        ->call('cambiarEstadoCaja')
        ->assertHasErrors('caja');

    expect($this->principal->refresh()->modalidad)->toBe('compartida')->and($this->principal->activa)->toBeTrue();
});

test('una sucursal con un turno abierto no se desactiva', function () {
    $deposito = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Depósito']);
    app(AbrirTurnoCaja::class)->ejecutar($this->admin, $deposito->cajaPrincipal(), '0');

    Livewire::test(Sucursales::class)
        ->call('editar', $deposito->id)
        ->assertSee('Desactivar sucursal')
        ->call('confirmarEstado', $deposito->id)
        ->call('cambiarEstado')
        ->assertHasErrors('sucursal');

    expect($deposito->refresh()->activa)->toBeTrue();
});

test('ids de otra empresa no abren ni cambian nada y el vendedor no entra', function () {
    [, $adminAjeno] = empresaConAdministrador();
    $this->actingAs($adminAjeno);
    $sucursalAjena = Sucursal::factory()->create(['empresa_id' => $adminAjeno->empresa_id]);
    $cajaAjena = $sucursalAjena->cajaPrincipal();
    $vendedorAjeno = User::factory()->vendedor($sucursalAjena)->create();
    $this->actingAs($this->admin);

    Livewire::test(Sucursales::class)->call('editarCaja', $cajaAjena->id)->assertNotFound();
    Livewire::test(Sucursales::class)->call('elegirVendedores', $cajaAjena->id)->assertNotFound();
    Livewire::test(Sucursales::class)->call('crearCaja', $sucursalAjena->id)->assertNotFound();
    Livewire::test(Sucursales::class)->call('asignar', $vendedorAjeno->id)->assertNotFound();
    // Un vendedor ajeno colado en la selección se rechaza en el servidor.
    Livewire::test(Sucursales::class)
        ->call('elegirVendedores', $this->caja2->id)
        ->set('seleccion', [$vendedorAjeno->id])
        ->call('guardarVendedores')
        ->assertHasErrors('caja_id');

    expect($vendedorAjeno->refresh()->caja_id)->toBe($cajaAjena->id);

    $this->actingAs($this->maria)->get(route('sucursales.index'))->assertForbidden();
});
