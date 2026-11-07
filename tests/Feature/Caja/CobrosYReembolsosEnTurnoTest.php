<?php

use App\Domain\Caja\Actions\AbrirTurnoCaja;
use App\Domain\Caja\Actions\CerrarTurnoCaja;
use App\Domain\Caja\Consultas\ReporteCaja;
use App\Domain\Reportes\Consultas\Cobros;
use App\Domain\Reportes\Periodo;
use App\Domain\Ventas\Actions\RegistrarDevolucion;
use App\Livewire\Caja\Arqueo;
use App\Livewire\Pedidos\Editor;
use App\Livewire\Ventas\NuevaDevolucion;
use App\Models\Cliente;
use App\Models\Devolucion;
use App\Models\MovimientoCaja;
use App\Models\Pedido;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Decisiones del propietario del 01/11/2026: el cobro de un pedido confirmado fuera del POS se
 * trata como un abono (§ 10.4) y el reembolso en efectivo de una devolución sale, como un egreso,
 * del turno de quien la registra (§ 18.7). En ambos el administrador puede elegir «fuera de caja».
 */

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->actingAs($this->admin);
    $this->sucursalA = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal A']);
    $this->sucursalB = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal B']);
    $this->cajaA = $this->sucursalA->cajaPrincipal();
    $this->cajaB = $this->sucursalB->cajaPrincipal();
    $this->producto = productoDe($this->empresa, ['nombre' => 'Aceite 1L', 'precio_consumidor' => '20.00']);
    cargarStock($this->admin, $this->sucursalA, $this->producto, '20');
    cargarStock($this->admin, $this->sucursalB, $this->producto, '20');
    $this->cliente = new Cliente(['nombre' => 'Carlos Mendoza', 'documento' => '4455667']);
    $this->cliente->save();
});

function pedidoDeAceite(User $actor, Sucursal $sucursal, string $cantidad = '1'): Pedido
{
    return crearPedido($actor, $sucursal, [['producto_id' => test()->producto->id, 'cantidad' => $cantidad]], ['cliente_id' => test()->cliente->id]);
}

/**
 * @param  array<string, mixed>  $extra
 */
function reembolsarUno(User $admin, Venta $venta, array $extra = []): Devolucion
{
    return app(RegistrarDevolucion::class)->ejecutar($admin, $venta, $extra + [
        'lineas' => [$venta->items()->value('id') => ['cantidad' => '1', 'reingresa' => true]],
        'motivo' => 'Producto defectuoso', 'metodo' => 'efectivo', 'efectivo' => null, 'electronico' => null,
        'clave' => (string) Str::uuid(),
    ]);
}

test('el efectivo de un pedido confirmado fuera del POS entra en el turno abierto y cuenta en su arqueo', function () {
    $turno = app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->cajaA, '100.00');

    $elegido = confirmarVenta($this->admin, pedidoDeAceite($this->admin, $this->sucursalA), ['metodo' => 'efectivo', 'turno_id' => $turno->id]);
    // Sin elegir turno, el administrador usa el de la caja principal de la sucursal.
    $porDefecto = confirmarVenta($this->admin, pedidoDeAceite($this->admin, $this->sucursalA), ['metodo' => 'efectivo', 'externo' => false]);

    expect([$elegido->turno_caja_id, $porDefecto->turno_caja_id])->toBe([$turno->id, $turno->id])
        ->and([$elegido->caja_id, $porDefecto->caja_id])->toBe([$this->cajaA->id, $this->cajaA->id])
        ->and(MovimientoCaja::query()->where('origen_tipo', 'pago_venta')->where('turno_caja_id', $turno->id)->count())->toBe(2);

    // 100 de fondo + 20 + 20: el arqueo los espera en el cajón.
    expect((string) app(CerrarTurnoCaja::class)->ejecutar($this->admin, $turno, '140.00')->diferencia)->toBe('0.00');
});

test('sin turno abierto el efectivo se rechaza, lo electrónico y el crédito sin cuota inicial no lo exigen y solo el administrador cobra fuera de caja', function () {
    $pedido = pedidoDeAceite($this->admin, $this->sucursalA);

    expect(fn () => confirmarVenta($this->admin, $pedido, ['metodo' => 'efectivo', 'externo' => false]))
        ->toThrow(ValidationException::class, 'El efectivo por caja requiere un turno abierto');
    // La venta rechazada no movió stock ni dinero.
    expect(Venta::query()->count())->toBe(0)
        ->and(stockDe($this->sucursalA, $this->producto))->toBe('20.000')
        ->and(MovimientoCaja::query()->count())->toBe(0);

    $electronica = confirmarVenta($this->admin, pedidoDeAceite($this->admin, $this->sucursalA), ['metodo' => 'electronico', 'externo' => false]);
    $credito = confirmarVenta($this->admin, pedidoDeAceite($this->admin, $this->sucursalA),
        ['metodo' => 'efectivo', 'en_cuotas' => true, 'cuota_inicial' => '0', 'externo' => false]);
    $fuera = confirmarVenta($this->admin, $pedido, ['metodo' => 'efectivo', 'externo' => true]);

    expect([$electronica->turno_caja_id, $credito->turno_caja_id, $fuera->turno_caja_id])->toBe([null, null, null])
        ->and((string) $credito->saldo_pendiente)->toBe('20.00')
        ->and(MovimientoCaja::query()->where('metodo', 'efectivo')->whereNull('caja_id')->count())->toBe(1);

    $vendedor = User::factory()->vendedor($this->sucursalA)->create(['caja_id' => $this->cajaA->id]);
    $suyo = pedidoDeAceite($vendedor, $this->sucursalA);

    expect(fn () => confirmarVenta($vendedor, $suyo, ['metodo' => 'efectivo']))
        ->toThrow(ValidationException::class, 'Abre tu caja en la barra superior')
        ->and(fn () => confirmarVenta($vendedor, $suyo, ['metodo' => 'efectivo', 'externo' => true]))
        ->toThrow(ValidationException::class, 'Solo un administrador registra efectivo fuera de caja');
});

test('el vendedor cobra en el turno de su propia caja aunque la solicitud indique otro turno', function () {
    $vendedor = User::factory()->vendedor($this->sucursalA)->create(['caja_id' => $this->cajaA->id]);
    $suyo = app(AbrirTurnoCaja::class)->ejecutar($vendedor, $this->cajaA, '0');
    $ajeno = app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->cajaB, '0');

    $venta = confirmarVenta($vendedor, pedidoDeAceite($vendedor, $this->sucursalA), ['metodo' => 'efectivo', 'turno_id' => $ajeno->id]);

    expect($venta->turno_caja_id)->toBe($suyo->id)
        ->and($venta->caja_id)->toBe($this->cajaA->id)
        ->and(MovimientoCaja::query()->where('turno_caja_id', $ajeno->id)->exists())->toBeFalse();
});

test('el reembolso en efectivo sale del turno abierto y baja el efectivo esperado del cajón', function () {
    $turno = app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->cajaA, '100.00');
    $venta = confirmarVenta($this->admin, pedidoDeAceite($this->admin, $this->sucursalA, '2'), ['metodo' => 'efectivo', 'turno_id' => $turno->id]);

    $devolucion = reembolsarUno($this->admin, $venta);

    $salida = MovimientoCaja::query()->where('origen_tipo', 'devolucion')->sole();
    expect((string) $devolucion->reembolso)->toBe('20.00')
        ->and($salida->turno_caja_id)->toBe($turno->id)
        ->and($salida->caja_id)->toBe($this->cajaA->id)
        ->and($salida->sentido)->toBe('egreso');

    // 100 + 40 de la venta − 20 del reembolso.
    expect((string) app(CerrarTurnoCaja::class)->ejecutar($this->admin, $turno, '120.00')->diferencia)->toBe('0.00');

    // Turno cerrado: el efectivo se rechaza salvo que el administrador lo deje fuera de caja.
    expect(fn () => reembolsarUno($this->admin, $venta, ['externo' => false]))
        ->toThrow(ValidationException::class, 'El efectivo por caja requiere un turno abierto');
    expect(Devolucion::query()->count())->toBe(1);

    reembolsarUno($this->admin, $venta, ['externo' => true]);
    expect(MovimientoCaja::query()->where('origen_tipo', 'devolucion')->whereNull('caja_id')->count())->toBe(1)
        ->and(Devolucion::query()->count())->toBe(2);
});

test('las pantallas de pedido y devolución ofrecen el turno abierto y registran el dinero en él', function () {
    $turno = app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->cajaA, '50.00');
    $pedido = pedidoDeAceite($this->admin, $this->sucursalA, '2');

    Livewire::test(Editor::class, ['pedido' => $pedido])
        ->assertSet('caja', (string) $turno->id)
        ->assertSee('Caja del efectivo')
        ->assertSee('Fuera de caja (externo)')
        ->call('revisar')
        ->call('confirmar');

    $venta = Venta::query()->sole();
    expect($venta->turno_caja_id)->toBe($turno->id);

    Livewire::test(NuevaDevolucion::class, ['venta' => $venta])
        ->assertSet('caja', (string) $turno->id)
        ->set('lineas.'.$venta->items()->value('id').'.cantidad', '1')
        ->set('motivo', 'Envase dañado')
        ->call('revisar')
        ->assertSee('Caja del efectivo')
        ->call('confirmar');

    expect(MovimientoCaja::query()->where('origen_tipo', 'devolucion')->value('turno_caja_id'))->toBe($turno->id)
        ->and((string) app(CerrarTurnoCaja::class)->ejecutar($this->admin, $turno, '70.00')->diferencia)->toBe('0.00');
});

test('el arqueo filtra lo de fuera de caja: cajas más fuera de caja dan el total de la sucursal', function () {
    $turno = app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->cajaA, '0');
    confirmarVenta($this->admin, pedidoDeAceite($this->admin, $this->sucursalA), ['metodo' => 'efectivo', 'turno_id' => $turno->id]);
    $fuera = confirmarVenta($this->admin, pedidoDeAceite($this->admin, $this->sucursalA, '2'), ['metodo' => 'efectivo', 'externo' => true]);
    reembolsarUno($this->admin, $fuera, ['externo' => true]);
    $hoy = Periodo::hoy();

    expect(Cobros::resumen($this->empresa->id, $hoy, $this->sucursalA->id, '', ReporteCaja::FUERA_DE_CAJA))
        ->toMatchArray(['efectivo' => '40.00', 'egresos_efectivo' => '20.00'])
        ->and(Cobros::resumen($this->empresa->id, $hoy, $this->sucursalA->id, '', $this->cajaA->id))
        ->toMatchArray(['efectivo' => '20.00', 'egresos_efectivo' => '0.00'])
        ->and(Cobros::resumen($this->empresa->id, $hoy, $this->sucursalA->id))
        ->toMatchArray(['efectivo' => '60.00', 'egresos_efectivo' => '20.00']);

    Livewire::test(Arqueo::class)->set('caja', 'externo')
        ->assertSet('pestana', 'movimientos')
        ->assertSee('Lo de fuera de caja no pasa por ningún cajón')
        ->assertSee($fuera->numero)->assertSee('Reembolso');

    $csv = $this->get(route('caja.exportar', ['fecha' => $hoy->desde->format('Y-m-d'), 'caja' => 'externo']))->streamedContent();
    expect(substr_count($csv, 'Fuera de caja'))->toBe(2)->and($csv)->not->toContain($this->cajaA->nombre);
});
