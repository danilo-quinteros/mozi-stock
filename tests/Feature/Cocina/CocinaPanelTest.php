<?php

use App\Domain\Caja\Actions\AbrirTurnoCaja;
use App\Domain\Caja\Actions\CerrarTurnoCaja;
use App\Domain\Despachos\Consultas\PedidosEnCocina;
use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Domain\Ventas\Actions\ConfirmarVenta;
use App\Domain\Ventas\Actions\RegistrarDevolucion;
use App\Enums\Capacidad;
use App\Enums\EstadoEntrega;
use App\Enums\Rubro;
use App\Livewire\Cocina\BotonFlotante;
use App\Livewire\Cocina\Panel;
use App\Livewire\Empresa\FuncionesNegocio;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\EventoEntrega;
use App\Models\MovimientoCaja;
use App\Models\PagoVenta;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Pedidos en cocina, etapa K2 (ESPECIFICACION_COCINA.md § 4.2 a § 4.4, § 5.4, § 5.5 y § 8): botón flotante con
 * contador y alerta, panel lateral con «Ver» y «Entregar», actualización automática, tiempo en cocina y el
 * límite de demora en Funciones del negocio. Restaurante con Casa Matriz y Sucursal Norte; «Hamburguesa
 * clásica» de Bs 25 con stock en ambas. Lunes 19 de octubre de 2026 en La Paz.
 */

function panelHora(string $momento): void
{
    test()->travelTo(CarbonImmutable::parse($momento, 'America/La_Paz'));
}

/**
 * Venta del administrador con la acción real, En cocina salvo que se pague otra cosa.
 *
 * @param  list<array{0: Producto, 1: string}>|null  $lineas  producto y cantidad
 * @param  array<string, mixed>  $pedido  datos extra del pedido (nota, cliente)
 * @param  array<string, mixed>  $pago
 */
function panelVender(object $t, ?string $mesa = null, ?Sucursal $sucursal = null, ?array $lineas = null, array $pedido = [], array $pago = []): Venta
{
    $lineas ??= [[$t->producto, '1']];
    $registro = crearPedido($t->admin, $sucursal ?? $t->matriz,
        array_map(fn (array $l): array => ['producto_id' => $l[0]->id, 'cantidad' => $l[1]], $lineas), $pedido);

    return app(ConfirmarVenta::class)->ejecutar($t->admin, $registro, $pago + ['metodo' => 'efectivo', 'en_cuotas' => false,
        'cuota_inicial' => null, 'efectivo' => null, 'electronico' => null, 'clave' => (string) Str::uuid(), 'externo' => true], nombreMesa: $mesa);
}

function panelDevolver(User $admin, Venta $venta, string $cantidad): void
{
    $linea = VentaItem::query()->where('venta_id', $venta->id)->orderBy('orden')->value('id');

    app(RegistrarDevolucion::class)->ejecutar($admin, $venta, [
        'lineas' => [$linea => ['cantidad' => $cantidad, 'reingresa' => false]],
        'motivo' => 'Pidió menos', 'metodo' => 'efectivo', 'efectivo' => null, 'electronico' => null,
        'externo' => true, 'clave' => (string) Str::uuid(),
    ]);
}

/**
 * Cantidad de consultas SQL que hace $accion.
 */
function panelConsultas(callable $accion): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $accion();
    $total = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $total;
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    $this->producto = productoDe($this->empresa, ['nombre' => 'Hamburguesa clásica', 'precio_consumidor' => '25.00']);
    $this->actingAs($this->admin);
    panelHora('2026-10-19 12:00');
    cargarStock($this->admin, $this->matriz, $this->producto, '100');
    cargarStock($this->admin, $this->norte, $this->producto, '100');
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::Restaurante, [Capacidad::Cocina]);
    $this->mesero = User::factory()->vendedor($this->matriz)->create(['name' => 'Rosa Mamani']);
});

test('without the kitchen there is no button or panel and every sale stays Entregado; with it the layout loads the button', function () {
    guiaTerminada($this->empresa);
    $this->get(route('dashboard'))->assertOk()->assertSee('data-cocina-boton', false);

    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::General, []);
    $venta = panelVender($this);

    expect($venta->estado_entrega)->toBe(EstadoEntrega::Entregado);
    $this->get(route('dashboard'))->assertOk()->assertDontSee('data-cocina-boton', false)->assertDontSee('cocina-panel', false);
    Livewire::test(BotonFlotante::class)->assertDontSeeHtml('data-cocina-boton');
    Livewire::test(Panel::class)->dispatch('abrir-panel-cocina')->assertSet('abierto', false)->assertDontSeeHtml('data-cocina-panel');

    // El superadministrador no pertenece a una empresa: nunca ve el botón.
    $this->actingAs(User::factory()->superadmin()->create())->followingRedirects()->get(route('dashboard'))
        ->assertOk()->assertDontSee('data-cocina-boton', false);
});

test('a seller sees the button only with an open (shared) shift, and only the orders of their branch', function () {
    $mesa = panelVender($this, 'Mesa 4');
    panelHora('2026-10-19 12:05');
    panelVender($this, 'Juan');
    $norte = panelVender($this, 'Norte 1', $this->norte);

    // Sin turno abierto no hay botón ni panel.
    $boton = Livewire::actingAs($this->mesero)->test(BotonFlotante::class)->assertDontSeeHtml('data-cocina-boton');
    Livewire::actingAs($this->mesero)->test(Panel::class)->dispatch('abrir-panel-cocina')
        ->assertSet('abierto', false)->assertDontSeeHtml('data-cocina-panel');

    // El administrador abre la caja compartida de Casa Matriz, que es la asignada al vendedor.
    $turno = app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->matriz->cajaPrincipal(), '0.00');
    $boton->dispatch('turno-modificado')
        ->assertSeeHtml('data-cocina-boton')
        ->assertSeeHtml('data-cocina-contador>2</span>')
        ->assertSeeHtml('wire:poll.visible.10s');

    $panel = Livewire::actingAs($this->mesero)->test(Panel::class)->dispatch('abrir-panel-cocina')
        ->assertSet('abierto', true)
        ->assertSeeHtml('wire:poll.visible.5s')
        ->assertSeeHtml('data-cocina-total>(2)</span>')
        ->assertSeeInOrder(['Mesa 4', 'Juan'])      // del más antiguo al más nuevo
        ->assertDontSee('Norte 1')
        ->assertDontSeeHtml('data-cocina-sucursal');  // el vendedor no elige sucursal

    // Una sucursal enviada a mano no cambia nada: sigue viendo solo la suya.
    $panel->set('sucursal', (string) $this->norte->id)
        ->assertSet('sucursal', '')
        ->assertDontSee('Norte 1')
        ->assertSeeHtml('data-cocina-pedido="'.$mesa->id.'"');

    // Ni entregar ni ver un pedido de otra sucursal cambiando el ID.
    Livewire::actingAs($this->mesero)->test(Panel::class)->call('entregarPedido', $norte->id)->assertForbidden();
    Livewire::actingAs($this->mesero)->test(Panel::class)->call('ver', $norte->id)->assertForbidden();
    expect($norte->refresh()->estado_entrega)->toBe(EstadoEntrega::EnCocina);

    // Al cerrar el turno, el botón desaparece y el panel abierto se cierra solo.
    app(CerrarTurnoCaja::class)->ejecutar($this->admin, $turno, '0.00');
    Livewire::actingAs($this->mesero);
    $boton->call('$refresh')->assertDontSeeHtml('data-cocina-boton');
    $panel->call('$refresh')->assertSet('abierto', false)->assertDontSeeHtml('data-cocina-panel');
});

test('an administrator sees the button without a shift, starting from the branch chosen in the side menu', function () {
    panelVender($this, 'Mesa 4');
    panelVender($this, 'Norte 1', $this->norte);
    [, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $sucursalB = Sucursal::factory()->create(['empresa_id' => $adminB->empresa_id, 'nombre' => 'Otra empresa']);
    $this->actingAs($this->admin);

    // Sin sucursal elegida en el menú: todas las de su empresa.
    Livewire::test(BotonFlotante::class)->assertSeeHtml('data-cocina-contador>2</span>');
    Livewire::test(Panel::class)->dispatch('abrir-panel-cocina')
        ->assertSet('sucursal', '')
        ->assertSee(['Mesa 4', 'Norte 1'])
        ->assertSeeHtml('class="cocina-sucursal-nombre">Sucursal Norte</small>');

    session([User::SESION_SUCURSAL => $this->matriz->id]);
    Livewire::test(BotonFlotante::class)->assertSeeHtml('data-cocina-contador>1</span>');

    $panel = Livewire::test(Panel::class)->dispatch('abrir-panel-cocina')
        ->assertSet('sucursal', (string) $this->matriz->id)
        ->assertSeeHtml('data-cocina-sucursal')
        ->assertSeeHtml('data-cocina-total>(1)</span>')
        ->assertSee('Mesa 4')
        ->assertDontSee('Norte 1');

    $panel->set('sucursal', (string) $this->norte->id)->assertSee('Norte 1')->assertDontSee('Mesa 4');
    $panel->set('sucursal', '')->assertSee(['Mesa 4', 'Norte 1']);

    // Una sucursal de otra empresa se trata como «todas», que nunca sale de la empresa del usuario.
    $panel->set('sucursal', (string) $sucursalB->id)->assertSet('sucursal', '')->assertSeeHtml('data-cocina-total>(2)</span>');
});

test('«Entregar» in the panel is one click with «Deshacer», never touches money, and a second click or user changes nothing', function () {
    app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->matriz->cajaPrincipal(), '0.00');
    $mesa = panelVender($this, 'Mesa 4');
    panelVender($this, 'Juan');
    $pagos = PagoVenta::query()->count();
    $movimientos = MovimientoCaja::query()->count();

    panelHora('2026-10-19 12:40:00');
    $panelAdmin = Livewire::test(Panel::class)->dispatch('abrir-panel-cocina')->assertSeeHtml('data-cocina-pedido="'.$mesa->id.'"');

    $panel = Livewire::actingAs($this->mesero)->test(Panel::class)->dispatch('abrir-panel-cocina')
        ->call('entregarPedido', $mesa->id)
        ->assertDispatched('cocina-actualizada')
        ->assertSeeHtml('data-aviso-deshacer')
        ->assertSee('Mesa 4 entregado')
        ->assertDontSeeHtml('data-cocina-pedido="'.$mesa->id.'"')
        ->assertSeeHtml('data-cocina-total>(1)</span>');

    $mesa->refresh();
    expect($mesa->estado_entrega)->toBe(EstadoEntrega::Entregado)
        ->and($mesa->entregado_por)->toBe($this->mesero->id)
        ->and(EventoEntrega::query()->where('venta_id', $mesa->id)->count())->toBe(2)
        ->and(PagoVenta::query()->count())->toBe($pagos)
        ->and(MovimientoCaja::query()->count())->toBe($movimientos);

    // El administrador tenía el panel abierto con el pedido todavía en la lista.
    Livewire::actingAs($this->admin);
    $panelAdmin->call('entregarPedido', $mesa->id)
        ->assertSee('Ya entregado por Rosa Mamani a las 12:40.')
        ->assertDontSeeHtml('data-cocina-pedido="'.$mesa->id.'"');
    expect(EventoEntrega::query()->where('venta_id', $mesa->id)->count())->toBe(2);

    // Quien entregó lo deshace dentro de los 10 segundos y el pedido vuelve a la lista.
    panelHora('2026-10-19 12:40:08');
    Livewire::actingAs($this->mesero);
    $panel->call('deshacerEntregaPedido', $mesa->id)
        ->assertSee('Mesa 4 volvió a cocina.')
        ->assertDispatched('cocina-actualizada')
        ->assertSeeHtml('data-cocina-pedido="'.$mesa->id.'"')
        ->assertSeeHtml('data-cocina-total>(2)</span>');
    expect($mesa->refresh()->estado_entrega)->toBe(EstadoEntrega::EnCocina);
});

test('company B neither sees nor delivers the orders of company A', function () {
    $mesa = panelVender($this, 'Mesa 4');
    [$empresaB, $adminB] = empresaConAdministrador();
    app(CambiarFuncionesEmpresa::class)->ejecutar($adminB, $empresaB, Rubro::Restaurante, [Capacidad::Cocina]);

    Livewire::actingAs($adminB)->test(BotonFlotante::class)->assertSeeHtml('data-cocina-contador>0</span>')->assertSeeHtml('cocina-fab is-vacio');
    Livewire::actingAs($adminB)->test(Panel::class)->dispatch('abrir-panel-cocina')
        ->assertSeeHtml('data-cocina-vacio')
        ->assertDontSee('Mesa 4');
    Livewire::actingAs($adminB)->test(Panel::class)->call('entregarPedido', $mesa->id)->assertNotFound();
    Livewire::actingAs($adminB)->test(Panel::class)->call('ver', $mesa->id)->assertNotFound();

    expect(PedidosEnCocina::conteo($adminB, null, 15))->toBe(['total' => 0, 'demorados' => 0])
        ->and(PedidosEnCocina::lista($adminB, $this->matriz->id))->toHaveCount(0);

    $this->actingAs($this->admin);
    expect($mesa->refresh()->estado_entrega)->toBe(EstadoEntrega::EnCocina);
});

test('time in the kitchen comes from the server clock: with 15 minutes, 16 is delayed and 14 or 15 are not', function () {
    $mesa = panelVender($this, 'Mesa 4');               // 12:00
    panelHora('2026-10-19 12:02');
    $juan = panelVender($this, 'Juan');

    panelHora('2026-10-19 12:15:59');                     // Mesa 4: 15 min; Juan: 13 min
    expect(PedidosEnCocina::conteo($this->admin, null, 15))->toBe(['total' => 2, 'demorados' => 0]);
    Livewire::test(BotonFlotante::class)->assertSeeHtml('data-cocina-contador>2</span>')->assertDontSeeHtml('is-demora');

    panelHora('2026-10-19 12:16:30');                     // Mesa 4: 16 min; Juan: 14 min
    expect(PedidosEnCocina::conteo($this->admin, null, 15))->toBe(['total' => 2, 'demorados' => 1])
        ->and(PedidosEnCocina::demorado($mesa->refresh(), 15))->toBeTrue()
        ->and(PedidosEnCocina::demorado($juan->refresh(), 15))->toBeFalse();
    Livewire::test(BotonFlotante::class)->assertSeeHtml('cocina-fab is-demora');
    Livewire::test(Panel::class)->dispatch('abrir-panel-cocina')
        ->assertSeeHtml('data-cocina-pedido="'.$mesa->id.'" data-demora="1"')
        ->assertSeeHtml('data-cocina-pedido="'.$juan->id.'" data-demora="0"')
        ->assertSee(['hace 16 min', 'hace 14 min', 'con demora']);

    // El límite es de la empresa: con 20 minutos ya no hay demora.
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::Restaurante, [Capacidad::Cocina], 20);
    Livewire::test(BotonFlotante::class)->assertDontSeeHtml('is-demora');

    panelHora('2026-10-19 13:05:30');
    Livewire::test(Panel::class)->dispatch('abrir-panel-cocina')->assertSee(['hace 1 h 05 min', 'hace 1 h 03 min']);
    expect(PedidosEnCocina::textoTiempo(0))->toBe('hace 0 min')
        ->and(PedidosEnCocina::textoTiempo(59))->toBe('hace 59 min')
        ->and(PedidosEnCocina::textoTiempo(60))->toBe('hace 1 h 00 min');
});

test('the card summarizes three products, «Ver» shows everything with the note and payment, and returns update the list', function () {
    $papas = productoDe($this->empresa, ['nombre' => 'Papas fritas']);
    $coca = productoDe($this->empresa, ['nombre' => 'Coca-Cola 500 ml']);
    $te = productoDe($this->empresa, ['nombre' => 'Té de coca']);
    foreach ([$papas, $coca, $te] as $producto) {
        cargarStock($this->admin, $this->matriz, $producto, '10');
    }

    $mesa = panelVender($this, 'Mesa 4', lineas: [[$this->producto, '2'], [$papas, '1'], [$coca, '2'], [$te, '1']], pedido: ['nota' => 'Sin cebolla']);
    $cliente = new Cliente(['nombre' => 'Carlos Mendoza', 'documento' => '4455667']);
    $cliente->save();
    $credito = panelVender($this, null, pedido: ['cliente_id' => $cliente->id], pago: ['en_cuotas' => true, 'cuota_inicial' => '10.00']);
    $cancelada = panelVender($this, 'Mesa 9');

    $panel = Livewire::test(Panel::class)->dispatch('abrir-panel-cocina')
        ->assertSee('2× Hamburguesa clásica, 1× Papas fritas, 2× Coca-Cola 500 ml y 1 más')
        ->assertSeeHtml('<b class="cocina-nombre">'.$credito->codigo().'</b>');   // sin nombre o mesa: el número

    $panel->call('ver', $mesa->id)
        ->assertSeeHtml('data-cocina-detalle="'.$mesa->id.'"')
        ->assertSee(['Mesa 4', 'Té de coca', 'Sin cebolla', $this->admin->name, 'Pagado'])
        ->assertSeeHtml('data-cocina-entregar="'.$mesa->id.'"')
        ->assertDontSeeHtml('data-cocina-pedido=');
    $panel->call('ver', $credito->id)->assertSee('Saldo Bs 15,00');
    $panel->call('volver')->assertDontSeeHtml('data-cocina-detalle')->assertSeeHtml('data-cocina-pedido="'.$mesa->id.'"');

    // Una devolución parcial descuenta lo devuelto; la total la cancela y la saca del panel.
    panelDevolver($this->admin, $mesa, '1');
    panelDevolver($this->admin, $cancelada, '1');
    $panel->call('$refresh')
        ->assertSee('1× Hamburguesa clásica, 1× Papas fritas, 2× Coca-Cola 500 ml y 1 más')
        ->assertDontSeeHtml('data-cocina-pedido="'.$cancelada->id.'"')
        ->assertSeeHtml('data-cocina-total>(2)</span>');
    expect($cancelada->refresh()->estado_entrega)->toBe(EstadoEntrega::Cancelado);
});

test('the delay limit is set in Funciones del negocio, from 1 to 240, with the summary and the audit', function () {
    expect(Empresa::factory()->create()->fresh()->minutos_alerta_cocina)->toBe(15)
        ->and(Capacidad::Cocina->disponible())->toBeTrue();

    $pantalla = Livewire::test(FuncionesNegocio::class)
        ->assertSet('minutosAlertaCocina', '15')
        ->assertSeeHtml('data-test="funciones-minutos-cocina"')
        ->assertSee('Avisar pedidos con demora a partir de');

    foreach (['0', '241', 'abc', '', '12.5'] as $invalido) {
        $pantalla->set('minutosAlertaCocina', $invalido)->call('revisar')
            ->assertHasErrors(['minutosAlertaCocina'])->assertSet('revisando', false);
    }

    // Solo cambia el límite: igual se revisa y se guarda.
    $pantalla->set('minutosAlertaCocina', ' 20 ')->call('revisar')
        ->assertHasNoErrors()
        ->assertSet('revisando', true)
        ->assertSee('15 → 20 min')
        ->assertDontSee('Sin cambios')
        ->call('guardar')
        ->assertSee('Funciones del negocio guardadas.');

    $auditoria = Auditoria::query()->where('accion', 'empresa.funciones_cambiadas')->latest('id')->first();
    expect($this->empresa->fresh()->minutos_alerta_cocina)->toBe(20)
        ->and($auditoria->antes['minutos_alerta_cocina'])->toBe(15)
        ->and($auditoria->despues['minutos_alerta_cocina'])->toBe(20)
        ->and($auditoria->despues['activadas'])->toBe([]);

    $pantalla->call('revisar')->assertSee('No hay cambios que guardar.')->assertSet('revisando', false);

    // Sin cocina marcada el campo no se muestra ni se valida, y el límite guardado no cambia.
    $pantalla->set('capacidades', [])->assertDontSeeHtml('data-test="funciones-minutos-cocina"')
        ->set('minutosAlertaCocina', 'abc')->call('revisar')->assertHasNoErrors()->call('guardar');
    expect($this->empresa->fresh()->minutos_alerta_cocina)->toBe(20);

    // El servidor y la base rechazan valores fuera de rango aunque la pantalla se salte.
    expect(fn () => app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::Restaurante, [Capacidad::Cocina], 0))
        ->toThrow(ValidationException::class);
    expect(fn () => DB::table('empresas')->where('id', $this->empresa->id)->update(['minutos_alerta_cocina' => 241]))
        ->toThrow(QueryException::class);
    expect($this->empresa->fresh()->minutos_alerta_cocina)->toBe(20);
});

test('the panel with 50 orders of 5 products makes the same number of queries as with 5, and the counter is one small request', function () {
    $platos = collect(range(1, 5))->map(fn (int $i): Producto => productoDe($this->empresa, ['nombre' => "Plato {$i}"]));
    $platos->each(fn (Producto $p) => cargarStock($this->admin, $this->matriz, $p, '60'));
    $lineas = $platos->map(fn (Producto $p): array => [$p, '1'])->all();
    $vender = function (int $desde, int $hasta) use ($lineas): void {
        foreach (range($desde, $hasta) as $i) {
            panelVender($this, "Mesa {$i}", lineas: $lineas);
        }
    };

    $vender(1, 5);
    $panel = Livewire::test(Panel::class)->dispatch('abrir-panel-cocina');
    $boton = Livewire::test(BotonFlotante::class);
    $conCinco = panelConsultas(fn () => $panel->call('$refresh')->assertSeeHtml('data-cocina-total>(5)</span>'));
    $botonCinco = panelConsultas(fn () => $boton->call('$refresh')->assertSeeHtml('data-cocina-contador>5</span>'));

    $vender(6, 50);
    $conCincuenta = panelConsultas(fn () => $panel->call('$refresh')->assertSeeHtml('data-cocina-total>(50)</span>')->assertSee('Mesa 50'));
    $botonCincuenta = panelConsultas(fn () => $boton->call('$refresh')->assertSeeHtml('data-cocina-contador>50</span>'));

    expect($conCincuenta)->toBe($conCinco)
        ->and($botonCincuenta)->toBe($botonCinco)
        ->and($botonCinco)->toBeLessThanOrEqual(4);
});
