<?php

use App\Domain\Caja\Actions\AbrirTurnoCaja;
use App\Domain\Caja\Actions\CerrarTurnoCaja;
use App\Domain\Caja\Actions\GuardarCaja;
use App\Domain\Egresos\Actions\RegistrarEgreso;
use App\Domain\Ventas\Actions\VenderEnPos;
use App\Livewire\Caja\Arqueo;
use App\Livewire\Caja\ControlCajaTopbar;
use App\Models\Caja;
use App\Models\CategoriaEgreso;
use App\Models\MovimientoCaja;
use App\Models\Sucursal;
use App\Models\TurnoCaja;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    [$empresa, $admin] = empresaConAdministrador();
    $this->empresa = $empresa;
    $this->admin = $admin;

    $this->sucursal1 = Sucursal::factory()->create([
        'empresa_id' => $this->empresa->id,
        'nombre' => 'Sucursal Central',
        'activa' => true,
    ]);
    $this->sucursal2 = Sucursal::factory()->create([
        'empresa_id' => $this->empresa->id,
        'nombre' => 'Sucursal Sur',
        'activa' => true,
    ]);

    $this->caja1 = $this->sucursal1->cajaPrincipal();
    $this->caja1->update(['nombre' => 'Caja Central 1']);

    $this->caja2 = app(GuardarCaja::class)->crear($this->admin, [
        'sucursal_id' => $this->sucursal1->id,
        'nombre' => 'Caja Central 2',
        'modalidad' => 'individual',
    ]);

    $this->admin->update([
        'sucursal_id' => $this->sucursal1->id,
        'caja_id' => $this->caja1->id,
    ]);
});

test('control caja topbar muestra estado cerrado y permite abrir y cerrar turno', function () {
    $this->actingAs($this->admin);

    // Inicialmente no hay turno abierto
    $component = Livewire::test(ControlCajaTopbar::class)
        ->assertSee('Cerrada')
        ->assertSee('Abrir caja')
        ->assertSee('Caja Central 1')
        ->assertSet('modalAperturaAbierto', false);

    // Abrir modal de apertura
    $component->call('abrirModalApertura')
        ->assertSet('modalAperturaAbierto', true);

    // Ejecutar apertura con fondo inicial
    $component->set('fondoInicial', '150.00')
        ->call('ejecutarApertura')
        ->assertDispatched('turno-modificado')
        ->assertSet('avisoTipo', 'success')
        ->assertSee('Cerrar caja')
        ->assertSee('abierto con éxito');

    expect(TurnoCaja::query()->where('caja_id', $this->caja1->id)->whereNull('fecha_cierre')->count())->toBe(1);

    // Abrir modal de cierre
    $component->call('abrirModalCierre')
        ->assertSet('modalCierreAbierto', true);

    // Ejecutar cierre
    $component->set('montoCierre', '150.00')
        ->set('observacionesCierre', 'Cierre cuadrado')
        ->call('ejecutarCierre')
        ->assertDispatched('turno-modificado')
        ->assertSet('avisoTipo', 'success')
        ->assertSee('Cerrada')
        ->assertSee('Abrir caja');

    expect(TurnoCaja::query()->where('caja_id', $this->caja1->id)->whereNotNull('fecha_cierre')->count())->toBe(1);
});

test('arqueo del administrador muestra la caja abierta, exige motivo en el faltante y guarda el resultado', function () {
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-22 09:00', 'America/La_Paz'));
    $turno = app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->caja1, '200.00');
    $categoria = new CategoriaEgreso(['nombre' => 'Servicios', 'descripcion' => 'Luz y agua']);
    $categoria->empresa_id = $this->empresa->id;
    $categoria->save();
    app(RegistrarEgreso::class)->ejecutar($this->admin, [
        'sucursal_id' => $this->sucursal1->id, 'categoria_id' => $categoria->id, 'descripcion' => 'Luz', 'metodo' => 'efectivo',
        'monto' => '40', 'externo' => false, 'turno_id' => $turno->id, 'clave' => (string) Str::uuid(),
    ]);

    Livewire::test(Arqueo::class)
        ->assertSee('Cajas en este momento')->assertSee('Caja Central 1')->assertSee('Bs 160,00')
        ->assertViewHas('turnos', fn ($p) => $p->getCollection()->pluck('id')->all() === [$turno->id]);

    // Con diferencia y sin motivo no se cierra.
    expect(fn () => app(CerrarTurnoCaja::class)->ejecutar($this->admin, $turno, '150.00'))
        ->toThrow(ValidationException::class);
    expect($turno->fresh()->estaAbierto())->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-10-22 18:00', 'America/La_Paz'));
    app(CerrarTurnoCaja::class)->ejecutar($this->admin, $turno, '150.00', 'Faltó cambio');
    $cerrado = $turno->fresh();
    expect((string) $cerrado->efectivo_esperado)->toBe('160.00')
        ->and((string) $cerrado->diferencia)->toBe('-10.00')
        ->and($cerrado->resultado())->toBe('faltante');

    Livewire::test(Arqueo::class)
        ->assertSee('Cierre con faltante')->assertSee('Faltante − 10,00')->assertSee('1 faltante · 0 sobrantes · 1 cierre')
        ->call('verDetalleTurno', $turno->id)
        ->assertSee('Arqueo · Turno #'.$turno->id)->assertSee('Bs 160,00')->assertSee('Observación: Faltó cambio')
        ->assertSee(route('caja.imprimir', ['caja' => $turno->caja_id, 'sucursal' => $turno->sucursal_id, 'fecha' => '2026-10-22']))
        ->call('cerrarDetalleTurno')->assertDontSee('Arqueo · Turno #'.$turno->id)
        ->call('cambiarPestana', 'movimientos')->assertSee('Egreso · EGR-00001')
        ->call('cambiarPestana', 'responsables')->assertSee($this->admin->name)
        ->call('cambiarPestana', 'dias')->assertSee('22/10/2026');

    // Otra empresa no puede abrir este turno cambiando el id.
    [, $adminB] = empresaConAdministrador();
    Livewire::actingAs($adminB)->test(Arqueo::class)->call('verDetalleTurno', $turno->id)->assertForbidden();
});

test('mi caja del vendedor muestra solo lo suyo y no da acceso a turnos ajenos ni al export', function () {
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-22 09:00', 'America/La_Paz'));
    $producto = productoDe($this->empresa, ['nombre' => 'Aceite 1L', 'precio_consumidor' => '20.00']);
    cargarStock($this->admin, $this->sucursal1, $producto, '10');
    $ana = User::factory()->vendedor($this->sucursal1)->create(['name' => 'Ana Vendedora', 'caja_id' => $this->caja1->id]);
    $beto = User::factory()->vendedor($this->sucursal1)->create(['name' => 'Beto Vendedor', 'caja_id' => $this->caja1->id]);
    $turno = app(AbrirTurnoCaja::class)->ejecutar($ana, $this->caja1, '100.00');
    $vender = fn (User $actor, string $cantidad) => app(VenderEnPos::class)->ejecutar($actor, [
        'sucursal_id' => $this->sucursal1->id, 'cliente_id' => null, 'caja_id' => $this->caja1->id,
        'lineas' => [['producto_id' => $producto->id, 'cantidad' => $cantidad, 'tipo_precio' => 'consumidor', 'descuento_tipo' => 'bs', 'descuento_valor' => '0']],
        'descuento_global_tipo' => 'bs', 'descuento_global_valor' => '0', 'clave' => (string) Str::uuid(),
    ], ['metodo' => 'efectivo', 'en_cuotas' => false, 'cuota_inicial' => null, 'efectivo' => null, 'electronico' => null, 'clave' => (string) Str::uuid()]);
    $vender($ana, '1');
    $vender($beto, '2');

    // Turno de otra caja de la misma sucursal que Ana nunca tocó.
    $ajeno = app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->caja2, '0');

    $this->actingAs($ana);
    $this->get(route('caja'))->assertOk()->assertSee('Mi caja')->assertDontSee('Cajas en este momento');
    Livewire::test(Arqueo::class)
        ->assertSee('Bs 160,00')            // 100 de fondo + 20 de Ana + 40 de Beto
        ->assertSee('Turno compartido con Beto Vendedor')
        ->assertViewHas('mios', fn ($m) => $m['ingresos'] === '20.00' && $m['ventas'] === 1)
        ->assertViewHas('misMovimientos', fn ($p) => $p->total() === 1)
        ->call('verDetalleTurno', $ajeno->id)->assertForbidden();

    $this->get(route('caja.exportar', ['fecha' => '2026-10-22']))->assertForbidden();
    expect($turno->fresh()->estaAbierto())->toBeTrue();
});

test('no se registra dinero en un turno que ya se cerró', function () {
    $this->actingAs($this->admin);
    $turno = app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->caja1, '0');
    app(CerrarTurnoCaja::class)->ejecutar($this->admin, $turno, '0');
    $categoria = new CategoriaEgreso(['nombre' => 'Servicios', 'descripcion' => 'Luz y agua']);
    $categoria->empresa_id = $this->empresa->id;
    $categoria->save();

    expect(fn () => app(RegistrarEgreso::class)->ejecutar($this->admin, [
        'sucursal_id' => $this->sucursal1->id, 'categoria_id' => $categoria->id, 'descripcion' => 'Luz', 'metodo' => 'efectivo',
        'monto' => '10', 'externo' => false, 'turno_id' => $turno->id, 'clave' => (string) Str::uuid(),
    ]))->toThrow(ValidationException::class);
    expect(MovimientoCaja::query()->where('turno_caja_id', $turno->id)->exists())->toBeFalse();
});

test('exportar e imprimir arqueo respetan el filtro de caja y muestran la caja de cada salida', function () {
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00', 'America/La_Paz'));
    $turno = app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->caja1, '100.00');
    $categoria = new CategoriaEgreso(['nombre' => 'Servicios', 'descripcion' => 'Luz y agua']);
    $categoria->empresa_id = $this->empresa->id;
    $categoria->save();
    $egreso = fn (string $descripcion, bool $externo) => app(RegistrarEgreso::class)->ejecutar($this->admin, [
        'sucursal_id' => $this->sucursal1->id, 'categoria_id' => $categoria->id, 'descripcion' => $descripcion, 'metodo' => 'efectivo',
        'monto' => '40', 'externo' => $externo, 'clave' => (string) Str::uuid(), ...($externo ? [] : ['turno_id' => $turno->id]),
    ]);
    $egreso('Luz pagada en caja', false);
    $egreso('Luz pagada externa', true);

    $todo = $this->get(route('caja.exportar', ['fecha' => '2026-10-22']))->streamedContent();
    expect($todo)->toContain(';Sucursal;Caja;Usuario;')
        ->toContain('"Sucursal Central";"Caja Central 1";')
        ->toContain('"Sucursal Central";"Fuera de caja";');

    $soloCaja = $this->get(route('caja.exportar', ['fecha' => '2026-10-22', 'caja' => $this->caja1->id]))->streamedContent();
    expect($soloCaja)->toContain('Luz pagada en caja')->not->toContain('Luz pagada externa');

    $this->get(route('caja.imprimir', ['fecha' => '2026-10-22', 'caja' => $this->caja1->id]))->assertOk()
        ->assertSee('Caja Central 1')->assertSee('Luz pagada en caja')->assertDontSee('Luz pagada externa');

    // Otra empresa no obtiene nada usando el id de esta caja.
    [, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    expect($this->get(route('caja.exportar', ['fecha' => '2026-10-22', 'caja' => $this->caja1->id]))->streamedContent())
        ->not->toContain('Luz pagada');
});

test('los turnos se agrupan por día de La Paz y no por día UTC', function () {
    $this->actingAs($this->admin);

    // 19:30 en La Paz son las 23:30 UTC: a las 21:00 locales (01:00 UTC del día siguiente) sigue siendo el mismo día.
    $this->travelTo(CarbonImmutable::parse('2026-10-22 19:30', 'America/La_Paz'));
    $temprano = app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->caja1, '0');
    $this->travelTo(CarbonImmutable::parse('2026-10-22 21:00', 'America/La_Paz'));
    expect($temprano->fresh()->esDelDiaAnterior())->toBeFalse();

    // Cerrar el turno no reescribe su fecha de apertura (columna sin ON UPDATE CURRENT_TIMESTAMP).
    app(CerrarTurnoCaja::class)->ejecutar($this->admin, $temprano, '0');
    expect($temprano->fresh()->fecha_apertura->equalTo($temprano->fecha_apertura))->toBeTrue();
    $noche = app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->caja1, '50');

    $this->travelTo(CarbonImmutable::parse('2026-10-23 00:30', 'America/La_Paz'));
    expect($noche->fresh()->esDelDiaAnterior())->toBeTrue();

    // El 23 solo aparece el turno de la noche, que sigue abierto; el de las 19:30 es del 22.
    $ids = fn (array $esperados) => fn ($turnos) => $turnos->getCollection()->pluck('id')->sort()->values()->all() === $esperados;
    Livewire::test(Arqueo::class)->set('fecha', '2026-10-22')->assertViewHas('turnos', $ids([$temprano->id, $noche->id]))
        ->set('fecha', '2026-10-23')->assertViewHas('turnos', $ids([$noche->id]));
});
