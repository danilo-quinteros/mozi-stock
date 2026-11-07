<?php

use App\Domain\Caja\Actions\AbrirTurnoCaja;
use App\Domain\Caja\Actions\CerrarTurnoCaja;
use App\Domain\Caja\Actions\GuardarCaja;
use App\Domain\Ventas\Actions\VenderEnPos;
use App\Enums\MetodoPago;
use App\Livewire\Empresa\Sucursales;
use App\Livewire\Ventas\Pos;
use App\Models\Caja;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('admin can view branch cash registers and create additional registers', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $this->actingAs($admin);

    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $cajaPrincipal = $sucursal->cajaPrincipal();

    // Las cajas se gestionan en la tarjeta de su sucursal (Sucursales y cajas, 01/11/2026)
    Livewire::actingAs($admin)->test(Sucursales::class)
        ->assertOk()
        ->assertSee($cajaPrincipal->nombre)
        ->assertSee('Principal')
        // Abrir modal de creación de caja
        ->call('crearCaja', $sucursal->id)
        ->assertSet('formularioCajaAbierto', true)
        ->set('nombreCaja', 'Caja Mostrador 2')
        ->set('codigoCaja', 'CM-02')
        ->set('modalidad', 'individual')
        ->call('guardarCaja')
        ->assertSet('formularioCajaAbierto', false);

    $caja2 = Caja::withoutGlobalScopes()
        ->where('empresa_id', $empresa->id)
        ->where('sucursal_id', $sucursal->id)
        ->where('nombre', 'Caja Mostrador 2')
        ->first();

    expect($caja2)->not->toBeNull()
        ->and($caja2->codigo)->toBe('CM-02')
        ->and($caja2->modalidad)->toBe('individual')
        ->and($caja2->es_principal)->toBeFalse()
        ->and($caja2->activa)->toBeTrue();
});

test('admin can edit a cash register and cannot deactivate one with an active shift', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $this->actingAs($admin);

    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $caja = $sucursal->cajaPrincipal();

    // Edición de la caja
    Livewire::actingAs($admin)->test(Sucursales::class)
        ->call('editarCaja', $caja->id)
        ->assertSet('editandoCajaId', $caja->id)
        ->set('nombreCaja', 'Caja Principal Modificada')
        ->call('guardarCaja')
        ->assertSet('formularioCajaAbierto', false);

    expect($caja->refresh()->nombre)->toBe('Caja Principal Modificada');

    // Abrir un turno en la caja
    app(AbrirTurnoCaja::class)->ejecutar($admin, $caja, '50.00');

    // Intento de desactivar debe ser bloqueado porque tiene turno abierto
    Livewire::actingAs($admin)->test(Sucursales::class)
        ->call('confirmarEstadoCaja', $caja->id)
        ->call('cambiarEstadoCaja')
        ->assertHasErrors(['caja']);

    expect($caja->refresh()->activa)->toBeTrue();
});

test('POS cashier can close shift with cash reconciliation and difference detection', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $this->actingAs($admin);

    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $producto = productoDe($empresa, ['precio_consumidor' => '30.00']);
    cargarStock($admin, $sucursal, $producto, '10');

    $caja = $sucursal->cajaPrincipal();
    $vendedor = User::factory()->vendedor($sucursal)->create(['caja_id' => $caja->id]);

    // 1. Abrir turno con fondo inicial de Bs 100.00
    $turno = app(AbrirTurnoCaja::class)->ejecutar($vendedor, $caja, '100.00');

    // 2. Realizar venta en efectivo de Bs 30.00
    app(VenderEnPos::class)->ejecutar($vendedor, [
        'sucursal_id' => $sucursal->id,
        'caja_id' => $caja->id,
        'cliente_id' => null,
        'lineas' => [
            [
                'producto_id' => $producto->id,
                'cantidad' => '1',
                'tipo_precio' => 'consumidor',
                'descuento_tipo' => 'bs',
                'descuento_valor' => '0',
            ],
        ],
        'descuento_global_tipo' => 'bs',
        'descuento_global_valor' => '0',
        'clave' => (string) Str::uuid(),
    ], [
        'metodo' => MetodoPago::Efectivo->value,
        'en_cuotas' => false,
        'cuota_inicial' => '0',
        'efectivo' => '30.00',
        'electronico' => '0',
        'clave' => (string) Str::uuid(),
    ]);

    // Efectivo esperado = 100.00 + 30.00 = 130.00
    $arqueo = $turno->calcularArqueo('130.00');
    expect($arqueo['monto_apertura'])->toBe('100.00')
        ->and($arqueo['ingresos_efectivo'])->toBe('30.00')
        ->and($arqueo['efectivo_esperado'])->toBe('130.00')
        ->and($arqueo['estado_diferencia'])->toBe('cuadrada')
        ->and($arqueo['diferencia'])->toBe('0.00')
        ->and($arqueo['cantidad_ventas'])->toBe(1);

    // 3. Probar simulación con faltante
    $arqueoFaltante = $turno->calcularArqueo('120.00');
    expect($arqueoFaltante['estado_diferencia'])->toBe('faltante')
        ->and($arqueoFaltante['diferencia'])->toBe('-10.00');

    // 4. Probar simulación con sobrante
    $arqueoSobrante = $turno->calcularArqueo('140.00');
    expect($arqueoSobrante['estado_diferencia'])->toBe('sobrante')
        ->and($arqueoSobrante['diferencia'])->toBe('10.00');

    // 5. Cerrar el turno desde POS
    Livewire::actingAs($vendedor)->test(Pos::class)
        ->call('abrirModalCierre')
        ->assertSet('modalCierreAbierto', true)
        ->set('montoCierre', '130.00')
        ->set('observacionesCierre', 'Turno entregado sin novedades')
        ->call('cerrarTurnoActual')
        ->assertSet('modalCierreAbierto', false)
        ->assertHasNoErrors();

    expect($turno->refresh()->estado)->toBe('cerrado')
        ->and($turno->monto_cierre)->toBe('130.00')
        ->and($turno->usuario_cierre_id)->toBe($vendedor->id)
        ->and($turno->observaciones)->toBe('Turno entregado sin novedades');
});

test('shift closing authorization respects individual vs shared cash register rules', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $this->actingAs($admin);

    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);

    // Caja individual
    $cajaIndividual = app(GuardarCaja::class)->crear($admin, [
        'nombre' => 'Caja Individual',
        'sucursal_id' => $sucursal->id,
        'modalidad' => 'individual',
    ]);

    $vendedor1 = User::factory()->vendedor($sucursal)->create(['caja_id' => $cajaIndividual->id]);
    $vendedor2 = User::factory()->vendedor($sucursal)->create(['caja_id' => $cajaIndividual->id]);

    $turno = app(AbrirTurnoCaja::class)->ejecutar($vendedor1, $cajaIndividual, '50.00');

    // Vendedor 2 no puede cerrar el turno individual de Vendedor 1
    expect(fn () => app(CerrarTurnoCaja::class)->ejecutar($vendedor2, $turno, '50.00'))
        ->toThrow(ValidationException::class);

    // Administrador sí puede cerrar el turno individual
    $turnoAdminCierre = app(CerrarTurnoCaja::class)->ejecutar($admin, $turno, '50.00');
    expect($turnoAdminCierre->estado)->toBe('cerrado')
        ->and($turnoAdminCierre->usuario_cierre_id)->toBe($admin->id);
});

test('multi-tenant isolation: company B cannot access or alter company A cash registers', function () {
    [$empresaA, $adminA] = empresaConAdministrador();
    [$empresaB, $adminB] = empresaConAdministrador();

    $this->actingAs($adminA);
    $sucursalA = Sucursal::factory()->create(['empresa_id' => $empresaA->id]);
    $cajaA = $sucursalA->cajaPrincipal();

    // Admin B no ve ni abre las cajas de la sucursal de Empresa A
    Livewire::actingAs($adminB)->test(Sucursales::class)
        ->assertDontSee($sucursalA->nombre);
    Livewire::actingAs($adminB)->test(Sucursales::class)
        ->call('crearCaja', $sucursalA->id)
        ->assertNotFound();
    Livewire::actingAs($adminB)->test(Sucursales::class)
        ->call('editarCaja', $cajaA->id)
        ->assertNotFound();

    // Admin B no puede modificar la caja de Empresa A mediante la acción
    expect(fn () => app(GuardarCaja::class)->actualizar($adminB, $cajaA, ['nombre' => 'Hackeada']))
        ->toThrow(Exception::class);
});
