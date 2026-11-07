<?php

use App\Domain\Creditos\Actions\RegistrarAbono;
use App\Domain\Creditos\Consultas\CuentasPorCobrar;
use App\Domain\Reportes\Consultas\Cobros;
use App\Domain\Reportes\Periodo;
use App\Domain\Ventas\Actions\RegistrarDevolucion;
use App\Livewire\Caja\Arqueo;
use App\Livewire\Ventas\Detalle;
use App\Livewire\Ventas\NuevaDevolucion;
use App\Models\Cliente;
use App\Models\Devolucion;
use App\Models\MovimientoCaja;
use App\Models\OperacionInventario;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaItem;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Devoluciones de venta (§ 3.9, § 5.5 y T21). Decisión del propietario (23/10/2026): importe
 * automático a precio neto; primero reduce el saldo del crédito y solo el resto se reembolsa,
 * en el día real del reembolso. Semana de prueba en La Paz: lunes 19 a jueves 22 de octubre.
 */

function horaLaPaz(string $momento): void
{
    test()->travelTo(CarbonImmutable::parse($momento, 'America/La_Paz'));
}

/**
 * @param  array<int, string|array{cantidad: string, reingresa?: bool}>  $lineas  por id de línea de venta
 */
function devolver(User $admin, Venta $venta, array $lineas, array $extra = []): Devolucion
{
    return app(RegistrarDevolucion::class)->ejecutar($admin, $venta, $extra + [
        'lineas' => array_map(fn ($l) => is_array($l) ? $l + ['reingresa' => true] : ['cantidad' => $l, 'reingresa' => true], $lineas),
        'motivo' => 'Producto defectuoso', 'metodo' => 'efectivo', 'efectivo' => null, 'electronico' => null,
        'externo' => ! isset($extra['turno_id']), 'clave' => (string) Str::uuid(),
    ]);
}

/**
 * @return list<int>
 */
function lineasDe(Venta $venta): array
{
    return VentaItem::query()->where('venta_id', $venta->id)->orderBy('orden')->pluck('id')->all();
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->producto = productoDe($this->empresa, ['nombre' => 'Leche 1L', 'precio_consumidor' => '20.00']);
    $this->actingAs($this->admin);
    horaLaPaz('2026-10-19 08:00');
    cargarStock($this->admin, $this->matriz, $this->producto, '50');
    $this->cliente = new Cliente(['nombre' => 'Carlos Mendoza', 'documento' => '4455667']);
    $this->cliente->save();
});

function ventaDe(object $t, string $cantidad, array $pago, array $extra = []): Venta
{
    $pedido = crearPedido($t->admin, $t->matriz, [['producto_id' => $t->producto->id, 'cantidad' => $cantidad]], $extra + ['cliente_id' => $t->cliente->id]);

    return confirmarVenta($t->admin, $pedido, $pago);
}

test('T21 a Bs 20 cash refund leaves on the refund day; the original collection is neither hidden nor duplicated', function () {
    horaLaPaz('2026-10-19 10:00');
    $venta = ventaDe($this, '10', ['metodo' => 'efectivo']);   // Bs 200 al contado el lunes

    horaLaPaz('2026-10-20 16:00');
    $devolucion = devolver($this->admin, $venta, [lineasDe($venta)[0] => '1']);

    expect($devolucion->codigo())->toBe('DEV-00001')
        ->and($devolucion->importe)->toBe('20.00')
        ->and($devolucion->deuda_reducida)->toBe('0.00')
        ->and($devolucion->reembolso)->toBe('20.00');

    $lunes = Cobros::resumen($this->empresa->id, Periodo::dia('2026-10-19'));
    $martes = Cobros::resumen($this->empresa->id, Periodo::dia('2026-10-20'));
    expect($lunes['total'])->toBe('200.00')->and($lunes['egresos'])->toBe('0.00')
        ->and($martes['total'])->toBe('0.00')->and($martes['egresos_efectivo'])->toBe('20.00')->and($martes['neto'])->toBe('-20.00');

    $venta->refresh();
    expect($venta->total)->toBe('200.00')            // la venta no se reescribe
        ->and($venta->devuelto)->toBe('20.00')
        ->and($venta->reembolsado)->toBe('20.00')
        ->and($venta->saldo_pendiente)->toBe('0.00')
        ->and($venta->cobrado())->toBe('200.00')
        ->and(stockDe($this->matriz, $this->producto))->toBe('41.000')
        ->and(MovimientoCaja::query()->where('sentido', 'egreso')->sole()->origen_tipo)->toBe('devolucion');

    Livewire::test(Arqueo::class)->set('fecha', '2026-10-20')->call('cambiarPestana', 'movimientos')->assertSee('Reembolso · DEV-00001')->assertSee('− Bs 20,00');
    Livewire::test(Arqueo::class)->set('fecha', '2026-10-19')->call('cambiarPestana', 'movimientos')->assertDontSee('Reembolso · DEV-00001');
    $csvMartes = $this->get(route('caja.exportar', ['fecha' => '2026-10-20']))->streamedContent();
    expect($csvMartes)->toContain('Salida;Reembolso;DEV-00001')->toContain(';20.00;0.00;20.00')->not->toContain('Ingreso;');
});

test('on a credit sale the return reduces the debt first and moves no money', function () {
    horaLaPaz('2026-10-19 10:00');
    $venta = ventaDe($this, '10', ['metodo' => 'efectivo', 'en_cuotas' => true, 'cuota_inicial' => '50']);

    horaLaPaz('2026-10-20 09:00');
    $devolucion = devolver($this->admin, $venta, [lineasDe($venta)[0] => '5'], ['metodo' => null]);

    expect($devolucion->importe)->toBe('100.00')
        ->and($devolucion->deuda_reducida)->toBe('100.00')
        ->and($devolucion->reembolso)->toBe('0.00')
        ->and($devolucion->reembolso_metodo)->toBeNull()
        ->and($venta->refresh()->saldo_pendiente)->toBe('50.00')
        ->and($venta->cobrado())->toBe('50.00')
        ->and(MovimientoCaja::query()->where('sentido', 'egreso')->count())->toBe(0)
        ->and(Cobros::resumen($this->empresa->id, Periodo::dia('2026-10-20'))['neto'])->toBe('0.00');

    expect(CuentasPorCobrar::resumen($this->empresa->id)['pendiente'])->toBe('50.00')
        ->and((string) CuentasPorCobrar::consulta($this->empresa->id)->sole()->cobrado)->toBe('50.00');

    // Un abono posterior respeta el saldo reducido.
    expect(fn () => app(RegistrarAbono::class)->ejecutar($this->admin, $venta, [
        'sucursal_id' => $this->matriz->id, 'metodo' => 'efectivo', 'monto' => '60', 'efectivo' => null,
        'electronico' => null, 'nota' => null, 'externo' => true, 'clave' => (string) Str::uuid(),
    ]))->toThrow(ValidationException::class);
});

test('when the return exceeds the debt only the rest is refunded, with an exact mixed split', function () {
    horaLaPaz('2026-10-19 10:00');
    $venta = ventaDe($this, '10', ['metodo' => 'efectivo', 'en_cuotas' => true, 'cuota_inicial' => '150']);   // saldo 50
    $linea = lineasDe($venta)[0];

    horaLaPaz('2026-10-21 12:00');
    expect(fn () => devolver($this->admin, $venta, [$linea => '5'], ['metodo' => 'mixto', 'efectivo' => '20', 'electronico' => '20']))
        ->toThrow(ValidationException::class);
    expect(Devolucion::query()->count())->toBe(0)->and(stockDe($this->matriz, $this->producto))->toBe('40.000');

    $devolucion = devolver($this->admin, $venta, [$linea => '5'], ['metodo' => 'mixto', 'efectivo' => '20', 'electronico' => '30']);

    expect($devolucion->deuda_reducida)->toBe('50.00')->and($devolucion->reembolso)->toBe('50.00')
        ->and($venta->refresh()->saldo_pendiente)->toBe('0.00')
        ->and($venta->reembolsado)->toBe('50.00')
        ->and($venta->cobrado())->toBe('150.00')
        ->and(MovimientoCaja::query()->where('sentido', 'egreso')->orderBy('metodo')->pluck('monto', 'metodo')->all())
        ->toBe(['efectivo' => '20.00', 'electronico' => '30.00']);
});

test('partial returns accumulate, never exceed what was sold and the full return equals the total exactly', function () {
    // 2 líneas (20 + 10) con descuento global de Bs 10: total Bs 20.
    $pan = productoDe($this->empresa, ['nombre' => 'Pan', 'precio_consumidor' => '10.00']);
    $galleta = productoDe($this->empresa, ['nombre' => 'Galleta', 'precio_consumidor' => '10.00']);
    cargarStock($this->admin, $this->matriz, $pan, '10');
    cargarStock($this->admin, $this->matriz, $galleta, '10');
    $pedido = crearPedido($this->admin, $this->matriz, [
        ['producto_id' => $pan->id, 'cantidad' => '2'],
        ['producto_id' => $galleta->id, 'cantidad' => '1'],
    ], ['descuento_global_tipo' => 'bs', 'descuento_global_valor' => '10']);
    $venta = confirmarVenta($this->admin, $pedido, ['metodo' => 'efectivo']);
    [$primera, $segunda] = lineasDe($venta);

    expect($venta->total)->toBe('20.00');

    $uno = devolver($this->admin, $venta, [$primera => '1']);
    expect($uno->importe)->toBe('6.67');   // la mitad de 13,33 (20 − 6,67 de descuento prorrateado)

    expect(fn () => devolver($this->admin, $venta, [$primera => '2']))->toThrow(ValidationException::class);

    $resto = devolver($this->admin, $venta, [$primera => '1', $segunda => '1']);
    $venta->refresh();

    expect($resto->importe)->toBe('13.33')
        ->and($venta->devuelto)->toBe('20.00')
        ->and($venta->reembolsado)->toBe('20.00')
        ->and(stockDe($this->matriz, $pan))->toBe('10.000')
        ->and(stockDe($this->matriz, $galleta))->toBe('10.000');

    expect(fn () => devolver($this->admin, $venta, [$segunda => '1']))->toThrow(ValidationException::class);
    Livewire::test(Detalle::class, ['venta' => $venta])->assertDontSee('Registrar devolución')->assertSee('DEV-00002');
});

test('unmarked lines do not return to stock and the DEV series is shared with or without stock', function () {
    $venta = ventaDe($this, '4', ['metodo' => 'electronico']);
    $linea = lineasDe($venta)[0];

    $danado = devolver($this->admin, $venta, [$linea => ['cantidad' => '1', 'reingresa' => false]], ['metodo' => 'electronico']);
    expect($danado->codigo())->toBe('DEV-00001')
        ->and($danado->operacion_inventario_id)->toBeNull()
        ->and(stockDe($this->matriz, $this->producto))->toBe('46.000');

    $bueno = devolver($this->admin, $venta, [$linea => '1'], ['metodo' => 'electronico']);
    expect($bueno->codigo())->toBe('DEV-00002')
        ->and(OperacionInventario::query()->whereKey($bueno->operacion_inventario_id)->sole()->codigo())->toBe('DEV-00002')
        ->and(stockDe($this->matriz, $this->producto))->toBe('47.000');
});

test('T19 repeating a return key applies it only once', function () {
    $venta = ventaDe($this, '5', ['metodo' => 'efectivo']);
    $clave = (string) Str::uuid();
    $linea = lineasDe($venta)[0];

    $primera = devolver($this->admin, $venta, [$linea => '2'], ['clave' => $clave]);
    $segunda = devolver($this->admin, $venta, [$linea => '2'], ['clave' => $clave]);

    expect($segunda->id)->toBe($primera->id)
        ->and(Devolucion::query()->count())->toBe(1)
        ->and($venta->refresh()->devuelto)->toBe('40.00')
        ->and(MovimientoCaja::query()->where('sentido', 'egreso')->count())->toBe(1)
        ->and(stockDe($this->matriz, $this->producto))->toBe('47.000');
});

test('a motive is mandatory and only administrators of the same company can return', function () {
    $venta = ventaDe($this, '5', ['metodo' => 'efectivo']);
    $linea = lineasDe($venta)[0];

    expect(fn () => devolver($this->admin, $venta, [$linea => '1'], ['motivo' => '  ']))->toThrow(ValidationException::class)
        ->and(fn () => devolver($this->admin, $venta, [$linea => '']))->toThrow(ValidationException::class);

    $vendedor = User::factory()->vendedor($this->matriz)->create();
    $this->actingAs($vendedor);
    expect(fn () => devolver($vendedor, $venta, [$linea => '1']))->toThrow(AuthorizationException::class);
    $this->get(route('ventas.devolucion', $venta))->assertForbidden();
    $this->get(route('ventas.ver', $venta))->assertOk()->assertDontSee('Registrar devolución');

    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $this->get(route('ventas.devolucion', $venta))->assertNotFound();

    expect(Devolucion::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('the return screen previews the split and confirms once', function () {
    horaLaPaz('2026-10-19 10:00');
    $venta = ventaDe($this, '10', ['metodo' => 'efectivo', 'en_cuotas' => true, 'cuota_inicial' => '170']);   // saldo 30
    $linea = lineasDe($venta)[0];

    horaLaPaz('2026-10-20 10:00');
    $pantalla = Livewire::test(NuevaDevolucion::class, ['venta' => $venta])
        ->set("lineas.{$linea}.cantidad", '2')
        ->assertSee('Bs 40,00')    // valor devuelto
        ->assertSee('Bs 30,00')    // reduce saldo
        ->assertSee('Bs 10,00')    // reembolso
        ->set('motivo', 'Cliente cambió de opinión')
        ->call('revisar')->assertSet('confirmando', true);

    $pantalla->call('confirmar')->assertRedirect(route('ventas.ver', $venta));
    $pantalla->call('confirmar');

    expect(Devolucion::query()->count())->toBe(1)
        ->and($venta->refresh()->saldo_pendiente)->toBe('0.00')
        ->and($venta->reembolsado)->toBe('10.00');

    $this->get(route('ventas.ver', $venta))->assertOk()->assertSee('DEV-00001')->assertSee('Reembolso Bs 10,00')
        ->assertSee('Registrar devolución');
    $this->get(route('creditos.historial', $venta))->assertOk()->assertSee('Devoluciones por Bs 40,00');
});
