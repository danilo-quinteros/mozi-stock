<?php

use App\Domain\Creditos\Actions\RegistrarAbono;
use App\Domain\Reportes\Consultas\Cobros;
use App\Domain\Reportes\Consultas\VentasPeriodo;
use App\Domain\Reportes\Periodo;
use App\Models\Cliente;
use App\Models\Cobro;
use App\Models\MovimientoCaja;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * Prioridad máxima (§ 3.11 y T12–T15, T20; V3 pruebas 2 y 3). Semana de prueba en La Paz:
 * lunes 19, martes 20, miércoles 21 y jueves 22 de octubre de 2026.
 */

function enLaPaz(string $momento): void
{
    test()->travelTo(CarbonImmutable::parse($momento, 'America/La_Paz'));
}

function abonar(User $actor, Venta $venta, array $datos): Cobro
{
    return app(RegistrarAbono::class)->ejecutar($actor, $venta, $datos + [
        'sucursal_id' => null, 'metodo' => 'efectivo', 'monto' => null, 'efectivo' => null,
        'electronico' => null, 'nota' => null, 'externo' => true, 'clave' => (string) Str::uuid(),
    ]);
}

function cobradoEl(int $empresaId, string $dia, ?int $sucursalId = null): string
{
    return Cobros::resumen($empresaId, Periodo::dia($dia), $sucursalId)['total'];
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    $this->producto = productoDe($this->empresa, ['precio_consumidor' => '20.00']);
    $this->actingAs($this->admin);
    enLaPaz('2026-10-19 08:00');
    cargarStock($this->admin, $this->matriz, $this->producto, '50');
    $this->cliente = new Cliente(['nombre' => 'Carlos Mendoza', 'documento' => '4455667']);
    $this->cliente->save();
});

/**
 * Venta a crédito de Bs 200 el lunes con cuota inicial de Bs 50 en efectivo.
 */
function ventaCreditoLunes(object $t): Venta
{
    enLaPaz('2026-10-19 10:15');
    $pedido = crearPedido($t->admin, $t->matriz, [['producto_id' => $t->producto->id, 'cantidad' => '10']], ['cliente_id' => $t->cliente->id]);

    return confirmarVenta($t->admin, $pedido, ['metodo' => 'efectivo', 'en_cuotas' => true, 'cuota_inicial' => '50']);
}

test('T12–T14 the mandatory example: 50 Monday, 30 Tuesday, 0 Wednesday, 120 Thursday, never 200 on Monday', function () {
    $venta = ventaCreditoLunes($this);

    // T12: lunes cobrado 50, valor vendido 200 en columna separada, saldo 150.
    expect(cobradoEl($this->empresa->id, '2026-10-19'))->toBe('50.00')
        ->and(VentasPeriodo::totales($this->empresa->id, Periodo::dia('2026-10-19'))['valor_vendido'])->toBe('200.00')
        ->and($venta->refresh()->saldo_pendiente)->toBe('150.00');

    // T13: martes abono 30.
    enLaPaz('2026-10-20 16:40');
    abonar($this->admin, $venta, ['sucursal_id' => $this->matriz->id, 'monto' => '30']);
    expect(cobradoEl($this->empresa->id, '2026-10-20'))->toBe('30.00')
        ->and(cobradoEl($this->empresa->id, '2026-10-19'))->toBe('50.00')
        ->and($venta->refresh()->saldo_pendiente)->toBe('120.00')
        ->and(VentasPeriodo::totales($this->empresa->id, Periodo::dia('2026-10-20'))['valor_vendido'])->toBe('0.00');

    // T14: jueves abono 120; miércoles 0.
    enLaPaz('2026-10-22 09:05');
    abonar($this->admin, $venta, ['sucursal_id' => $this->matriz->id, 'monto' => '120']);

    expect(cobradoEl($this->empresa->id, '2026-10-21'))->toBe('0.00')
        ->and(cobradoEl($this->empresa->id, '2026-10-22'))->toBe('120.00')
        ->and($venta->refresh()->saldo_pendiente)->toBe('0.00')
        ->and(Cobros::resumen($this->empresa->id, Periodo::rango('2026-10-19', '2026-10-22'))['total'])->toBe('200.00')
        ->and(Cobros::porDia($this->empresa->id, Periodo::rango('2026-10-19', '2026-10-22')))
        ->toBe(['2026-10-19' => '50.00', '2026-10-20' => '30.00', '2026-10-22' => '120.00']);
});

test('T15 a quotation never appears in income or confirmed sales', function () {
    enLaPaz('2026-10-19 11:00');
    crearPedido($this->admin, $this->matriz, [['producto_id' => $this->producto->id, 'cantidad' => '10']]);

    expect(cobradoEl($this->empresa->id, '2026-10-19'))->toBe('0.00')
        ->and(VentasPeriodo::totales($this->empresa->id, Periodo::dia('2026-10-19')))->toBe(['valor_vendido' => '0.00', 'cantidad' => 0, 'devoluciones' => '0.00', 'cantidad_devoluciones' => 0, 'venta_neta' => '0.00'])
        ->and(VentasPeriodo::pendientes($this->empresa->id)['cotizaciones'])->toBe(1);
});

test('V3-2 a mixed installment 20 cash + 30 electronic on a balance of 150 leaves 100 and records 50 once', function () {
    $venta = ventaCreditoLunes($this);
    enLaPaz('2026-10-20 12:00');
    $pago = abonar($this->admin, $venta, ['sucursal_id' => $this->matriz->id, 'metodo' => 'mixto', 'efectivo' => '20', 'electronico' => '30']);

    expect($venta->refresh()->saldo_pendiente)->toBe('100.00')
        ->and($pago->only(['monto', 'monto_efectivo', 'monto_electronico']))
        ->toBe(['monto' => '50.00', 'monto_efectivo' => '20.00', 'monto_electronico' => '30.00'])
        ->and(Cobro::query()->count())->toBe(1)
        ->and(MovimientoCaja::query()->where('origen_tipo', 'cobro')->where('origen_id', $pago->id)->pluck('monto', 'metodo')->all())
        ->toBe(['efectivo' => '20.00', 'electronico' => '30.00'])
        ->and(Cobros::resumen($this->empresa->id, Periodo::dia('2026-10-20')))
        ->toMatchArray(['efectivo' => '20.00', 'electronico' => '30.00', 'total' => '50.00']);
});

test('V3-3 installments above the balance, negative, zero or repeated are rejected', function (array $datos) {
    $venta = ventaCreditoLunes($this);

    expect(fn () => abonar($this->admin, $venta, $datos + ['sucursal_id' => $this->matriz->id]))->toThrow(ValidationException::class);
    expect($venta->refresh()->saldo_pendiente)->toBe('150.00')
        ->and(Cobro::query()->count())->toBe(0);
})->with([
    'mayor que el saldo' => [['monto' => '150.01']],
    'negativo' => [['monto' => '-5']],
    'cero' => [['monto' => '0']],
    'mixto en cero' => [['metodo' => 'mixto', 'efectivo' => '0', 'electronico' => '0']],
    'mixto que excede' => [['metodo' => 'mixto', 'efectivo' => '100', 'electronico' => '50.01']],
    'mixto con negativo' => [['metodo' => 'mixto', 'efectivo' => '-10', 'electronico' => '40']],
    'sin método' => [['metodo' => 'cheque', 'monto' => '10']],
    'más de 2 decimales' => [['monto' => '10.005']],
]);

test('T19 retrying the same installment does not charge twice', function () {
    $venta = ventaCreditoLunes($this);
    $datos = ['sucursal_id' => $this->matriz->id, 'monto' => '30', 'clave' => (string) Str::uuid()];

    $primero = abonar($this->admin, $venta, $datos);
    $segundo = abonar($this->admin, $venta->refresh(), $datos);

    expect($segundo->id)->toBe($primero->id)
        ->and($venta->refresh()->saldo_pendiente)->toBe('120.00')
        ->and(MovimientoCaja::query()->sum('monto'))->toEqual('80.00');
});

test('T20 an installment the next day at another branch counts only that day, for that branch and method', function () {
    $venta = ventaCreditoLunes($this);
    $vendedorNorte = User::factory()->vendedor($this->norte)->create();
    $this->actingAs($vendedorNorte);

    enLaPaz('2026-10-20 18:30');
    // El vendedor intenta registrar en Casa Matriz: el servidor usa su sucursal asignada.
    $pago = abonar($vendedorNorte, $venta, ['sucursal_id' => $this->matriz->id, 'metodo' => 'electronico', 'monto' => '30']);

    expect($pago->sucursal_id)->toBe($this->norte->id)
        ->and($pago->recibido_por)->toBe($vendedorNorte->id)
        ->and($venta->refresh()->vendedor_id)->toBe($this->admin->id)
        ->and(cobradoEl($this->empresa->id, '2026-10-20', $this->norte->id))->toBe('30.00')
        ->and(cobradoEl($this->empresa->id, '2026-10-20', $this->matriz->id))->toBe('0.00')
        ->and(cobradoEl($this->empresa->id, '2026-10-19', $this->norte->id))->toBe('0.00')
        ->and(Cobros::resumen($this->empresa->id, Periodo::dia('2026-10-20'))['electronico'])->toBe('30.00');
});

test('the business day follows La Paz time, not UTC', function () {
    $venta = ventaCreditoLunes($this);

    // 23:30 del martes en La Paz es 03:30 del miércoles en UTC.
    enLaPaz('2026-10-20 23:30');
    abonar($this->admin, $venta, ['sucursal_id' => $this->matriz->id, 'monto' => '30']);

    expect(cobradoEl($this->empresa->id, '2026-10-20'))->toBe('30.00')
        ->and(cobradoEl($this->empresa->id, '2026-10-21'))->toBe('0.00');
});

test('an installment cannot be charged on a paid credit nor by another company', function () {
    $venta = ventaCreditoLunes($this);
    abonar($this->admin, $venta, ['sucursal_id' => $this->matriz->id, 'monto' => '150']);

    expect(fn () => abonar($this->admin, $venta->refresh(), ['sucursal_id' => $this->matriz->id, 'monto' => '1']))
        ->toThrow(ValidationException::class);

    [, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    expect(fn () => abonar($adminB, $venta, ['monto' => '1']))->toThrow(ModelNotFoundException::class);
});

test('reports never mix companies', function () {
    ventaCreditoLunes($this);
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $sucursalB = Sucursal::factory()->create(['empresa_id' => $empresaB->id]);
    $productoB = productoDe($empresaB, ['precio_consumidor' => '999.00']);
    cargarStock($adminB, $sucursalB, $productoB, '1');
    confirmarVenta($adminB, crearPedido($adminB, $sucursalB, [['producto_id' => $productoB->id, 'cantidad' => '1']]), ['metodo' => 'efectivo']);

    expect(cobradoEl($this->empresa->id, '2026-10-19'))->toBe('50.00')
        ->and(cobradoEl($empresaB->id, '2026-10-19'))->toBe('999.00')
        ->and(VentasPeriodo::totales($this->empresa->id, Periodo::dia('2026-10-19'))['valor_vendido'])->toBe('200.00');
});
