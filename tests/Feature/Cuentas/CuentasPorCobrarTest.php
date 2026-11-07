<?php

use App\Domain\Caja\Actions\AbrirTurnoCaja;
use App\Domain\Creditos\Actions\AnularCobro;
use App\Domain\Creditos\Actions\AnularCuentaCobrar;
use App\Domain\Creditos\Actions\DefinirCondicionesCredito;
use App\Domain\Creditos\Actions\RegistrarCobro;
use App\Domain\Creditos\Actions\RegistrarCuentaCobrar;
use App\Domain\Creditos\Consultas\CuentasPorCobrar;
use App\Domain\Creditos\Consultas\HistorialPagos;
use App\Domain\Reportes\Consultas\Cobros;
use App\Domain\Reportes\Consultas\Salidas;
use App\Domain\Reportes\Periodo;
use App\Domain\Ventas\Actions\RegistrarDevolucion;
use App\Livewire\Creditos\Cobrar as CreditoCobrar;
use App\Livewire\Creditos\Cuenta as CreditoCuenta;
use App\Livewire\Creditos\Historial as CreditoHistorial;
use App\Livewire\Creditos\Lista as CreditoLista;
use App\Livewire\Cuentas\Anular;
use App\Livewire\Reportes\Panel as ReportesPanel;
use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\CobroAplicacion;
use App\Models\CuentaCobrar;
use App\Models\MovimientoCaja;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaCuota;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Cuentas por cobrar (docs/MoziStock_Cuentas_por_Cobrar_y_Pagar.md, parte A y § 24.1):
 * un cobro es un único ingreso por medio, en su fecha real, distribuido sin duplicar saldos.
 * Semana de prueba en La Paz: lunes 19 a jueves 22 de octubre de 2026.
 */

function cobrar(User $actor, array $datos): Cobro
{
    return app(RegistrarCobro::class)->ejecutar($actor, $datos + [
        'sucursal_id' => Sucursal::query()->orderBy('id')->value('id'), 'metodo' => 'efectivo', 'monto' => null, 'efectivo' => null, 'electronico' => null,
        'modo' => 'automatico', 'externo' => true, 'clave' => (string) Str::uuid(),
    ]);
}

function ventaACredito(object $t, string $cantidad, string $inicial, ?string $vencimiento = null): Venta
{
    $venta = confirmarVenta($t->admin, crearPedido($t->admin, $t->matriz,
        [['producto_id' => $t->producto->id, 'cantidad' => $cantidad]], ['cliente_id' => $t->cliente->id]),
        ['metodo' => 'efectivo', 'en_cuotas' => true, 'cuota_inicial' => $inicial]);

    if ($vencimiento !== null) {
        app(DefinirCondicionesCredito::class)->ejecutar($t->admin, $venta, ['tipo' => 'unico', 'vencimiento' => $vencimiento]);
    }

    return $venta->refresh();
}

function cuentaManual(object $t, string $importe, ?string $vencimiento = null, string $fecha = '2026-09-01'): CuentaCobrar
{
    return app(RegistrarCuentaCobrar::class)->ejecutar($t->admin, [
        'cliente_id' => $t->cliente->id, 'sucursal_id' => $t->matriz->id, 'concepto' => 'Saldo inicial anterior a MoziStock',
        'fecha' => $fecha, 'vencimiento' => $vencimiento, 'importe' => $importe, 'nota' => null, 'clave' => (string) Str::uuid(),
    ]);
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->producto = productoDe($this->empresa, ['precio_consumidor' => '20.00']);
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 08:00', 'America/La_Paz'));
    cargarStock($this->admin, $this->matriz, $this->producto, '100');
    $this->cliente = new Cliente(['nombre' => 'Carlos Mendoza', 'documento' => '4455667']);
    $this->cliente->save();
});

test('the mandatory example with an installment plan: 50 Monday, 30 Tuesday, 120 Thursday, never 200 on Monday', function () {
    $venta = ventaACredito($this, '10', '50');
    app(DefinirCondicionesCredito::class)->ejecutar($this->admin, $venta, ['tipo' => 'cuotas', 'cuotas' => [
        ['vencimiento' => '2026-11-19', 'monto' => '50'], ['vencimiento' => '2026-12-19', 'monto' => '50'], ['vencimiento' => '2027-01-19', 'monto' => '50'],
    ]]);

    $this->travelTo(CarbonImmutable::parse('2026-10-20 11:00', 'America/La_Paz'));
    cobrar($this->admin, ['venta_id' => $venta->id, 'monto' => '30']);
    expect(VentaCuota::query()->orderBy('numero')->pluck('saldo')->all())->toBe(['20.00', '50.00', '50.00']);

    $this->travelTo(CarbonImmutable::parse('2026-10-22 17:00', 'America/La_Paz'));
    cobrar($this->admin, ['venta_id' => $venta->id, 'metodo' => 'electronico', 'monto' => '120']);

    $dia = fn (string $fecha) => Cobros::resumen($this->empresa->id, Periodo::dia($fecha))['total'];
    expect($dia('2026-10-19'))->toBe('50.00')
        ->and($dia('2026-10-20'))->toBe('30.00')
        ->and($dia('2026-10-21'))->toBe('0.00')
        ->and($dia('2026-10-22'))->toBe('120.00')
        ->and($venta->refresh()->saldo_pendiente)->toBe('0.00')
        ->and(VentaCuota::query()->sum('saldo'))->toEqual('0.00')
        ->and(MovimientoCaja::query()->where('origen_tipo', 'cobro')->count())->toBe(2);
});

test('a client collection goes to the oldest due dates first and is recorded once with its split', function () {
    $ventaA = ventaACredito($this, '10', '50', '2026-10-30');   // saldo 150
    $ventaB = ventaACredito($this, '5', '0', '2026-10-25');     // saldo 100
    $manual = cuentaManual($this, '40', '2026-10-20');

    $cobro = cobrar($this->admin, ['cliente_id' => $this->cliente->id, 'metodo' => 'mixto', 'efectivo' => '100', 'electronico' => '60']);

    $aplicado = CobroAplicacion::query()->orderBy('id')->get()
        ->map(fn ($a) => [$a->venta_id ?? 'manual', $a->monto, $a->monto_efectivo, $a->monto_electronico])->all();
    expect($aplicado)->toBe([
        ['manual', '40.00', '40.00', '0.00'],
        [$ventaB->id, '100.00', '60.00', '40.00'],
        [$ventaA->id, '20.00', '0.00', '20.00'],
    ])
        ->and($manual->refresh()->saldo_pendiente)->toBe('0.00')
        ->and($ventaB->refresh()->saldo_pendiente)->toBe('0.00')
        ->and($ventaA->refresh()->saldo_pendiente)->toBe('130.00')
        ->and(Cobro::query()->count())->toBe(1)
        ->and(MovimientoCaja::query()->where('origen_tipo', 'cobro')->where('origen_id', $cobro->id)->pluck('monto', 'metodo')->all())
        ->toBe(['efectivo' => '100.00', 'electronico' => '60.00'])
        ->and(Cobros::detalle($this->empresa->id, Periodo::dia('2026-10-19'))->get()->where('origen', 'cobro')->sum('total'))->toEqual(160);

    // Nunca se aplica más de lo adeudado ni se crea saldo a favor.
    expect(fn () => cobrar($this->admin, ['cliente_id' => $this->cliente->id, 'monto' => '130.01']))->toThrow(ValidationException::class);
});

test('manual application assigns exactly the chosen amounts', function () {
    $venta = ventaACredito($this, '10', '50');
    $manual = cuentaManual($this, '40');
    $base = ['cliente_id' => $this->cliente->id, 'modo' => 'manual', 'monto' => '60'];

    expect(fn () => cobrar($this->admin, $base + ['asignaciones' => ['venta:'.$venta->id => '30', 'cuenta:'.$manual->id => '20.01']]))
        ->toThrow(ValidationException::class)
        ->and(fn () => cobrar($this->admin, $base + ['asignaciones' => ['cuenta:'.$manual->id => '41', 'venta:'.$venta->id => '19']]))
        ->toThrow(ValidationException::class);

    cobrar($this->admin, $base + ['asignaciones' => ['venta:'.$venta->id => '20', 'cuenta:'.$manual->id => '40']]);

    expect($venta->refresh()->saldo_pendiente)->toBe('130.00')
        ->and($manual->refresh()->saldo_pendiente)->toBe('0.00')
        ->and(Cobro::query()->count())->toBe(1);
});

test('cash through the till needs an open shift and only an administrator records it as external', function () {
    $venta = ventaACredito($this, '10', '50');
    $vendedor = User::factory()->vendedor($this->matriz)->create();
    $this->actingAs($vendedor);

    expect(fn () => cobrar($vendedor, ['venta_id' => $venta->id, 'monto' => '10', 'externo' => false]))->toThrow(ValidationException::class)
        ->and(fn () => cobrar($vendedor, ['venta_id' => $venta->id, 'monto' => '10', 'externo' => true]))->toThrow(ValidationException::class);

    $turno = app(AbrirTurnoCaja::class)->ejecutar($vendedor, $vendedor->caja, '0');
    $porCaja = cobrar($vendedor, ['venta_id' => $venta->id, 'monto' => '10', 'externo' => false]);
    $this->actingAs($this->admin);
    $externo = cobrar($this->admin, ['venta_id' => $venta->id, 'monto' => '15', 'externo' => true]);

    expect($porCaja->turno_caja_id)->toBe($turno->id)
        ->and($externo->externo)->toBeTrue()->and($externo->turno_caja_id)->toBeNull()
        ->and($turno->refresh()->calcularArqueo()['ingresos_efectivo'])->toBe('10.00')
        ->and(MovimientoCaja::query()->where('origen_tipo', 'cobro')->where('origen_id', $externo->id)->value('turno_caja_id'))->toBeNull();
});

test('retrying the same key does not collect twice and other companies cannot reach the debt', function () {
    $venta = ventaACredito($this, '10', '50');
    $clave = (string) Str::uuid();

    $primero = cobrar($this->admin, ['venta_id' => $venta->id, 'monto' => '30', 'clave' => $clave]);
    $segundo = cobrar($this->admin, ['venta_id' => $venta->id, 'monto' => '30', 'clave' => $clave]);

    expect($segundo->id)->toBe($primero->id)
        ->and($venta->refresh()->saldo_pendiente)->toBe('120.00')
        ->and(MovimientoCaja::query()->where('origen_tipo', 'cobro')->count())->toBe(1);

    [, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    expect(fn () => cobrar($adminB, ['venta_id' => $venta->id, 'monto' => '1']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => cobrar($adminB, ['cliente_id' => $this->cliente->id, 'monto' => '1']))->toThrow(ModelNotFoundException::class)
        ->and(CuentasPorCobrar::consulta((int) $adminB->empresa_id)->count())->toBe(0);
});

test('a manual receivable creates no sale, stock or money movement and only admins register it', function () {
    $ventas = Venta::query()->count();
    $movimientos = MovimientoCaja::query()->count();
    $cuenta = cuentaManual($this, '75.50', '2026-11-30', '2025-12-15');

    expect(Venta::query()->count())->toBe($ventas)
        ->and(MovimientoCaja::query()->count())->toBe($movimientos)
        ->and($cuenta->codigo())->toBe('CXC-00001')
        ->and(CuentasPorCobrar::consulta($this->empresa->id)->get()->firstWhere('tipo', 'cuenta')->saldo)->toEqual('75.50');

    $vendedor = User::factory()->vendedor($this->matriz)->create();
    $this->actingAs($vendedor);
    expect(fn () => app(RegistrarCuentaCobrar::class)->ejecutar($vendedor, [
        'cliente_id' => $this->cliente->id, 'sucursal_id' => null, 'concepto' => 'X', 'fecha' => '2026-10-01',
        'vencimiento' => null, 'importe' => '10', 'nota' => null, 'clave' => (string) Str::uuid(),
    ]))->toThrow(AuthorizationException::class);
});

test('overdue needs a balance and a past due date; summary splits overdue and upcoming', function () {
    $vencida = ventaACredito($this, '10', '50', '2026-10-20');   // saldo 150
    ventaACredito($this, '5', '0', '2026-11-30');                 // saldo 100
    $this->travelTo(CarbonImmutable::parse('2026-10-22 09:00', 'America/La_Paz'));

    expect(CuentasPorCobrar::resumen($this->empresa->id))->toBe(['pendiente' => '250.00', 'vencido' => '150.00', 'por_vencer' => '100.00', 'clientes' => 1])
        ->and(CuentasPorCobrar::consulta($this->empresa->id, 'vencidas')->pluck('id')->all())->toBe([$vencida->id])
        ->and(CuentasPorCobrar::consulta($this->empresa->id, 'pendientes')->count())->toBe(1);

    cobrar($this->admin, ['venta_id' => $vencida->id, 'monto' => '150']);

    expect(CuentasPorCobrar::consulta($this->empresa->id, 'vencidas')->count())->toBe(0)
        ->and(CuentasPorCobrar::estado(CuentasPorCobrar::consulta($this->empresa->id, 'pagadas')->sole()))->toBe('pagada');
});

test('a plan must add up to the balance, cannot be replaced after a collection and returns reduce the last installments', function () {
    $venta = ventaACredito($this, '10', '50');
    $definir = fn (array $cuotas) => app(DefinirCondicionesCredito::class)->ejecutar($this->admin, $venta, ['tipo' => 'cuotas', 'cuotas' => $cuotas]);

    expect(fn () => $definir([['vencimiento' => '2026-11-01', 'monto' => '100'], ['vencimiento' => '2026-12-01', 'monto' => '49.99']]))
        ->toThrow(ValidationException::class)
        ->and(fn () => $definir([['vencimiento' => '2026-10-18', 'monto' => '150']]))->toThrow(ValidationException::class);

    $definir([['vencimiento' => '2026-11-01', 'monto' => '100'], ['vencimiento' => '2026-12-01', 'monto' => '50']]);
    cobrar($this->admin, ['venta_id' => $venta->id, 'monto' => '10']);

    expect(fn () => $definir([['vencimiento' => '2026-11-01', 'monto' => '140']]))->toThrow(ValidationException::class);

    // Devolución de 3 unidades (Bs 60): sale de la última cuota y luego de la anterior.
    $item = DB::table('venta_items')->where('venta_id', $venta->id)->value('id');
    app(RegistrarDevolucion::class)->ejecutar($this->admin, $venta->refresh(), [
        'lineas' => [$item => ['cantidad' => '3', 'reingresa' => true]], 'motivo' => 'Producto defectuoso',
        'metodo' => 'efectivo', 'efectivo' => null, 'electronico' => null, 'clave' => (string) Str::uuid(),
    ]);

    expect(VentaCuota::query()->orderBy('numero')->pluck('saldo')->all())->toBe(['80.00', '0.00'])
        ->and($venta->refresh()->saldo_pendiente)->toBe('80.00');
});

test('the receivables screens register a manual account, a plan and collections from each page', function () {
    $venta = ventaACredito($this, '10', '50');   // saldo 150

    Livewire::test(CreditoLista::class)->call('nuevaCuenta')
        ->set('cliente_id', (string) $this->cliente->id)->set('concepto', 'Saldo anterior')->set('fecha', '2026-09-01')
        ->set('importe', '100')->call('registrarCuenta')->assertHasNoErrors()->assertRedirect();
    $cuenta = CuentaCobrar::query()->sole();

    Livewire::test(CreditoLista::class)
        ->assertSee('CXC-00001')->assertSee($venta->codigo())->assertSee('Bs 250,00')
        ->set('estado', 'pendientes')->assertSee('CXC-00001');

    Livewire::test(CreditoHistorial::class, ['venta' => $venta])
        ->call('editarCondiciones')->set('condicion', 'cuotas')->set('planCantidad', '3')->set('planPrimera', '2026-11-19')
        ->call('generarPlan')->call('guardarCondiciones')->assertHasNoErrors()
        ->assertSee('Condiciones del crédito guardadas.')->assertSee('1 / 3');

    expect($venta->cuotas()->pluck('monto')->all())->toBe(['50.00', '50.00', '50.00']);

    Livewire::test(CreditoCuenta::class, ['cuenta' => $cuenta])
        ->set('monto', '40')->call('registrar')->assertHasNoErrors()->assertSee('Cobro COB-00001 de Bs 40,00 registrado.');

    Livewire::test(CreditoCobrar::class)->set('cliente', (string) $this->cliente->id)
        ->assertSee('Saldo anterior')->set('monto', '80')->call('registrar')->assertHasNoErrors();

    // Automático: la cuenta manual (fecha más antigua) primero y luego la cuota 1.
    expect($cuenta->refresh()->saldo_pendiente)->toBe('0.00')
        ->and($venta->cuotas()->pluck('saldo')->all())->toBe(['30.00', '50.00', '50.00'])
        ->and(Cobros::resumen($this->empresa->id, Periodo::dia('2026-10-19'))['total'])->toBe('170.00');
});

test('a credit sale takes an optional due date and both exports list sales and manual accounts', function () {
    $pedido = crearPedido($this->admin, $this->matriz, [['producto_id' => $this->producto->id, 'cantidad' => '5']], ['cliente_id' => $this->cliente->id]);
    $pago = ['metodo' => 'efectivo', 'en_cuotas' => true, 'cuota_inicial' => '20'];

    expect(fn () => confirmarVenta($this->admin, $pedido, $pago + ['vencimiento' => '2026-10-18']))->toThrow(ValidationException::class);

    $venta = confirmarVenta($this->admin, $pedido, $pago + ['vencimiento' => '2026-11-15']);
    $cuenta = cuentaManual($this, '75.00');

    expect($venta->vencimiento->format('Y-m-d'))->toBe('2026-11-15')
        ->and($venta->saldo_pendiente)->toBe('80.00');

    $csv = $this->get(route('creditos.exportar'))->assertOk()->streamedContent();
    expect($csv)->toContain($venta->codigo())->toContain('15/11/2026')->toContain($cuenta->codigo())->toContain('Saldo inicial anterior a MoziStock');

    $reporte = $this->get(route('reportes.exportar', ['pestana' => 'creditos']))->assertOk()->streamedContent();
    expect($reporte)->toContain($venta->codigo())->toContain($cuenta->codigo())->toContain('75.00');

    $porCliente = CuentasPorCobrar::porCliente($this->empresa->id)->sole();
    expect((int) $porCliente->cuentas)->toBe(2)->and((string) $porCliente->saldo)->toBe('155.00');
    expect($this->get(route('reportes.exportar', ['pestana' => 'creditos', 'agrupar' => 'cliente']))->assertOk()->streamedContent())
        ->toContain('Carlos Mendoza')->toContain('155.00')->not->toContain($venta->codigo());
    Livewire::test(ReportesPanel::class)->set('pestana', 'creditos')->set('agrupar', 'cliente')
        ->assertSee('Carlos Mendoza')->assertSee('Bs 155,00')->assertDontSee($cuenta->codigo());
});

test('an admin annuls a collection: balances come back and the money goes out the day it is annulled, old days unchanged', function () {
    $venta = ventaACredito($this, '10', '50');
    $cuenta = cuentaManual($this, '75.00');
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'America/La_Paz'));
    // Automático: primero la cuenta manual (fecha 01/09), luego la venta.
    $cobro = cobrar($this->admin, ['cliente_id' => $this->cliente->id, 'monto' => '180']);
    $vendedor = User::factory()->vendedor($this->matriz)->create();

    expect(fn () => app(AnularCobro::class)->ejecutar($vendedor, $cobro, ['motivo' => 'Registrado por error']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(AnularCobro::class)->ejecutar($this->admin, $cobro, ['motivo' => 'no']))->toThrow(ValidationException::class);

    $this->travelTo(CarbonImmutable::parse('2026-10-22 11:00', 'America/La_Paz'));
    $datos = ['motivo' => 'Se registró dos veces', 'externo' => true];
    app(AnularCobro::class)->ejecutar($this->admin, $cobro, $datos);
    app(AnularCobro::class)->ejecutar($this->admin, $cobro->refresh(), $datos);
    $historial = HistorialPagos::de($venta->refresh());

    expect($venta->saldo_pendiente)->toBe('150.00')
        ->and($cuenta->refresh()->saldo_pendiente)->toBe('75.00')
        ->and($cobro->refresh()->estaAnulado())->toBeTrue()
        ->and(MovimientoCaja::query()->where('origen_tipo', 'anulacion_cobro')->sole()->only(['sentido', 'metodo', 'monto']))
        ->toBe(['sentido' => 'egreso', 'metodo' => 'efectivo', 'monto' => '180.00'])
        ->and(Cobros::resumen($this->empresa->id, Periodo::dia('2026-10-20'))['total'])->toBe('180.00')
        ->and(Cobros::concepto(Cobros::detalle($this->empresa->id, Periodo::dia('2026-10-20'))->sole()))->toBe('Abono (anulado)')
        ->and(Cobros::resumen($this->empresa->id, Periodo::dia('2026-10-22'))['egresos'])->toBe('180.00')
        ->and(Salidas::describir(Salidas::detalle($this->empresa->id, Periodo::dia('2026-10-22'))->sole())['concepto'])->toBe('Cobro anulado')
        ->and(collect($historial)->whereNotNull('anulado'))->toHaveCount(1)
        ->and(HistorialPagos::total($historial, 'monto'))->toBe('50.00')
        ->and(CuentasPorCobrar::resumen($this->empresa->id)['pendiente'])->toBe('225.00');
});

test('annulment is refused after a later return or plan, and a manual account needs its collections annulled first', function () {
    $motivo = ['motivo' => 'Error de digitación', 'externo' => true];
    $conPlan = ventaACredito($this, '10', '50');
    $cobroAntes = cobrar($this->admin, ['venta_id' => $conPlan->id, 'monto' => '30']);
    app(DefinirCondicionesCredito::class)->ejecutar($this->admin, $conPlan, ['tipo' => 'cuotas', 'cuotas' => [['vencimiento' => '2026-11-01', 'monto' => '120']]]);
    $cobroCuota = cobrar($this->admin, ['venta_id' => $conPlan->id, 'monto' => '20']);

    expect(fn () => app(AnularCobro::class)->ejecutar($this->admin, $cobroAntes, $motivo))->toThrow(ValidationException::class);

    // Anular el cobro de la cuota la repone, pero el plan con historial de cobros no se reemplaza.
    app(AnularCobro::class)->ejecutar($this->admin, $cobroCuota, $motivo);
    expect(VentaCuota::query()->where('venta_id', $conPlan->id)->sole()->saldo)->toBe('120.00')
        ->and(fn () => app(DefinirCondicionesCredito::class)->ejecutar($this->admin, $conPlan, ['tipo' => 'unico', 'vencimiento' => '2026-12-01']))
        ->toThrow(ValidationException::class);

    $devuelta = ventaACredito($this, '10', '50');
    $cobroDevuelta = cobrar($this->admin, ['venta_id' => $devuelta->id, 'monto' => '100']);
    $item = DB::table('venta_items')->where('venta_id', $devuelta->id)->value('id');
    app(RegistrarDevolucion::class)->ejecutar($this->admin, $devuelta->refresh(), [
        'lineas' => [$item => ['cantidad' => '1', 'reingresa' => true]], 'motivo' => 'Producto defectuoso',
        'metodo' => 'efectivo', 'efectivo' => null, 'electronico' => null, 'clave' => (string) Str::uuid(),
    ]);
    expect(fn () => app(AnularCobro::class)->ejecutar($this->admin, $cobroDevuelta, $motivo))->toThrow(ValidationException::class);

    $cuenta = cuentaManual($this, '75.00');
    $cobroCuenta = cobrar($this->admin, ['cuenta_id' => $cuenta->id, 'monto' => '25']);
    expect(fn () => app(AnularCuentaCobrar::class)->ejecutar($this->admin, $cuenta, $motivo))->toThrow(ValidationException::class);

    app(AnularCobro::class)->ejecutar($this->admin, $cobroCuenta, $motivo);
    app(AnularCuentaCobrar::class)->ejecutar($this->admin, $cuenta->refresh(), $motivo);

    expect($cuenta->refresh()->estaAnulado())->toBeTrue()
        ->and(CuentasPorCobrar::consulta($this->empresa->id, 'anuladas')->pluck('id')->all())->toBe([$cuenta->id])
        ->and(CuentasPorCobrar::consulta($this->empresa->id)->where('tipo', 'cuenta')->count())->toBe(0)
        ->and(fn () => cobrar($this->admin, ['cuenta_id' => $cuenta->id, 'monto' => '10']))->toThrow(ValidationException::class);
});

test('the annul modal asks for a reason, offers the till and only administrators can open it', function () {
    $venta = ventaACredito($this, '10', '50');
    $cobro = cobrar($this->admin, ['venta_id' => $venta->id, 'monto' => '40']);
    $volver = route('creditos.historial', $venta);

    Livewire::test(CreditoHistorial::class, ['venta' => $venta])->assertSee('Anular cobro');
    Livewire::test(Anular::class, ['volver' => $volver])
        ->call('abrir', 'cobro', $cobro->id)->assertSet('caja', 'externo')->assertSee('Anular cobro '.$cobro->codigo())
        ->call('confirmar')->assertHasErrors('motivo')
        ->set('motivo', 'El cliente no pagó')->call('confirmar')->assertHasNoErrors()->assertRedirect($volver);

    expect($venta->refresh()->saldo_pendiente)->toBe('150.00');
    $this->get($volver)->assertOk()->assertSee('Anulado el');

    $vendedor = User::factory()->vendedor($this->matriz)->create();
    Livewire::actingAs($vendedor)->test(Anular::class, ['volver' => $volver])->call('abrir', 'cobro', $cobro->id)->assertForbidden();
});
