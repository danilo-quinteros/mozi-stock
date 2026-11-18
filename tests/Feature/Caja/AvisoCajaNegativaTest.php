<?php

use App\Domain\Caja\Actions\AbrirTurnoCaja;
use App\Livewire\Compras\NuevaCompra;
use App\Livewire\CuentasPagar\Detalle as CuentaPagarDetalle;
use App\Livewire\CuentasPagar\Lista as CuentasPagarLista;
use App\Livewire\Egresos\Panel as EgresosPanel;
use App\Livewire\Ventas\NuevaDevolucion;
use App\Models\CategoriaEgreso;
use App\Models\Compra;
use App\Models\CuentaPagar;
use App\Models\Egreso;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\TurnoCaja;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * Aviso de caja en negativo (18/11/2026): una salida de efectivo mayor que lo que, según el sistema, hay en el turno
 * elegido avisa antes de registrarse y sugiere «Fuera de caja» (p. ej. una compra pagada con dinero del
 * administrador). No bloquea: la acción y el arqueo no cambian. Caso real: fondo Bs 0, cobros en efectivo Bs 80 y una
 * compra de Bs 3.499 cargada al turno dejaron «Debe haber» en Bs −3.419 y un sobrante que no existía.
 */

const AVISO_CAJA_NEGATIVA = 'La caja quedaría en negativo';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 09:00', 'America/La_Paz'));
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->producto = productoDe($this->empresa, ['nombre' => 'Leche 1L', 'precio_consumidor' => '20.00']);
    $this->actingAs($this->admin);
    cargarStock($this->admin, $this->sucursal, $this->producto, '50');
    $this->proveedor = new Proveedor(['nombre' => 'Distribuidora Oriente']);
    $this->proveedor->save();
});

function abrirTurnoCon(string $fondo): TurnoCaja
{
    return app(AbrirTurnoCaja::class)->ejecutar(test()->admin, test()->sucursal->cajaPrincipal(), $fondo);
}

function venderEnTurno(TurnoCaja $turno, string $cantidad, string $metodo): void
{
    $pedido = crearPedido(test()->admin, test()->sucursal, [['producto_id' => test()->producto->id, 'cantidad' => $cantidad]]);
    confirmarVenta(test()->admin, $pedido, ['metodo' => $metodo, 'turno_id' => $turno->id]);
}

test('el caso del cierre: con Bs 80 en el turno, una compra de Bs 3.499 avisa y sugiere fuera de caja', function () {
    $turno = abrirTurnoCon('0.00');
    venderEnTurno($turno, '4', 'efectivo');      // Bs 80 en efectivo
    venderEnTurno($turno, '1', 'electronico');   // Bs 20 electrónico: no va al cajón

    expect($turno->efectivoEnCaja())->toBe('80.00');

    $pantalla = Livewire::test(NuevaCompra::class)
        ->assertSet('caja', (string) $turno->id)
        ->set('proveedor_id', (string) $this->proveedor->id)
        ->call('agregarProducto', $this->producto->id)->set('lineas.0.costo', '3499')
        ->assertSee(AVISO_CAJA_NEGATIVA)
        ->assertSee('hay Bs 80,00 en efectivo; esta salida de Bs 3.499,00 la dejaría en Bs −3.419,00.')
        ->assertSee('elige «Fuera de caja (externo)»')
        // Fuera de caja, electrónico o a crédito no sale efectivo del turno.
        ->set('caja', 'externo')->assertDontSee(AVISO_CAJA_NEGATIVA)
        ->set('caja', (string) $turno->id)->assertSee(AVISO_CAJA_NEGATIVA)
        ->set('metodo', 'electronico')->assertDontSee(AVISO_CAJA_NEGATIVA)
        // Mixto: solo cuenta la parte en efectivo.
        ->set('metodo', 'mixto')->set('efectivo', '80')->set('electronico', '3419')->assertDontSee(AVISO_CAJA_NEGATIVA)
        ->set('efectivo', '80.01')->set('electronico', '3418.99')->assertSee('la dejaría en Bs −0,01.')
        ->set('metodo', 'efectivo')->set('condicion', 'credito')->set('vencimiento', '2026-12-17')->assertDontSee(AVISO_CAJA_NEGATIVA)
        // Pago parcial: cuenta solo lo pagado ahora.
        ->set('condicion', 'parcial')->set('pagado', '80')->assertDontSee(AVISO_CAJA_NEGATIVA)
        ->set('pagado', '100')->assertSee('esta salida de Bs 100,00 la dejaría en Bs −20,00.')
        // La confirmación también lo muestra.
        ->set('condicion', 'contado')->set('vencimiento', '')
        ->call('revisar')->assertSet('confirmando', true)
        ->assertSeeHtml('data-test="caja-negativa"');

    // Solo avisa: la compra se registra igual en el turno y el arqueo sigue la misma cuenta.
    $pantalla->call('confirmar')->assertHasNoErrors();

    $compra = Compra::query()->sole();
    $turno->refresh();
    expect($compra->total)->toBe('3499.00')
        ->and($turno->efectivoEnCaja())->toBe('-3419.00')
        ->and($turno->calcularArqueo()['efectivo_esperado'])->toBe('-3419.00');
});

test('egresos: avisa solo cuando lo pagado en efectivo supera lo que hay en el turno', function () {
    $turno = abrirTurnoCon('80.00');
    $categoria = new CategoriaEgreso(['nombre' => 'Servicios']);
    $categoria->save();

    Livewire::test(EgresosPanel::class)->call('nuevoEgreso')
        ->assertSet('caja', (string) $turno->id)
        ->set('categoria_id', (string) $categoria->id)->set('descripcion', 'Reparación urgente')
        ->set('monto', '80')->assertDontSee(AVISO_CAJA_NEGATIVA)   // queda en Bs 0: no es negativo
        ->set('monto', '100')->assertSee('hay Bs 80,00 en efectivo; esta salida de Bs 100,00 la dejaría en Bs −20,00.')
        ->set('condicion', 'parcial')->set('pagado', '50')->assertDontSee(AVISO_CAJA_NEGATIVA)
        ->set('condicion', 'pendiente')->assertDontSee(AVISO_CAJA_NEGATIVA)
        ->set('condicion', 'pagado')->set('caja', 'externo')->assertDontSee(AVISO_CAJA_NEGATIVA)
        ->set('caja', (string) $turno->id)->assertSee(AVISO_CAJA_NEGATIVA)
        ->call('registrarEgreso')->assertHasNoErrors();

    expect(Egreso::query()->sole()->turno_caja_id)->toBe($turno->id)
        ->and($turno->efectivoEnCaja())->toBe('-20.00');
});

test('pago de una cuenta por pagar y reembolso de una devolución también avisan', function () {
    $turno = abrirTurnoCon('10.00');

    Livewire::test(CuentasPagarLista::class)->call('nuevaObligacion')
        ->set('proveedor_id', (string) $this->proveedor->id)->set('concepto', 'Préstamo anterior')
        ->set('importe', '500')->set('vencimiento', '2026-12-31')->call('registrarObligacion')->assertHasNoErrors();

    Livewire::test(CuentaPagarDetalle::class, ['cuenta' => CuentaPagar::query()->sole()])
        ->assertSet('caja', (string) $turno->id)
        ->set('monto', '10')->assertDontSee(AVISO_CAJA_NEGATIVA)
        ->set('monto', '500')->assertSee('esta salida de Bs 500,00 la dejaría en Bs −490,00.')
        ->set('metodo', 'electronico')->assertDontSee(AVISO_CAJA_NEGATIVA);

    // El cliente pagó con QR (nada entró al cajón) y se le devuelve en efectivo.
    $pedido = crearPedido($this->admin, $this->sucursal, [['producto_id' => $this->producto->id, 'cantidad' => '2']]);
    $venta = confirmarVenta($this->admin, $pedido, ['metodo' => 'electronico']);
    $linea = $venta->items()->value('id');

    Livewire::test(NuevaDevolucion::class, ['venta' => $venta])
        ->assertSet('caja', (string) $turno->id)
        ->set("lineas.{$linea}.cantidad", '2')
        ->assertSee('esta salida de Bs 40,00 la dejaría en Bs −30,00.')
        ->set('metodo', 'electronico')->assertDontSee(AVISO_CAJA_NEGATIVA);
});

test('aislamiento: el turno de otra empresa no se mira aunque se escriba su id', function () {
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $sucursalB = Sucursal::factory()->create(['empresa_id' => $empresaB->id]);
    $turnoB = app(AbrirTurnoCaja::class)->ejecutar($adminB, $sucursalB->cajaPrincipal(), '0.00');
    $this->actingAs($this->admin);

    // La empresa A no tiene turno abierto: solo «fuera de caja». Forzar el turno de B no muestra su efectivo.
    Livewire::test(NuevaCompra::class)
        ->set('proveedor_id', (string) $this->proveedor->id)
        ->call('agregarProducto', $this->producto->id)->set('lineas.0.costo', '500')
        ->set('caja', (string) $turnoB->id)
        ->assertDontSee(AVISO_CAJA_NEGATIVA)
        ->assertDontSee('turno #'.$turnoB->id);
});
