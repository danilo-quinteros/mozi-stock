<?php

use App\Domain\Caja\Actions\AbrirTurnoCaja;
use App\Domain\Caja\Actions\AsegurarParticipanteTurno;
use App\Domain\Caja\Actions\CrearCajaPrincipal;
use App\Domain\Caja\Actions\GuardarCaja;
use App\Domain\Identidad\Actions\GuardarUsuarioEmpresa;
use App\Domain\Tenancy\Actions\GuardarSucursal;
use App\Enums\Rol;
use App\Livewire\Empresa\Usuarios;
use App\Livewire\Ventas\Pos;
use App\Models\Caja;
use App\Models\MovimientoCaja;
use App\Models\Sucursal;
use App\Models\TurnoUsuario;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('creating a branch automatically creates its primary cash register idempotently', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $this->actingAs($admin);

    // GuardarSucursal crea automáticamente la Caja principal
    $sucursal = app(GuardarSucursal::class)->crear($admin, [
        'nombre' => 'Sucursal Centro',
        'codigo' => 'SC-01',
    ]);

    $caja = Caja::where('sucursal_id', $sucursal->id)->sole();
    expect($caja->nombre)->toBe('Caja principal')
        ->and($caja->es_principal)->toBeTrue()
        ->and($caja->activa)->toBeTrue()
        ->and($caja->modalidad)->toBe('compartida')
        ->and($caja->empresa_id)->toBe($empresa->id);

    // Reejecutar CrearCajaPrincipal no la duplica (es idempotente)
    $cajaRepetida = app(CrearCajaPrincipal::class)->ejecutar($admin, $sucursal);
    expect($cajaRepetida->id)->toBe($caja->id)
        ->and(Caja::where('sucursal_id', $sucursal->id)->count())->toBe(1);
});

test('a seller user must have exactly one cash register from their own branch', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $this->actingAs($admin);
    $sucursal1 = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $sucursal2 = Sucursal::factory()->create(['empresa_id' => $empresa->id]);

    $cajaSucursal1 = $sucursal1->cajaPrincipal();
    $cajaSucursal2 = $sucursal2->cajaPrincipal();

    // Intento de asignar una caja de otra sucursal es rechazado por el dominio
    expect(fn () => app(GuardarUsuarioEmpresa::class)->crear($admin, [
        'name' => 'Vendedor Prueba',
        'email' => 'vendedor@test.com',
        'rol' => Rol::Vendedor,
        'sucursal_id' => $sucursal1->id,
        'caja_id' => $cajaSucursal2->id,
        'password' => 'Password12345!',
        'activo' => true,
    ]))->toThrow(ValidationException::class);

    // Asignación correcta con su propia caja
    $vendedor = app(GuardarUsuarioEmpresa::class)->crear($admin, [
        'name' => 'Vendedor Prueba',
        'email' => 'vendedor@test.com',
        'rol' => Rol::Vendedor,
        'sucursal_id' => $sucursal1->id,
        'caja_id' => $cajaSucursal1->id,
        'password' => 'Password12345!',
        'activo' => true,
    ]);

    expect($vendedor->sucursal_id)->toBe($sucursal1->id)
        ->and($vendedor->caja_id)->toBe($cajaSucursal1->id);
});

test('in user creation form selecting a branch auto-selects its single cash register', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $caja = $sucursal->cajaPrincipal();

    Livewire::actingAs($admin)->test(Usuarios::class)
        ->call('crear')
        ->set('rol', 'vendedor')
        ->set('sucursal_id', (string) $sucursal->id)
        ->assertSet('caja_id', (string) $caja->id);
});

test('opening a shift validates initial fund, active status and prevents duplicate shifts', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $this->actingAs($admin);
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $caja = $sucursal->cajaPrincipal();
    $vendedor = User::factory()->vendedor($sucursal)->create(['caja_id' => $caja->id]);

    // Abrir turno con fondo inicial 350.00
    $turno = app(AbrirTurnoCaja::class)->ejecutar($vendedor, $caja, '350.00');

    expect($turno->monto_apertura)->toBe('350.00')
        ->and($turno->estado)->toBe('abierto')
        ->and($turno->caja_id)->toBe($caja->id)
        ->and($turno->usuario_apertura_id)->toBe($vendedor->id);

    // Impedir abrir otro turno en la misma caja
    expect(fn () => app(AbrirTurnoCaja::class)->ejecutar($admin, $caja, '0.00'))
        ->toThrow(ValidationException::class);

    // Impedir que el mismo vendedor abra otro turno en otra caja
    $caja2 = app(GuardarCaja::class)->crear($admin, [
        'nombre' => 'Caja 2',
        'sucursal_id' => $sucursal->id,
    ]);
    expect(fn () => app(AbrirTurnoCaja::class)->ejecutar($vendedor, $caja2, '0.00'))
        ->toThrow(ValidationException::class);

    // No se puede desactivar una caja con turno abierto
    expect(fn () => app(GuardarCaja::class)->cambiarEstado($admin, $caja, false))
        ->toThrow(ValidationException::class);
});

test('shared shift allows multiple authorized sellers while individual shift restricts', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $this->actingAs($admin);
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);

    // Caja compartida
    $cajaCompartida = $sucursal->cajaPrincipal();
    $vendedor1 = User::factory()->vendedor($sucursal)->create(['caja_id' => $cajaCompartida->id]);
    $vendedor2 = User::factory()->vendedor($sucursal)->create(['caja_id' => $cajaCompartida->id]);

    $turnoCompartido = app(AbrirTurnoCaja::class)->ejecutar($vendedor1, $cajaCompartida, '0.00');
    expect($turnoCompartido->usuarioPuedeOperar($vendedor1))->toBeTrue()
        ->and($turnoCompartido->usuarioPuedeOperar($vendedor2))->toBeTrue();

    // Vendedor 2 se incorpora al turno compartido
    app(AsegurarParticipanteTurno::class)->ejecutar($vendedor2, $turnoCompartido);
    expect(TurnoUsuario::where('turno_caja_id', $turnoCompartido->id)->where('user_id', $vendedor2->id)->exists())->toBeTrue();

    // Caja individual
    $cajaIndividual = app(GuardarCaja::class)->crear($admin, [
        'nombre' => 'Caja Individual',
        'sucursal_id' => $sucursal->id,
        'modalidad' => 'individual',
    ]);
    $vendedor3 = User::factory()->vendedor($sucursal)->create(['caja_id' => $cajaIndividual->id]);
    $vendedor4 = User::factory()->vendedor($sucursal)->create(['caja_id' => $cajaIndividual->id]);

    $turnoIndividual = app(AbrirTurnoCaja::class)->ejecutar($vendedor3, $cajaIndividual, '50.00');
    expect($turnoIndividual->usuarioPuedeOperar($vendedor3))->toBeTrue()
        ->and($turnoIndividual->usuarioPuedeOperar($vendedor4))->toBeFalse();
});

test('POS requires an open shift and opening one preserves cart contents to confirm sale', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $this->actingAs($admin);
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $producto = productoDe($empresa, ['precio_consumidor' => '25.00']);
    cargarStock($admin, $sucursal, $producto, '10');

    $caja = $sucursal->cajaPrincipal();
    $vendedor = User::factory()->vendedor($sucursal)->create(['caja_id' => $caja->id]);

    // El vendedor entra al POS sin turno abierto y agrega productos
    $pos = Livewire::actingAs($vendedor)->test(Pos::class)
        ->call('agregarProducto', $producto->id)
        ->assertSet('lineas.0.cantidad', '1')
        // Intenta confirmar sin turno: no debe confirmar y abre modal de apertura
        ->call('confirmar')
        ->assertSet('modalAperturaAbierto', true)
        ->assertCount('lineas', 1); // ¡Carrito preservado!

    expect(Venta::count())->toBe(0);

    // Abre el turno desde el POS con fondo inicial de Bs 150.00
    $pos->set('fondoInicial', '150.00')
        ->call('abrirTurno')
        ->assertSet('modalAperturaAbierto', false)
        ->assertCount('lineas', 1); // Carrito sigue intacto

    // Ahora confirma la venta exitosamente
    $pos->call('confirmar')->assertHasNoErrors();

    $venta = Venta::sole();
    expect($venta->caja_id)->toBe($caja->id)
        ->and($venta->turno_caja_id)->not->toBeNull()
        ->and($venta->total)->toBe('25.00');

    $movimiento = MovimientoCaja::sole();
    expect($movimiento->caja_id)->toBe($caja->id)
        ->and($movimiento->turno_caja_id)->toBe($venta->turno_caja_id)
        ->and($movimiento->monto)->toBe('25.00');
});

test('previous day shift is detected and can be continued or closed and renewed', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $caja = $sucursal->cajaPrincipal();
    $vendedor = User::factory()->vendedor($sucursal)->create(['caja_id' => $caja->id]);

    // Crear un turno con fecha de ayer
    $turnoAyer = app(AbrirTurnoCaja::class)->ejecutar($vendedor, $caja, '100.00');
    $turnoAyer->fecha_apertura = now()->subDays(1);
    $turnoAyer->save();

    expect($turnoAyer->esDelDiaAnterior())->toBeTrue();

    // Entrar al POS y solicitar continuar el turno anterior
    Livewire::actingAs($vendedor)->test(Pos::class)
        ->call('continuarTurnoAnterior')
        ->assertSet('turnoAnteriorAceptado', true);

    expect($turnoAyer->refresh()->estaAbierto())->toBeTrue();

    // Alternativa: cerrar anterior y abrir nuevo
    Livewire::actingAs($vendedor)->test(Pos::class)
        ->set('fondoInicial', '200.00')
        ->call('cerrarYCrearNuevoTurno');

    expect($turnoAyer->refresh()->estado)->toBe('cerrado');

    $nuevoTurno = $caja->refresh()->turnoActivo;
    expect($nuevoTurno)->not->toBeNull()
        ->and($nuevoTurno->id)->not->toBe($turnoAyer->id)
        ->and($nuevoTurno->monto_apertura)->toBe('200.00');
});

test('multi-tenant isolation: company B cannot see, assign or operate company A cash registers', function () {
    [$empresaA, $adminA] = empresaConAdministrador();
    [$empresaB, $adminB] = empresaConAdministrador();

    $this->actingAs($adminA);
    $sucursalA = Sucursal::factory()->create(['empresa_id' => $empresaA->id]);
    $cajaA = $sucursalA->cajaPrincipal();

    $this->actingAs($adminB);
    $sucursalB = Sucursal::factory()->create(['empresa_id' => $empresaB->id]);

    // Admin B no puede crear usuario en Empresa B asignándole la caja de Empresa A
    expect(fn () => app(GuardarUsuarioEmpresa::class)->crear($adminB, [
        'name' => 'Intruso',
        'email' => 'intruso@empresa-b.com',
        'rol' => Rol::Vendedor,
        'sucursal_id' => $sucursalB->id,
        'caja_id' => $cajaA->id,
        'password' => 'Password12345!',
        'activo' => true,
    ]))->toThrow(ValidationException::class);

    // Admin B no puede abrir turno en la caja de Empresa A
    expect(fn () => app(AbrirTurnoCaja::class)->ejecutar($adminB, $cajaA, '50.00'))
        ->toThrow(Exception::class);
});
