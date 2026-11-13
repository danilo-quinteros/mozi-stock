<?php

use App\Domain\Caja\Actions\AbrirTurnoCaja;
use App\Domain\Despachos\Actions\DespacharVenta;
use App\Domain\Despachos\Actions\MarcarEntregada;
use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Domain\Ventas\Actions\ConfirmarVenta;
use App\Enums\Capacidad;
use App\Enums\EstadoEntrega;
use App\Enums\Rubro;
use App\Livewire\Catalogo\Productos;
use App\Livewire\Empresa\Usuarios;
use App\Livewire\Inventario\NuevoAjuste;
use App\Livewire\Pedidos\Despachos;
use App\Livewire\Pedidos\Editor;
use App\Livewire\Pedidos\Lista;
use App\Livewire\Ventas\Detalle;
use App\Livewire\Ventas\Entrega;
use App\Livewire\Ventas\NuevaDevolucion;
use App\Livewire\Ventas\Pos;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\EventoEntrega;
use App\Models\MovimientoCaja;
use App\Models\PagoVenta;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Despachos, etapa D2 (ESPECIFICACION_DESPACHOS.md § 4 y § 8): la casilla «Entregado» al confirmar y en el POS,
 * la pestaña Despachos con sus diálogos y export, la línea de tiempo del detalle, el permiso de almacén en
 * Usuarios y el «Reservado por despachar». Distribuidora con Casa Matriz y Sucursal Norte; Aceite 1L de
 * Bs 20 con 10 unidades en cada sucursal. Lunes 19 de octubre de 2026, 08:00 en La Paz.
 */

/**
 * Venta confirmada por la acción real, como la deja la pantalla con la casilla desmarcada (Por despachar).
 *
 * @param  array<string, mixed>  $pago
 */
function pantallaVender(object $t, string $cantidad, ?Sucursal $sucursal = null, array $pago = [], ?Cliente $cliente = null): Venta
{
    $pedido = crearPedido($t->admin, $sucursal ?? $t->matriz, [['producto_id' => $t->producto->id, 'cantidad' => $cantidad]],
        ['cliente_id' => ($cliente ?? $t->cliente)->id]);

    return app(ConfirmarVenta::class)->ejecutar($t->admin, $pedido, $pago + ['metodo' => 'efectivo', 'en_cuotas' => false,
        'cuota_inicial' => null, 'efectivo' => null, 'electronico' => null, 'clave' => (string) Str::uuid(), 'externo' => true]);
}

function pantallaVendedor(Sucursal $sucursal, bool $almacen = false): User
{
    $usuario = User::factory()->vendedor($sucursal)->create();
    $usuario->forceFill(['puede_despachar' => $almacen])->save();

    return $usuario;
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    $this->producto = productoDe($this->empresa, ['nombre' => 'Aceite 1L', 'codigo_barras' => '7771234500099', 'precio_consumidor' => '20.00']);
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 08:00', 'America/La_Paz'));
    cargarStock($this->admin, $this->matriz, $this->producto, '10');
    cargarStock($this->admin, $this->norte, $this->producto, '10');
    $this->cliente = new Cliente(['nombre' => 'Distribuidora El Sol', 'documento' => '7788990']);
    $this->cliente->save();
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::Distribuidora, [Capacidad::Despachos]);
    $this->almacen = pantallaVendedor($this->matriz, almacen: true);
    $this->vendedor = pantallaVendedor($this->matriz);
});

test('confirming in Pedidos y ventas: «Entregado» starts unchecked, the badge says Por despachar and so does the sale', function () {
    app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->matriz->cajaPrincipal(), '0.00');
    $pedido = crearPedido($this->admin, $this->matriz, [['producto_id' => $this->producto->id, 'cantidad' => '3']], ['cliente_id' => $this->cliente->id]);

    Livewire::test(Editor::class, ['pedido' => $pedido])
        ->set('entregado', true)            // un valor previo no sobrevive a abrir la confirmación
        ->call('revisar')
        ->assertSet('entregado', false)
        ->assertSee('Márcalo si el cliente recibe los productos en este momento. Si no, la venta queda Por despachar y el almacén debe autorizar la salida.')
        ->assertSeeHtml('class="badge warning" data-estado-resultante>Por despachar</span>')
        ->call('confirmar')
        ->assertHasNoErrors();

    $venta = Venta::query()->sole();
    expect($venta->estado_entrega)->toBe(EstadoEntrega::PorDespachar)
        ->and($venta->entregado_por)->toBeNull()
        ->and(stockDe($this->matriz, $this->producto))->toBe('7.000');

    // Marcada: Entregado al instante, a nombre de quien vendió.
    $otro = crearPedido($this->admin, $this->matriz, [['producto_id' => $this->producto->id, 'cantidad' => '1']]);
    Livewire::test(Editor::class, ['pedido' => $otro])
        ->call('revisar')
        ->set('entregado', true)
        ->assertSeeHtml('class="badge success" data-estado-resultante>Entregado</span>')
        ->call('confirmar')
        ->assertHasNoErrors();

    $entregada = Venta::query()->latest('id')->first();
    expect($entregada->estado_entrega)->toBe(EstadoEntrega::Entregado)
        ->and($entregada->entregado_por)->toBe($this->admin->id);
});

test('the POS has the same checkbox: unchecked sells Por despachar, checked sells Entregado, and each new sale starts unchecked', function () {
    app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->matriz->cajaPrincipal(), '0.00');

    $pos = Livewire::test(Pos::class)
        ->assertSet('sucursal_id', (string) $this->matriz->id)
        ->call('agregarDesdeBuscador', '7771234500099')
        ->assertSet('entregado', false)
        ->assertSeeHtml('data-casilla-entregado')
        ->assertSeeHtml('class="badge warning" data-estado-resultante>Por despachar</span>')
        ->call('confirmar')
        ->assertHasNoErrors();

    expect(Venta::query()->sole()->estado_entrega)->toBe(EstadoEntrega::PorDespachar);

    $pos->call('agregarDesdeBuscador', '7771234500099')
        ->set('entregado', true)
        ->assertSeeHtml('class="badge success" data-estado-resultante>Entregado</span>')
        ->call('confirmar')
        ->assertHasNoErrors()
        ->assertSet('entregado', false);

    $segunda = Venta::query()->latest('id')->first();
    expect($segunda->estado_entrega)->toBe(EstadoEntrega::Entregado)
        ->and($segunda->entregado_por)->toBe($this->admin->id);
});

test('without the capability nothing new is shown: no checkbox, no tab, no export, and every sale is delivered', function () {
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::General, []);
    app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->matriz->cajaPrincipal(), '0.00');

    Livewire::test(Pos::class)
        ->call('agregarDesdeBuscador', '7771234500099')
        ->assertDontSeeHtml('data-casilla-entregado')
        ->assertDontSeeHtml('data-estado-resultante')
        ->call('confirmar')
        ->assertHasNoErrors();

    $venta = Venta::query()->sole();
    expect($venta->estado_entrega)->toBe(EstadoEntrega::Entregado)
        ->and(EventoEntrega::query()->count())->toBe(0);

    Livewire::withQueryParams(['pestana' => 'despachos'])->test(Lista::class)
        ->assertSet('pestana', 'todos')
        ->assertDontSee('Despachos');
    Livewire::test(Despachos::class)->assertNotFound();
    $this->get(route('pedidos.despachos.exportar'))->assertNotFound();
    Livewire::test(Detalle::class, ['venta' => $venta])->assertDontSeeHtml('data-entrega-venta');
});

test('the Despachos tab lists only this company\'s sales and a seller only those of the assigned branch', function () {
    $matriz = pantallaVender($this, '3');
    $norte = pantallaVender($this, '2', $this->norte);
    $entregada = pantallaVender($this, '1');
    app(MarcarEntregada::class)->ejecutar($this->admin, $entregada, despacharTambien: true);

    // Otra empresa con su propia venta Por despachar.
    [$otra, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $sucursalB = Sucursal::factory()->create(['empresa_id' => $otra->id]);
    $productoB = productoDe($otra, ['nombre' => 'Arroz B', 'precio_consumidor' => '10.00']);
    cargarStock($adminB, $sucursalB, $productoB, '5');
    app(CambiarFuncionesEmpresa::class)->ejecutar($adminB, $otra, Rubro::Distribuidora, [Capacidad::Despachos]);
    $ventaB = confirmarVenta($adminB, crearPedido($adminB, $sucursalB, [['producto_id' => $productoB->id, 'cantidad' => '1']]), ['metodo' => 'efectivo', 'externo' => true]);

    Livewire::actingAs($adminB)->test(Despachos::class)
        ->assertSee($ventaB->codigo())
        ->assertDontSee('Distribuidora El Sol')
        ->call('abrirEntrega', $matriz->id, 'despachar')
        ->assertNotFound();

    Livewire::actingAs($this->admin)->withQueryParams(['pestana' => 'despachos'])->test(Lista::class)
        ->assertSet('pestana', 'despachos')
        ->assertSeeLivewire(Despachos::class);

    Livewire::actingAs($this->admin)->test(Despachos::class)
        ->assertSee([$matriz->codigo(), $norte->codigo(), 'Casa Matriz', 'Sucursal Norte'])
        ->assertDontSee($entregada->codigo())
        ->assertViewHas('conteos', ['por_despachar' => 2, 'despachado' => 0, 'entregado' => 1, 'cancelado' => 0])
        ->set('sucursal', (string) $this->norte->id)
        ->assertSee($norte->codigo())->assertDontSee($matriz->codigo())
        ->set('sucursal', '')
        ->set('estado', 'entregado')
        ->assertSee($entregada->codigo())->assertDontSee($matriz->codigo())
        ->set('estado', 'por_despachar')
        ->set('cliente', 'Nadie así')
        ->assertDontSee($matriz->codigo())
        ->set('cliente', '7788990')
        ->assertSee($matriz->codigo());

    // El vendedor queda en su sucursal aunque pida otra.
    Livewire::actingAs($this->almacen)->test(Despachos::class)
        ->set('sucursal', (string) $this->norte->id)
        ->assertSee($matriz->codigo())
        ->assertDontSee($norte->codigo())
        ->assertDontSee('Sucursal Norte');
});

test('the warehouse user dispatches from the tab with «Retira»; a double click or a late second user records nothing twice', function () {
    $venta = pantallaVender($this, '3');

    $tardio = Livewire::actingAs($this->admin)->test(Despachos::class)->call('abrirEntrega', $venta->id, 'despachar');

    $pantalla = Livewire::actingAs($this->almacen)->test(Despachos::class)
        ->assertSee('Despachar y entregar')
        ->call('abrirEntrega', $venta->id, 'despachar')
        ->assertSee(['Despachar venta', 'Productos a entregar', 'Aceite 1L', 'Confirmar despacho'])
        ->set('entregaRetira', '  Juan Pérez  ')
        ->call('confirmarEntrega')
        ->assertHasNoErrors()
        ->assertSet('entregaVentaId', null)
        ->assertSee('Venta '.$venta->codigo().' despachada.');

    // Segundo clic con el diálogo ya cerrado: no hay nada que repetir.
    $pantalla->call('confirmarEntrega')->assertHasNoErrors();

    $venta->refresh();
    expect($venta->estado_entrega)->toBe(EstadoEntrega::Despachado)
        ->and($venta->despachado_por)->toBe($this->almacen->id)
        ->and($venta->despacho_retira)->toBe('Juan Pérez')
        ->and(EventoEntrega::query()->where('estado_nuevo', 'despachado')->count())->toBe(1);

    // El administrador tenía el diálogo abierto desde antes: se le explica quién ganó.
    $this->actingAs($this->admin);
    $tardio->call('confirmarEntrega')
        ->assertSet('entregaVentaId', null)
        ->assertSee('Ya despachada por '.$this->almacen->name.' a las 08:00.');
    expect(EventoEntrega::query()->where('estado_nuevo', 'despachado')->count())->toBe(1);
});

test('a seller without the warehouse permission, or from another branch, cannot open the dispatch dialog', function () {
    $venta = pantallaVender($this, '3');
    $norte = pantallaVender($this, '2', $this->norte);

    Livewire::actingAs($this->vendedor)->test(Despachos::class)
        ->assertSee($venta->codigo())
        ->assertDontSee('Despachar y entregar')
        ->call('abrirEntrega', $venta->id, 'despachar')
        ->assertForbidden();

    Livewire::actingAs($this->almacen)->test(Despachos::class)
        ->call('abrirEntrega', $norte->id, 'despachar')
        ->assertForbidden();

    // Un diálogo inventado tampoco existe.
    Livewire::actingAs($this->admin)->test(Despachos::class)
        ->call('abrirEntrega', $venta->id, 'borrar')
        ->assertNotFound();

    expect($venta->fresh()->estado_entrega)->toBe(EstadoEntrega::PorDespachar)
        ->and($norte->fresh()->estado_entrega)->toBe(EstadoEntrega::PorDespachar);
});

test('marking delivered links to the payment history and records no payment; dispatch and deliver writes two events', function () {
    $credito = pantallaVender($this, '5', pago: ['en_cuotas' => true, 'cuota_inicial' => '0']);
    app(DespacharVenta::class)->ejecutar($this->almacen, $credito, 'Juan Pérez');
    $pagos = PagoVenta::query()->count();
    $movimientos = MovimientoCaja::query()->count();

    Livewire::actingAs($this->vendedor)->test(Despachos::class)
        ->set('estado', 'despachado')
        ->assertSee(['Marcar entregado', 'Retira: Juan Pérez', 'Saldo Bs 100,00'])
        ->call('abrirEntrega', $credito->id, 'entregar')
        ->assertSee(['Recibió (opcional)', 'Saldo pendiente Bs 100,00', 'Entregar no registra pagos'])
        ->assertSeeHtml('href="'.route('creditos.historial', $credito).'"')
        ->set('entregaRecibio', 'Rosa Quispe')
        ->set('entregaNota', 'Dejado en portería')
        ->call('confirmarEntrega')
        ->assertHasNoErrors()
        ->assertSee('Venta '.$credito->codigo().' entregada.');

    $credito->refresh();
    expect($credito->estado_entrega)->toBe(EstadoEntrega::Entregado)
        ->and($credito->entregado_por)->toBe($this->vendedor->id)
        ->and($credito->entrega_recibio)->toBe('Rosa Quispe')
        ->and($credito->saldo_pendiente)->toBe('100.00')
        ->and(PagoVenta::query()->count())->toBe($pagos)
        ->and(MovimientoCaja::query()->count())->toBe($movimientos);

    $retiro = pantallaVender($this, '1');
    Livewire::actingAs($this->almacen)->test(Despachos::class)
        ->call('abrirEntrega', $retiro->id, 'despachar_entregar')
        ->assertSee(['Productos a entregar', 'Recibió (opcional)'])
        ->set('entregaRecibio', 'Mario')
        ->call('confirmarEntrega')
        ->assertHasNoErrors();

    expect($retiro->fresh()->estado_entrega)->toBe(EstadoEntrega::Entregado)
        ->and(EventoEntrega::query()->where('venta_id', $retiro->id)->pluck('usuario_id', 'estado_nuevo')->all())
        ->toBe(['por_despachar' => $this->admin->id, 'despachado' => $this->almacen->id, 'entregado' => $this->almacen->id]);
});

test('the sale detail shows the badge and timeline; the administrator undoes a dispatch only with a note', function () {
    $venta = pantallaVender($this, '3');
    app(DespacharVenta::class)->ejecutar($this->almacen, $venta, 'Juan Pérez');

    Livewire::actingAs($this->admin)->test(Detalle::class, ['venta' => $venta])
        ->assertSeeLivewire(Entrega::class);

    // El vendedor ve el estado y puede marcar entregado, pero no corregir.
    Livewire::actingAs($this->vendedor)->test(Entrega::class, ['ventaId' => $venta->id])
        ->assertSeeHtml('data-estado-entrega>Despachado</span>')
        ->assertSee(['Venta confirmada: Por despachar', 'Despachó '.$this->almacen->name.' a las 08:00', 'Retira: Juan Pérez', 'Marcar entregado'])
        ->assertDontSee('Deshacer despacho')
        ->call('abrir', 'corregir')
        ->assertForbidden();

    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:15', 'America/La_Paz'));
    Livewire::actingAs($this->admin)->test(Entrega::class, ['ventaId' => $venta->id])
        ->call('abrir', 'corregir')
        ->assertSee(['Deshacer último cambio', 'La venta volverá de'])
        ->call('confirmarEntrega')
        ->assertHasErrors(['nota'])
        ->set('entregaNota', 'Se despachó la venta equivocada')
        ->call('confirmarEntrega')
        ->assertHasNoErrors()
        ->assertSee('Cambio deshecho: la venta '.$venta->codigo().' quedó «Por despachar».')
        ->assertSee(['Corrección: Despachado → Por despachar', 'Se despachó la venta equivocada'])
        ->assertSeeHtml('data-estado-entrega>Por despachar</span>');

    expect($venta->fresh()->estado_entrega)->toBe(EstadoEntrega::PorDespachar)
        ->and(EventoEntrega::query()->where('venta_id', $venta->id)->where('es_correccion', true)->count())->toBe(1);

    // Otra empresa no abre la entrega de esta venta.
    [, $adminB] = empresaConAdministrador();
    Livewire::actingAs($adminB)->test(Entrega::class, ['ventaId' => $venta->id])->assertNotFound();
});

test('the export follows the screen filters, stays inside the company and forces the seller\'s branch', function () {
    $matriz = pantallaVender($this, '3');
    $norte = pantallaVender($this, '2', $this->norte);
    app(DespacharVenta::class)->ejecutar($this->almacen, $matriz, 'Juan Pérez');

    $csv = $this->actingAs($this->admin)->get(route('pedidos.despachos.exportar', ['entrega' => 'despachado']))->assertOk()->streamedContent();
    expect($csv)->toContain('Estado de entrega')
        ->toContain($matriz->codigo())
        ->toContain('Juan Pérez')
        ->toContain($this->almacen->name)
        ->not->toContain($norte->codigo());

    $csv = $this->actingAs($this->admin)->get(route('pedidos.despachos.exportar'))->assertOk()->streamedContent();
    expect($csv)->toContain($norte->codigo())->not->toContain($matriz->codigo());

    // El vendedor de matriz pide la sucursal Norte: recibe la suya, sin la venta de Norte.
    $csv = $this->actingAs($this->vendedor)->get(route('pedidos.despachos.exportar', ['suc' => $this->norte->id]))->assertOk()->streamedContent();
    expect($csv)->not->toContain($norte->codigo());

    [, $adminB] = empresaConAdministrador();
    // La empresa B no tiene la capacidad: la ruta ni existe para ella.
    $this->actingAs($adminB)->get(route('pedidos.despachos.exportar'))->assertNotFound();
});

test('the administrator grants the warehouse permission to a seller, and the change is audited', function () {
    Livewire::actingAs($this->admin)->test(Usuarios::class)
        ->call('editar', $this->vendedor->id)
        ->assertSet('puede_despachar', false)
        ->assertSeeHtml('data-permiso-almacen')
        ->set('caja_id', (string) $this->matriz->cajaPrincipal()->id)
        ->set('puede_despachar', true)
        ->call('guardar')
        ->assertHasNoErrors()
        ->assertSee('Almacén');

    expect($this->vendedor->fresh()->puede_despachar)->toBeTrue();
    $registro = Auditoria::query()->where('accion', 'usuario.actualizado')->where('entidad_id', $this->vendedor->id)->sole();
    expect($registro->antes['puede_despachar'])->toBeFalse()
        ->and($registro->despues['puede_despachar'])->toBeTrue();

    // El administrador siempre puede despachar: la marca no se guarda en administradores.
    Livewire::actingAs($this->admin)->test(Usuarios::class)
        ->call('editar', $this->vendedor->id)
        ->set('rol', 'administrador')
        ->assertDontSeeHtml('data-permiso-almacen')
        ->assertSee('Los administradores siempre pueden despachar.')
        ->call('guardar')
        ->assertHasNoErrors();
    expect($this->vendedor->fresh()->puede_despachar)->toBeFalse();

    // Sin la capacidad, la casilla no aparece y una petición manipulada no cambia la marca.
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::General, []);
    Livewire::actingAs($this->admin)->test(Usuarios::class)
        ->call('editar', $this->almacen->id)
        ->assertDontSeeHtml('data-permiso-almacen')
        ->assertDontSee('Almacén')
        ->set('puede_despachar', false)
        ->call('guardar')
        ->assertHasNoErrors();
    expect($this->almacen->fresh()->puede_despachar)->toBeTrue();
});

test('products, the adjustment screen and the return screen show what is reserved by dispatch', function () {
    $venta = pantallaVender($this, '3');
    pantallaVender($this, '2', $this->norte);

    Livewire::actingAs($this->admin)->test(Productos::class)
        ->set('sucursal', (string) $this->matriz->id)
        ->assertSee('Reservado por despachar: 3');

    Livewire::actingAs($this->admin)->test(NuevoAjuste::class)
        ->set('sucursal_id', (string) $this->matriz->id)
        ->assertSee('Hay 3 unidades reservadas por despachar; inclúyelas en el conteo físico, no en el stock.')
        ->set('sucursal_id', (string) $this->norte->id)
        ->assertSee('Hay 2 unidades reservadas por despachar;');

    Livewire::actingAs($this->admin)->test(NuevaDevolucion::class, ['venta' => $venta])
        ->assertSee('La venta está Por despachar: la mercadería sigue en el almacén, así que todo vuelve al stock. Si devuelves todo, la venta queda Cancelada.');

    // Despachada, la reserva desaparece de las pantallas.
    app(DespacharVenta::class)->ejecutar($this->admin, $venta);
    Livewire::actingAs($this->admin)->test(Productos::class)
        ->set('sucursal', (string) $this->matriz->id)
        ->assertDontSee('Reservado por despachar');
    Livewire::actingAs($this->admin)->test(NuevaDevolucion::class, ['venta' => $venta])
        ->assertDontSee('La venta está Por despachar');
});
