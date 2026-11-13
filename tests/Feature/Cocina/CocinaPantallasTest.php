<?php

use App\Domain\Caja\Actions\AbrirTurnoCaja;
use App\Domain\Despachos\Actions\MarcarEntregada;
use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Domain\Ventas\Actions\ConfirmarVenta;
use App\Enums\Capacidad;
use App\Enums\EstadoEntrega;
use App\Enums\Rubro;
use App\Livewire\Pedidos\Editor;
use App\Livewire\Ventas\Detalle;
use App\Livewire\Ventas\Entrega;
use App\Livewire\Ventas\NuevaDevolucion;
use App\Livewire\Ventas\Pos;
use App\Models\EventoEntrega;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Pedidos en cocina, etapa K1 (ESPECIFICACION_COCINA.md § 4.1, § 4.3 y § 5): «Nombre o mesa» y la casilla
 * «Entregado» al confirmar y en el POS, la entrega con un clic y el «Deshacer» de 10 segundos en el detalle de
 * venta (el panel de K2 está en CocinaPanelTest), el nombre o la mesa en la nota de venta impresa y el aviso de la
 * devolución. Restaurante con Casa Matriz; «Hamburguesa clásica» de Bs 25. Lunes 19 de octubre de 2026.
 */

function cocinaPantallaVender(object $t, ?string $mesa = null): Venta
{
    $pedido = crearPedido($t->admin, $t->matriz, [['producto_id' => $t->producto->id, 'cantidad' => '1']]);

    return app(ConfirmarVenta::class)->ejecutar($t->admin, $pedido, ['metodo' => 'efectivo', 'en_cuotas' => false,
        'cuota_inicial' => null, 'efectivo' => null, 'electronico' => null, 'clave' => (string) Str::uuid(), 'externo' => true], nombreMesa: $mesa);
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->producto = productoDe($this->empresa, ['nombre' => 'Hamburguesa clásica', 'codigo_barras' => '7770000000011', 'precio_consumidor' => '25.00']);
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 12:30', 'America/La_Paz'));
    cargarStock($this->admin, $this->matriz, $this->producto, '20');
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::Restaurante, [Capacidad::Cocina]);
    $this->mesero = User::factory()->vendedor($this->matriz)->create(['name' => 'Rosa Mamani']);
});

test('confirming in Pedidos y ventas asks «Nombre o mesa», the checkbox explains the kitchen and the badge says En cocina', function () {
    app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->matriz->cajaPrincipal(), '0.00');
    $pedido = crearPedido($this->admin, $this->matriz, [['producto_id' => $this->producto->id, 'cantidad' => '2']]);

    $editor = Livewire::test(Editor::class, ['pedido' => $pedido])
        ->set('nombreMesa', 'Mesa vieja')    // un valor previo no sobrevive a abrir la confirmación
        ->call('revisar')
        ->assertSet('nombreMesa', '')
        ->assertSet('entregado', false)
        ->assertSeeHtml('data-nombre-mesa')
        ->assertSee(['Nombre o mesa', 'Ej.: Juan, Mesa 4'])
        ->assertSee('Márcalo si el cliente recibe todo en este momento. Si no, el pedido queda En cocina hasta que lo entregues.')
        ->assertSeeHtml('class="badge warning" data-estado-resultante>En cocina</span>');

    // Más de 40 caracteres: el error se ve con la confirmación abierta y no se vende.
    $editor->set('nombreMesa', str_repeat('M', 41))
        ->call('confirmar')
        ->assertHasErrors(['nombreMesa'])
        ->assertSet('confirmando', true);
    expect(Venta::query()->count())->toBe(0);

    $editor->set('nombreMesa', 'Mesa 4')
        ->call('confirmar')
        ->assertHasNoErrors();

    $venta = Venta::query()->sole();
    expect($venta->estado_entrega)->toBe(EstadoEntrega::EnCocina)
        ->and($venta->nombre_mesa)->toBe('Mesa 4')
        ->and(stockDe($this->matriz, $this->producto))->toBe('18.000');
});

test('the POS has the same field and checkbox, and each new sale starts empty and unchecked', function () {
    app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->matriz->cajaPrincipal(), '0.00');

    $pos = Livewire::test(Pos::class)
        ->call('agregarDesdeBuscador', '7770000000011')
        ->assertSeeHtml('data-nombre-mesa')
        ->assertSeeHtml('class="badge warning" data-estado-resultante>En cocina</span>')
        ->set('nombreMesa', 'Juan')
        ->call('confirmar')
        ->assertHasNoErrors()
        ->assertSet('nombreMesa', '')
        ->assertSet('entregado', false);

    $primera = Venta::query()->sole();
    expect($primera->estado_entrega)->toBe(EstadoEntrega::EnCocina)
        ->and($primera->nombre_mesa)->toBe('Juan');

    $pos->call('agregarDesdeBuscador', '7770000000011')
        ->set('entregado', true)
        ->assertSeeHtml('class="badge success" data-estado-resultante>Entregado</span>')
        ->call('confirmar')
        ->assertHasNoErrors();

    $segunda = Venta::query()->latest('id')->first();
    expect($segunda->estado_entrega)->toBe(EstadoEntrega::Entregado)
        ->and($segunda->nombre_mesa)->toBeNull()
        ->and($segunda->entregado_por)->toBe($this->admin->id);
});

test('without the kitchen there is no «Nombre o mesa», and dispatch keeps its own texts', function () {
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::General, []);
    app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->matriz->cajaPrincipal(), '0.00');

    Livewire::test(Pos::class)
        ->call('agregarDesdeBuscador', '7770000000011')
        ->assertDontSeeHtml('data-nombre-mesa')
        ->assertDontSeeHtml('data-casilla-entregado');

    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::Distribuidora, [Capacidad::Despachos]);
    Livewire::test(Pos::class)
        ->call('agregarDesdeBuscador', '7770000000011')
        ->assertDontSeeHtml('data-nombre-mesa')
        ->assertSee('Si no, la venta queda Por despachar y el almacén debe autorizar la salida.')
        ->assertSeeHtml('class="badge warning" data-estado-resultante>Por despachar</span>');
});

test('the sale detail delivers with one click and offers «Deshacer» for 10 seconds to whoever delivered', function () {
    $venta = cocinaPantallaVender($this, 'Mesa 4');

    Livewire::actingAs($this->mesero)->test(Detalle::class, ['venta' => $venta])
        ->assertSeeLivewire(Entrega::class)
        ->assertSeeHtml('<b data-nombre-mesa>Mesa 4</b>');

    $this->travelTo(CarbonImmutable::parse('2026-10-19 12:40:00', 'America/La_Paz'));
    $entrega = Livewire::actingAs($this->mesero)->test(Entrega::class, ['ventaId' => $venta->id])
        ->assertSeeHtml('data-estado-entrega>En cocina</span>')
        ->assertSee('Venta confirmada: En cocina')
        ->assertSeeHtml('data-entregar-pedido')
        ->call('entregar')
        ->assertSeeHtml('data-estado-entrega>Entregado</span>')
        ->assertSee('Mesa 4 entregado')
        ->assertSeeHtml('data-deshacer-entrega')
        ->assertDontSeeHtml('data-entregar-pedido');

    expect($venta->fresh()->entregado_por)->toBe($this->mesero->id);

    $this->travel(8)->seconds();
    $entrega->call('deshacerEntregaPedido', $venta->id)
        ->assertSee('Mesa 4 volvió a cocina.')
        ->assertSeeHtml('data-estado-entrega>En cocina</span>')
        ->assertSee(['Corrección: Entregado → En cocina', 'Deshecho por el mismo usuario'])
        ->assertDontSeeHtml('data-aviso-deshacer');
    expect($venta->fresh()->estado_entrega)->toBe(EstadoEntrega::EnCocina);

    // Entregado otra vez: pasado el plazo el aviso desaparece y el servidor rechaza un clic tardío.
    $entrega->call('entregar')->assertSeeHtml('data-aviso-deshacer');
    $this->travel(11)->seconds();
    $entrega->call('$refresh')->assertDontSeeHtml('data-aviso-deshacer');
    $entrega->call('deshacerEntregaPedido', $venta->id)
        ->assertSee('Pasaron más de 10 segundos: pide a un administrador que corrija la entrega.');
    expect($venta->fresh()->estado_entrega)->toBe(EstadoEntrega::Entregado);
});

test('a second user with the detail open hears who delivered first, and nothing is recorded twice', function () {
    $venta = cocinaPantallaVender($this, 'Mesa 4');
    $this->travelTo(CarbonImmutable::parse('2026-10-19 12:40', 'America/La_Paz'));
    $abierta = Livewire::actingAs($this->admin)->test(Entrega::class, ['ventaId' => $venta->id])->assertSeeHtml('data-entregar-pedido');

    app(MarcarEntregada::class)->ejecutar($this->mesero, $venta);

    $abierta->call('entregar')
        ->assertSee('Ya entregado por Rosa Mamani a las 12:40.')
        ->assertDontSeeHtml('data-aviso-deshacer');
    expect(EventoEntrega::query()->where('venta_id', $venta->id)->where('estado_nuevo', 'entregado')->count())->toBe(1);

    // Otra sucursal u otra empresa no abren la entrega de esta venta.
    $vendedorNorte = User::factory()->vendedor(Sucursal::factory()->create(['empresa_id' => $this->empresa->id]))->create();
    Livewire::actingAs($vendedorNorte)->test(Entrega::class, ['ventaId' => $venta->id])->assertForbidden();
    [, $adminB] = empresaConAdministrador();
    Livewire::actingAs($adminB)->test(Entrega::class, ['ventaId' => $venta->id])->assertNotFound();
});

test('the administrator corrects a delivery back to En cocina from the detail, with a note', function () {
    $venta = cocinaPantallaVender($this, 'Mesa 4');
    app(MarcarEntregada::class)->ejecutar($this->mesero, $venta);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 13:05', 'America/La_Paz'));

    Livewire::actingAs($this->admin)->test(Entrega::class, ['ventaId' => $venta->id])
        ->assertSee('Deshacer entrega')
        ->call('abrir', 'corregir')
        ->assertSeeHtml('La venta volverá de <b>Entregado</b> a <b>En cocina</b>.')
        ->set('entregaNota', 'La mesa no recibió su pedido')
        ->call('confirmarEntrega')
        ->assertHasNoErrors()
        ->assertSee('Cambio deshecho: la venta '.$venta->codigo().' quedó «En cocina».');

    expect($venta->fresh()->estado_entrega)->toBe(EstadoEntrega::EnCocina);
});

test('the printed sale note shows «Nombre o mesa», and the return screen explains the kitchen without forcing the stock back', function () {
    $venta = cocinaPantallaVender($this, 'Mesa 4');

    $this->get(route('pedidos.imprimir', $venta->pedido_id))
        ->assertOk()
        ->assertSee('Nombre o mesa:')
        ->assertSee('Mesa 4');

    Livewire::actingAs($this->admin)->test(NuevaDevolucion::class, ['venta' => $venta])
        ->assertSee('El pedido está En cocina: si devuelves todo, queda Cancelado y sale de la lista. Marca «Vuelve al stock» solo si el producto no se usó.')
        ->assertDontSee('La venta está Por despachar')
        ->assertSeeHtml('wire:model.live="lineas.');

    // Sin nombre o mesa, la nota impresa no muestra la línea.
    $sinMesa = cocinaPantallaVender($this);
    $this->get(route('pedidos.imprimir', $sinMesa->pedido_id))->assertOk()->assertDontSee('Nombre o mesa:');
});
