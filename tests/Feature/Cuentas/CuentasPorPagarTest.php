<?php

use App\Domain\Caja\Actions\AbrirTurnoCaja;
use App\Domain\Compras\Actions\RegistrarCompra;
use App\Domain\CuentasPagar\Actions\AnularObligacion;
use App\Domain\CuentasPagar\Actions\AnularPagoCuenta;
use App\Domain\CuentasPagar\Actions\RegistrarObligacion;
use App\Domain\CuentasPagar\Actions\RegistrarPagoCuenta;
use App\Domain\CuentasPagar\Consultas\CuentasPorPagar;
use App\Domain\Egresos\Actions\RegistrarEgreso;
use App\Domain\Reportes\Consultas\Cobros;
use App\Domain\Reportes\Consultas\Salidas;
use App\Domain\Reportes\Periodo;
use App\Livewire\Compras\NuevaCompra as NuevaCompraPantalla;
use App\Livewire\CuentasPagar\Detalle as CuentaPagarDetalle;
use App\Livewire\CuentasPagar\Lista as CuentasPagarLista;
use App\Livewire\CuentasPagar\NuevaObligacion;
use App\Livewire\Egresos\Panel as EgresosPanel;
use App\Models\Caja;
use App\Models\CategoriaEgreso;
use App\Models\Compra;
use App\Models\CuentaPagar;
use App\Models\Egreso;
use App\Models\MovimientoCaja;
use App\Models\PagoCuentaPagar;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Cuentas por pagar (docs/MoziStock_Cuentas_por_Cobrar_y_Pagar.md, parte B y § 20): la deuda
 * nace de la compra o del egreso con saldo, cada pago se aplica a una sola cuenta y el dinero
 * sale una sola vez, en su fecha real, sin crear otro egreso ni otra compra.
 */

function compraCon(object $t, array $extra = []): Compra
{
    return app(RegistrarCompra::class)->ejecutar($t->admin, $extra + [
        'sucursal_id' => $t->sucursal->id, 'proveedor_id' => $t->proveedor->id, 'referencia' => 'Factura 77',
        'lineas' => [$t->arroz->id => ['cantidad' => '100', 'costo' => '50']],   // Bs 5.000
        'condicion' => 'contado', 'metodo' => 'efectivo', 'efectivo' => null, 'electronico' => null, 'externo' => true,
        'clave' => (string) Str::uuid(),
    ]);
}

function egresoCon(object $t, array $extra = []): Egreso
{
    return app(RegistrarEgreso::class)->ejecutar($t->admin, $extra + [
        'sucursal_id' => $t->sucursal->id, 'categoria_id' => $t->categoria->id, 'descripcion' => 'Alquiler de octubre',
        'proveedor_id' => $t->proveedor->id, 'monto' => '2000', 'condicion' => 'pagado', 'metodo' => 'efectivo',
        'externo' => true, 'clave' => (string) Str::uuid(),
    ]);
}

function pagarCuenta(User $actor, CuentaPagar $cuenta, array $datos): PagoCuentaPagar
{
    return app(RegistrarPagoCuenta::class)->ejecutar($actor, $cuenta, $datos + [
        'sucursal_id' => $cuenta->sucursal_id, 'metodo' => 'electronico', 'monto' => null, 'efectivo' => null, 'electronico' => null,
        'externo' => true, 'clave' => (string) Str::uuid(),
    ]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'America/La_Paz'));
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->arroz = productoDe($this->empresa, ['nombre' => 'Arroz 5kg']);
    $this->proveedor = new Proveedor(['nombre' => 'Distribuidora Oriente']);
    $this->proveedor->empresa_id = $this->empresa->id;
    $this->proveedor->save();
    $this->categoria = new CategoriaEgreso(['nombre' => 'Alquiler']);
    $this->categoria->empresa_id = $this->empresa->id;
    $this->categoria->save();
    $this->actingAs($this->admin);
});

test('cash, credit and partial purchases add stock and owe only the unpaid balance', function () {
    $contado = compraCon($this);
    $credito = compraCon($this, ['condicion' => 'credito', 'vencimiento' => '2026-11-19']);
    $parcial = compraCon($this, ['condicion' => 'parcial', 'pagado' => '2000', 'vencimiento' => '2026-11-19',
        'metodo' => 'mixto', 'efectivo' => '1500', 'electronico' => '500']);

    expect(stockDe($this->sucursal, $this->arroz))->toBe('300.000')
        ->and($contado->cuentaPagar)->toBeNull()
        ->and($credito->cuentaPagar->saldo_pendiente)->toBe('5000.00')
        ->and($parcial->cuentaPagar->only(['total_documento', 'pagado_origen', 'saldo_pendiente']))
        ->toBe(['total_documento' => '5000.00', 'pagado_origen' => '2000.00', 'saldo_pendiente' => '3000.00'])
        ->and(Cobros::resumen($this->empresa->id, Periodo::dia('2026-10-19'))['egresos'])->toBe('7000.00')
        ->and(MovimientoCaja::query()->where('origen_tipo', 'compra')->where('origen_id', $credito->id)->count())->toBe(0)
        ->and(Egreso::query()->count())->toBe(0);

    expect(fn () => compraCon($this, ['condicion' => 'credito']))->toThrow(ValidationException::class)
        ->and(fn () => compraCon($this, ['condicion' => 'parcial', 'pagado' => '5000', 'vencimiento' => '2026-11-19']))->toThrow(ValidationException::class)
        ->and(fn () => compraCon($this, ['proveedor_id' => null]))->toThrow(ValidationException::class);
});

test('paid, partial and pending expenses: mixed split, beneficiary and due date required with a balance', function () {
    $pagado = egresoCon($this, ['metodo' => 'mixto', 'efectivo' => '1200', 'electronico' => '800']);
    $parcial = egresoCon($this, ['condicion' => 'parcial', 'pagado' => '1000', 'vencimiento' => '2026-10-31']);
    $pendiente = egresoCon($this, ['condicion' => 'pendiente', 'vencimiento' => '2026-10-31', 'metodo' => null]);

    expect($pagado->cuentaPagar)->toBeNull()
        ->and(MovimientoCaja::query()->where('origen_tipo', 'egreso')->where('origen_id', $pagado->id)->pluck('monto', 'metodo')->all())
        ->toBe(['efectivo' => '1200.00', 'electronico' => '800.00'])
        ->and($parcial->cuentaPagar->saldo_pendiente)->toBe('1000.00')
        ->and($pendiente->cuentaPagar->saldo_pendiente)->toBe('2000.00')
        ->and(Cobros::resumen($this->empresa->id, Periodo::dia('2026-10-19'))['egresos'])->toBe('3000.00');

    expect(fn () => egresoCon($this, ['condicion' => 'pendiente', 'metodo' => null]))->toThrow(ValidationException::class)
        ->and(fn () => egresoCon($this, ['condicion' => 'pendiente', 'vencimiento' => '2026-10-31', 'proveedor_id' => null]))->toThrow(ValidationException::class);
});

test('later payments reduce only their own debt, never create an expense or purchase and leave money once', function () {
    $compra = compraCon($this, ['condicion' => 'parcial', 'pagado' => '2000', 'vencimiento' => '2026-11-19']);
    $cuenta = $compra->cuentaPagar;
    $egresos = Egreso::query()->count();
    $compras = Compra::query()->count();

    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'America/La_Paz'));
    $clave = (string) Str::uuid();
    $pago = pagarCuenta($this->admin, $cuenta, ['monto' => '1000', 'clave' => $clave]);
    pagarCuenta($this->admin, $cuenta, ['monto' => '1000', 'clave' => $clave]);   // reintento

    expect($cuenta->refresh()->saldo_pendiente)->toBe('2000.00')
        ->and($cuenta->estado())->toBe('parcial')
        ->and(Egreso::query()->count())->toBe($egresos)
        ->and(Compra::query()->count())->toBe($compras)
        ->and(PagoCuentaPagar::query()->count())->toBe(1)
        ->and(Cobros::resumen($this->empresa->id, Periodo::dia('2026-10-21'))['egresos'])->toBe('1000.00')
        ->and(Salidas::describir(Salidas::detalle($this->empresa->id, Periodo::dia('2026-10-21'))->sole())['referencia'])->toBe($cuenta->codigo());

    expect(fn () => pagarCuenta($this->admin, $cuenta, ['monto' => '2000.01']))->toThrow(ValidationException::class);

    pagarCuenta($this->admin, $cuenta, ['metodo' => 'mixto', 'efectivo' => '500', 'electronico' => '1500']);
    expect($cuenta->refresh()->saldo_pendiente)->toBe('0.00')
        ->and($cuenta->estado())->toBe('pagada')
        ->and(MovimientoCaja::query()->where('origen_tipo', 'pago_cxp')->count())->toBe(3);
});

test('a manual obligation needs a due date, keeps what was paid before and moves no stock nor money', function () {
    $datos = ['proveedor_id' => $this->proveedor->id, 'sucursal_id' => $this->sucursal->id, 'concepto' => 'Préstamo anterior',
        'fecha' => '2026-01-15', 'vencimiento' => '2026-10-01', 'importe' => '8000', 'pagado' => '3000',
        'nota' => null, 'clave' => (string) Str::uuid()];

    expect(fn () => app(RegistrarObligacion::class)->ejecutar($this->admin, ['vencimiento' => null] + $datos))->toThrow(ValidationException::class)
        ->and(fn () => app(RegistrarObligacion::class)->ejecutar($this->admin, ['pagado' => '8000'] + $datos))->toThrow(ValidationException::class);

    $cuenta = app(RegistrarObligacion::class)->ejecutar($this->admin, $datos);

    expect($cuenta->saldo_pendiente)->toBe('5000.00')
        ->and($cuenta->estado())->toBe('vencida')
        ->and(MovimientoCaja::query()->count())->toBe(0)
        ->and(Egreso::query()->count())->toBe(0)
        ->and(CuentasPorPagar::resumen($this->empresa->id))->toBe(['pendiente' => '5000.00', 'vencido' => '5000.00', 'por_vencer' => '0.00', 'abiertas' => 1])
        ->and(CuentasPorPagar::consulta($this->empresa->id, 'vencidas', origen: 'manual')->count())->toBe(1);
});

test('payables are only for administrators of the same company', function () {
    $cuenta = compraCon($this, ['condicion' => 'credito', 'vencimiento' => '2026-11-19'])->cuentaPagar;

    $vendedor = User::factory()->vendedor($this->sucursal)->create();
    $this->actingAs($vendedor);
    expect(fn () => pagarCuenta($vendedor, $cuenta, ['monto' => '10']))->toThrow(AuthorizationException::class);

    [, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    expect(fn () => pagarCuenta($adminB, $cuenta, ['monto' => '10']))->toThrow(AuthorizationException::class)
        ->and(fn () => CuentaPagar::query()->findOrFail($cuenta->id))->toThrow(ModelNotFoundException::class)
        ->and(CuentasPorPagar::consulta((int) $adminB->empresa_id)->count())->toBe(0);
});

test('the payables screens list, pay from the detail and register a manual obligation', function () {
    $compra = compraCon($this, ['condicion' => 'credito', 'vencimiento' => '2026-11-19']);
    egresoCon($this, ['descripcion' => 'Luz de octubre', 'condicion' => 'pendiente', 'vencimiento' => '2026-10-31', 'metodo' => null]);

    Livewire::test(CuentasPagarLista::class)
        ->assertSee('CXP-00001')->assertSee('COM-00001')->assertSee('Luz de octubre')->assertSee('Bs 7.000,00')
        ->set('origen', 'egreso')->assertDontSee('COM-00001');

    Livewire::test(CuentaPagarDetalle::class, ['cuenta' => $compra->cuentaPagar])
        ->assertSet('caja', 'externo')
        ->set('monto', '1500')->call('registrar')->assertHasNoErrors()
        ->assertSee('Pago de Bs 1.500,00 registrado.')->assertSee('Bs 3.500,00');

    Livewire::test(NuevaObligacion::class)
        ->set('proveedor_id', (string) $this->proveedor->id)->set('concepto', 'Impuesto anual')
        ->set('importe', '900')->call('guardar')->assertHasErrors('vencimiento')
        ->set('vencimiento', '2026-12-31')->call('guardar')->assertHasNoErrors()->assertRedirect();

    expect(CuentaPagar::query()->where('origen', 'manual')->sole()->saldo_pendiente)->toBe('900.00');

    $this->actingAs(User::factory()->vendedor($this->sucursal)->create());
    $this->get(route('cuentas-pagar.index'))->assertForbidden();
    $this->get(route('cuentas-pagar.ver', $compra->cuentaPagar))->assertForbidden();
});

test('the purchase and expense screens open a payable when a balance remains', function () {
    Livewire::test(NuevaCompraPantalla::class)
        ->set('proveedor_id', (string) $this->proveedor->id)
        ->call('agregarProducto', $this->arroz->id)->set('lineas.0.costo', '40')
        ->set('condicion', 'credito')->call('revisar')->assertHasErrors('vencimiento')
        ->set('vencimiento', '2026-11-30')->call('revisar')->call('confirmar')->assertHasNoErrors();

    Livewire::test(EgresosPanel::class)->call('nuevoEgreso')
        ->set('categoria_id', (string) $this->categoria->id)->set('descripcion', 'Alquiler')->set('monto', '500')
        ->set('proveedor_id', (string) $this->proveedor->id)->set('condicion', 'parcial')->set('pagado', '200')
        ->set('vencimiento', '2026-10-31')->call('registrarEgreso')->assertHasNoErrors()->assertSee('Parcial');

    expect(CuentaPagar::query()->orderBy('id')->pluck('saldo_pendiente')->all())->toBe(['40.00', '300.00'])
        ->and(MovimientoCaja::query()->sum('monto'))->toEqual('200.00');
});

test('an administrator chooses the till of the cash payment among the open shifts of the branch', function () {
    $cuenta = compraCon($this, ['condicion' => 'credito', 'vencimiento' => '2026-11-19'])->cuentaPagar;
    $segunda = new Caja(['nombre' => 'Caja 2', 'modalidad' => 'compartida', 'activa' => true, 'es_principal' => false]);
    $segunda->empresa_id = $this->empresa->id;
    $segunda->sucursal_id = $this->sucursal->id;
    $segunda->save();
    $principal = app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->sucursal->cajaPrincipal(), '0');
    // Un usuario tiene un solo turno abierto: los otros los abren otros administradores.
    $turno = app(AbrirTurnoCaja::class)->ejecutar(User::factory()->administrador($this->empresa)->create(), $segunda, '0');
    $otra = Sucursal::factory()->create(['empresa_id' => $this->empresa->id]);
    $ajeno = app(AbrirTurnoCaja::class)->ejecutar(User::factory()->administrador($this->empresa)->create(), $otra->cajaPrincipal(), '0');

    $pantalla = Livewire::test(CuentaPagarDetalle::class, ['cuenta' => $cuenta])
        ->assertSet('caja', (string) $principal->id)
        ->assertSee('Caja 2 · turno #'.$turno->id)->assertSee('Fuera de caja (externo)')
        ->set('caja', (string) $ajeno->id)->set('monto', '100')->call('registrar')->assertHasErrors('externo')
        ->set('caja', (string) $turno->id)->call('registrar')->assertHasNoErrors();

    expect(PagoCuentaPagar::query()->sole()->only(['caja_id', 'turno_caja_id', 'externo']))
        ->toBe(['caja_id' => $segunda->id, 'turno_caja_id' => $turno->id, 'externo' => false])
        ->and($pantalla->get('caja'))->toBe((string) $principal->id);
});

test('an admin annuls a payment: the balance comes back, the money returns that day and manual obligations follow', function () {
    $cuenta = compraCon($this, ['condicion' => 'credito', 'vencimiento' => '2026-11-19'])->cuentaPagar;
    $pago = pagarCuenta($this->admin, $cuenta, ['monto' => '1000']);
    $motivo = ['motivo' => 'Transferencia rechazada', 'externo' => true];

    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'America/La_Paz'));
    app(AnularPagoCuenta::class)->ejecutar($this->admin, $pago, $motivo);
    app(AnularPagoCuenta::class)->ejecutar($this->admin, $pago->refresh(), $motivo);
    $fila = Cobros::detalle($this->empresa->id, Periodo::dia('2026-10-21'))->sole();

    expect($cuenta->refresh()->saldo_pendiente)->toBe('5000.00')
        ->and($pago->refresh()->estaAnulado())->toBeTrue()
        ->and(MovimientoCaja::query()->where('origen_tipo', 'anulacion_pago_cxp')->sole()->only(['sentido', 'metodo', 'monto']))
        ->toBe(['sentido' => 'ingreso', 'metodo' => 'electronico', 'monto' => '1000.00'])
        ->and(Cobros::concepto($fila))->toBe('Devolución de pago anulado')
        ->and(Cobros::referencia($fila))->toBe($cuenta->codigo())
        ->and(Cobros::resumen($this->empresa->id, Periodo::dia('2026-10-19'))['egresos'])->toBe('1000.00')
        ->and(fn () => app(AnularObligacion::class)->ejecutar($this->admin, $cuenta, $motivo))->toThrow(ValidationException::class);

    Livewire::test(CuentaPagarDetalle::class, ['cuenta' => $cuenta])->assertSee('Historial de cambios')
        ->assertSee('Generada por COM-00001')->assertSee('Pago registrado')->assertSee('Pago anulado')
        ->assertSee('Bs 4.000,00 → Bs 5.000,00')->assertSee('Transferencia rechazada');

    $manual = app(RegistrarObligacion::class)->ejecutar($this->admin, ['proveedor_id' => $this->proveedor->id, 'sucursal_id' => $this->sucursal->id,
        'concepto' => 'Préstamo anterior', 'fecha' => '2026-01-15', 'vencimiento' => '2026-12-01', 'importe' => '800', 'pagado' => null,
        'nota' => null, 'clave' => (string) Str::uuid()]);
    $pagoManual = pagarCuenta($this->admin, $manual, ['monto' => '300']);

    expect(fn () => app(AnularObligacion::class)->ejecutar($this->admin, $manual, $motivo))->toThrow(ValidationException::class);

    app(AnularPagoCuenta::class)->ejecutar($this->admin, $pagoManual, $motivo);
    app(AnularObligacion::class)->ejecutar($this->admin, $manual->refresh(), $motivo);

    expect($manual->refresh()->estado())->toBe('anulada')
        ->and(CuentasPorPagar::resumen($this->empresa->id)['pendiente'])->toBe('5000.00')
        ->and(CuentasPorPagar::consulta($this->empresa->id, 'anuladas')->pluck('cp.id')->all())->toBe([$manual->id])
        ->and(fn () => pagarCuenta($this->admin, $manual, ['monto' => '10']))->toThrow(ValidationException::class);

    $csv = $this->get(route('cuentas-pagar.exportar', ['estado' => 'anuladas']))->assertOk()->streamedContent();
    expect($csv)->toContain($manual->codigo())->toContain('Anulada')->toContain('Transferencia rechazada')
        ->and($this->get(route('cuentas-pagar.exportar'))->assertOk()->streamedContent())->toContain($cuenta->codigo())->not->toContain($manual->codigo());

    $this->actingAs(User::factory()->vendedor($this->sucursal)->create())->get(route('cuentas-pagar.exportar'))->assertForbidden();
});
