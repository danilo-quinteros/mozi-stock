<?php

use App\Domain\Caja\Actions\AbrirTurnoCaja;
use App\Domain\Caja\Actions\GuardarCaja;
use App\Livewire\Caja\Arqueo;
use App\Livewire\Caja\ControlCajaTopbar;
use App\Livewire\Ventas\Pos;
use App\Models\MovimientoCaja;
use App\Models\Sucursal;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Decisión del propietario (06/11/2026): el administrador puede tener abierto un turno en cada
 * caja (la primera sigue vendiendo todo el día) y cierra cualquier caja de su empresa. El
 * vendedor sigue con un solo turno. Los modales muestran el motivo cuando el servidor rechaza.
 */

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->actingAs($this->admin);
    $this->central = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Central']);
    $this->sur = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Sur']);
    $this->principal = $this->central->cajaPrincipal();
    $this->caja2 = app(GuardarCaja::class)->crear($this->admin, ['sucursal_id' => $this->central->id, 'nombre' => 'Caja 2', 'modalidad' => 'compartida']);
    $this->producto = productoDe($this->empresa, ['nombre' => 'Aceite 1L', 'precio_consumidor' => '20.00']);
    cargarStock($this->admin, $this->central, $this->producto, '10');
});

test('el administrador abre una segunda caja sin cerrar la primera y cada venta queda en su caja', function () {
    $primero = app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->principal, '100.00');
    $segundo = app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->caja2, '50.00');
    $tercero = app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->sur->cajaPrincipal(), '0');

    expect(TurnoCaja::query()->where('usuario_apertura_id', $this->admin->id)->where('estado', 'abierto')->count())->toBe(3)
        ->and($primero->refresh()->estaAbierto())->toBeTrue();

    // Dos turnos en la misma caja siguen prohibidos, también para el administrador.
    expect(fn () => app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->caja2, '0'))
        ->toThrow(ValidationException::class, 'Esta caja ya tiene un turno abierto.');

    // El POS vende con la caja elegida: el efectivo entra solo en el arqueo de Caja 2.
    Livewire::test(Pos::class)
        ->assertSet('pos_caja_id', (string) $this->principal->id)
        ->assertSee('Caja 2 · abierta')
        ->set('pos_caja_id', (string) $this->caja2->id)
        ->call('agregarProducto', $this->producto->id)
        ->set('recibido', '20')
        ->call('confirmar')
        ->assertHasNoErrors();

    $venta = Venta::query()->sole();
    expect([$venta->caja_id, $venta->turno_caja_id])->toBe([$this->caja2->id, $segundo->id])
        ->and(MovimientoCaja::query()->sole()->turno_caja_id)->toBe($segundo->id)
        ->and($segundo->refresh()->calcularArqueo()['efectivo_esperado'])->toBe('70.00')
        ->and($primero->refresh()->calcularArqueo()['efectivo_esperado'])->toBe('100.00')
        ->and($tercero->refresh()->estaAbierto())->toBeTrue();
});

test('en el POS el administrador abre otra caja desde el modal, avisado de la que sigue abierta', function () {
    app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->principal, '100.00');

    // Antes el servidor rechazaba la segunda apertura y el modal no mostraba nada.
    Livewire::test(Pos::class)
        ->set('pos_caja_id', (string) $this->caja2->id)
        ->assertSee('Caja 2 · cerrada')
        ->call('iniciarAperturaTurno')
        ->assertSet('modalAperturaAbierto', true)
        ->assertSee('Sigues con 1 caja abierta')
        ->assertSee('Caja principal · Sucursal Central')
        ->set('fondoInicial', '30.00')
        ->call('abrirTurno')
        ->assertHasNoErrors()
        ->assertSet('modalAperturaAbierto', false)
        ->assertSee('Caja 2 · abierta');

    expect($this->caja2->refresh()->turnoActivo?->usuario_apertura_id)->toBe($this->admin->id)
        ->and($this->principal->refresh()->turnoActivo)->not->toBeNull();

    // Una caja de otra sucursal no se elige en el POS: vuelve a la que corresponde.
    Livewire::test(Pos::class)
        ->set('pos_caja_id', (string) $this->sur->cajaPrincipal()->id)
        ->assertSet('pos_caja_id', (string) $this->principal->id);
});

test('si el servidor rechaza la apertura o el cierre, el modal muestra el motivo', function () {
    $vendedor = User::factory()->vendedor($this->central)->create(['caja_id' => $this->caja2->id]);

    $pos = Livewire::test(Pos::class)
        ->set('pos_caja_id', (string) $this->caja2->id)
        ->call('iniciarAperturaTurno');
    // Mientras tanto un vendedor abre Caja 2.
    $turno = app(AbrirTurnoCaja::class)->ejecutar($vendedor, $this->caja2, '0');
    $pos->call('abrirTurno')
        ->assertHasErrors('pos_caja_id')
        ->assertSet('modalAperturaAbierto', true)
        ->assertSee('Esta caja ya tiene un turno abierto.');

    // En la barra superior: otro administrador abre la Caja principal mientras el modal está abierto.
    $barra = Livewire::test(ControlCajaTopbar::class)->call('abrirModalApertura')->assertSet('modalAperturaAbierto', true);
    app(AbrirTurnoCaja::class)->ejecutar(User::factory()->administrador($this->empresa)->create(), $this->principal, '0');
    $barra->call('ejecutarApertura')
        ->assertHasErrors('caja_nombre')
        ->assertSet('modalAperturaAbierto', true)
        ->assertSee('Esta caja ya tiene un turno abierto.');

    // El administrador cierra desde el POS la caja que abrió el vendedor.
    Livewire::test(Pos::class)
        ->set('pos_caja_id', (string) $this->caja2->id)
        ->call('abrirModalCierre')
        ->assertSet('modalCierreAbierto', true)
        ->assertSet('montoCierre', '0.00')
        ->call('cerrarTurnoActual')
        ->assertHasNoErrors();

    expect($turno->refresh()->estado)->toBe('cerrado')->and($turno->usuario_cierre_id)->toBe($this->admin->id);
});

test('el cierre con diferencia sin motivo no se pierde en el POS', function () {
    $turno = app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->principal, '100.00');

    Livewire::test(Pos::class)
        ->call('abrirModalCierre')
        ->set('montoCierre', '90.00')
        ->call('cerrarTurnoActual')
        ->assertHasErrors('observacionesCierre')
        ->assertSee('Escribe el motivo antes de cerrar.')
        ->assertSet('modalCierreAbierto', true);

    expect($turno->refresh()->estaAbierto())->toBeTrue();
});

test('el administrador cierra y abre cualquier caja desde Caja y arqueo', function () {
    $individual = app(GuardarCaja::class)->crear($this->admin, ['sucursal_id' => $this->central->id, 'nombre' => 'Caja 3', 'modalidad' => 'individual']);
    $vendedora = User::factory()->vendedor($this->central)->create(['name' => 'Ana Rojas', 'caja_id' => $individual->id]);
    $ajeno = app(AbrirTurnoCaja::class)->ejecutar($vendedora, $individual, '40.00');
    app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->principal, '0');

    Livewire::test(Arqueo::class)
        ->assertSee('Cerrar caja')->assertSee('Abrir caja')
        ->call('cerrarTurno', $ajeno->id)
        ->assertDispatched('abrir-cierre-caja', turnoId: $ajeno->id)
        ->call('abrirCaja', $this->sur->cajaPrincipal()->id)
        ->assertDispatched('abrir-apertura-caja', cajaId: $this->sur->cajaPrincipal()->id);

    // La caja individual de Ana la cierra el administrador, aunque no la abrió.
    Livewire::test(ControlCajaTopbar::class)
        ->dispatch('abrir-cierre-caja', turnoId: $ajeno->id)
        ->assertSet('modalCierreAbierto', true)
        ->assertSee('Cierre de turno y arqueo · Caja 3')
        ->assertSee('Ana Rojas')
        ->set('montoCierre', '40.00')
        ->call('ejecutarCierre')
        ->assertHasNoErrors()
        ->assertSet('modalCierreAbierto', false);

    expect($ajeno->refresh()->estado)->toBe('cerrado')->and($ajeno->usuario_cierre_id)->toBe($this->admin->id);

    // Abre la caja de otra sucursal sin cerrar la suya; la barra avisa de la otra abierta.
    Livewire::test(ControlCajaTopbar::class)
        ->dispatch('abrir-apertura-caja', cajaId: $this->sur->cajaPrincipal()->id)
        ->assertSee('Apertura de turno en Caja principal')
        ->assertSee('Sigues con 1 caja abierta')
        ->set('fondoInicial', '25.00')
        ->call('ejecutarApertura')
        ->assertHasNoErrors()
        ->assertSee('+1');

    expect($this->sur->cajaPrincipal()->turnoActivo?->monto_apertura)->toBe('25.00')
        ->and($this->principal->refresh()->turnoActivo)->not->toBeNull();
});

test('el vendedor no cierra ni abre cajas ajenas aunque envíe sus ids, y otra empresa no existe', function () {
    $ana = User::factory()->vendedor($this->central)->create(['caja_id' => $this->caja2->id]);
    $individual = app(GuardarCaja::class)->crear($this->admin, ['sucursal_id' => $this->central->id, 'nombre' => 'Caja 3', 'modalidad' => 'individual']);
    $beto = User::factory()->vendedor($this->central)->create(['caja_id' => $individual->id]);
    $deBeto = app(AbrirTurnoCaja::class)->ejecutar($beto, $individual, '0');
    [, $adminAjeno] = empresaConAdministrador();
    $this->actingAs($adminAjeno);
    $sucursalAjena = Sucursal::factory()->create(['empresa_id' => $adminAjeno->empresa_id]);
    $turnoAjeno = app(AbrirTurnoCaja::class)->ejecutar($adminAjeno, $sucursalAjena->cajaPrincipal(), '0');

    $this->actingAs($ana);
    Livewire::test(Arqueo::class)->call('cerrarTurno', $deBeto->id)->assertForbidden();
    Livewire::test(ControlCajaTopbar::class)
        ->dispatch('abrir-cierre-caja', turnoId: $deBeto->id)
        ->assertSet('modalCierreAbierto', false)
        ->assertSee('No tienes autorización para cerrar este turno.');
    Livewire::test(ControlCajaTopbar::class)
        ->dispatch('abrir-apertura-caja', cajaId: $individual->id)
        ->assertSet('modalAperturaAbierto', false);

    // El vendedor vende siempre con su caja: un pos_caja_id enviado no cambia la caja.
    Livewire::test(Pos::class)->set('pos_caja_id', (string) $individual->id)->assertDontSee('Caja 3');

    $this->actingAs($this->admin);
    Livewire::test(ControlCajaTopbar::class)
        ->dispatch('abrir-cierre-caja', turnoId: $turnoAjeno->id)
        ->assertSet('modalCierreAbierto', false)
        ->assertSee('No hay un turno abierto para cerrar.');
    Livewire::test(ControlCajaTopbar::class)
        ->dispatch('abrir-apertura-caja', cajaId: $sucursalAjena->cajaPrincipal()->id)
        ->assertSet('modalAperturaAbierto', false)
        ->assertSee('Esa caja ya no está disponible.');

    expect([$deBeto->refresh()->estaAbierto(), $turnoAjeno->refresh()->estaAbierto()])->toBe([true, true]);
});

test('el vendedor sigue con un solo turno abierto', function () {
    $ana = User::factory()->vendedor($this->central)->create(['caja_id' => $this->caja2->id]);
    app(AbrirTurnoCaja::class)->ejecutar($ana, $this->caja2, '0');

    // Aunque la reasignación esté bloqueada, la regla también se comprueba al abrir.
    $ana->forceFill(['caja_id' => $this->principal->id])->saveQuietly();

    expect(fn () => app(AbrirTurnoCaja::class)->ejecutar($ana->fresh(), $this->principal, '0'))
        ->toThrow(ValidationException::class, 'Ya tienes un turno abierto en otra caja.');
});

test('cerrar el turno de ayer y abrir otro es todo o nada', function () {
    $ayer = app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->principal, '100.00');
    $ayer->forceFill(['fecha_apertura' => now()->subDay()])->save();

    // Antes se cerraba el turno anterior y, si el fondo no era válido, quedaba la caja sin turno.
    Livewire::test(Pos::class)
        ->set('fondoInicial', 'abc')
        ->call('cerrarYCrearNuevoTurno')
        ->assertHasErrors('fondoInicial');

    expect($ayer->refresh()->estaAbierto())->toBeTrue();

    Livewire::test(Pos::class)
        ->set('fondoInicial', '80.00')
        ->call('cerrarYCrearNuevoTurno')
        ->assertHasNoErrors();

    expect($ayer->refresh()->estado)->toBe('cerrado')
        ->and($this->principal->refresh()->turnoActivo?->monto_apertura)->toBe('80.00');
});

test('sin sucursal elegida, la barra muestra la caja del administrador en la sucursal donde la abrió', function () {
    app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->sur->cajaPrincipal(), '0');

    Livewire::test(ControlCajaTopbar::class)
        ->assertSee('Cerrar caja')
        ->assertDontSee('Abrir caja');
});
