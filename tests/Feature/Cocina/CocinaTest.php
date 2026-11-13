<?php

use App\Domain\Caja\Actions\AbrirTurnoCaja;
use App\Domain\Despachos\Actions\CorregirEstadoEntrega;
use App\Domain\Despachos\Actions\DeshacerEntrega;
use App\Domain\Despachos\Actions\MarcarEntregada;
use App\Domain\Despachos\Consultas\ListadoDespachos;
use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Domain\Ventas\Actions\ConfirmarVenta;
use App\Domain\Ventas\Actions\RegistrarDevolucion;
use App\Domain\Ventas\Actions\VenderEnPos;
use App\Enums\Capacidad;
use App\Enums\EstadoEntrega;
use App\Enums\Rubro;
use App\Models\Auditoria;
use App\Models\Devolucion;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * Pedidos en cocina, etapa K1 (ESPECIFICACION_COCINA.md § 5 y § 8): la venta nace En cocina salvo la casilla
 * «Entregado», cualquiera que vea la venta la entrega, quien entregó deshace durante 10 segundos y la
 * devolución total la cancela sin forzar el reingreso. Restaurante con Casa Matriz y Sucursal Norte;
 * «Hamburguesa clásica» de Bs 25 con 10 unidades en cada sucursal. Lunes 19 de octubre de 2026 en La Paz.
 * El botón flotante, el panel, el tiempo y el rendimiento del panel (etapa K2) están en CocinaPanelTest.
 */

function cocinaHora(string $momento): void
{
    test()->travelTo(CarbonImmutable::parse($momento, 'America/La_Paz'));
}

/**
 * Restaurante con la capacidad encendida por la acción real de Configuración.
 */
function cocinaActivar(User $admin, Empresa $empresa): void
{
    app(CambiarFuncionesEmpresa::class)->ejecutar($admin, $empresa, Rubro::Restaurante, [Capacidad::Cocina]);
}

/**
 * Confirma una venta del administrador con la acción real. Sin $entregado, como una petición que omite el campo.
 *
 * @param  array<string, mixed>  $pago
 */
function cocinaVender(object $t, string $cantidad = '1', ?bool $entregado = null, ?string $mesa = null, array $pago = [], ?Sucursal $sucursal = null): Venta
{
    $pedido = crearPedido($t->admin, $sucursal ?? $t->matriz, [['producto_id' => $t->producto->id, 'cantidad' => $cantidad]]);
    $pago += ['metodo' => 'efectivo', 'en_cuotas' => false, 'cuota_inicial' => null, 'efectivo' => null, 'electronico' => null,
        'clave' => (string) Str::uuid(), 'externo' => true];
    $extra = array_filter(['entregado' => $entregado, 'nombreMesa' => $mesa], fn ($valor) => $valor !== null);

    return app(ConfirmarVenta::class)->ejecutar($t->admin, $pedido, $pago, ...$extra);
}

function cocinaDevolver(User $admin, Venta $venta, string $cantidad, bool $reingresa = false): Devolucion
{
    $linea = VentaItem::query()->where('venta_id', $venta->id)->orderBy('orden')->value('id');

    return app(RegistrarDevolucion::class)->ejecutar($admin, $venta, [
        'lineas' => [$linea => ['cantidad' => $cantidad, 'reingresa' => $reingresa]],
        'motivo' => 'El cliente se fue sin comer', 'metodo' => 'efectivo', 'efectivo' => null, 'electronico' => null,
        'externo' => true, 'clave' => (string) Str::uuid(),
    ]);
}

/**
 * @return list<array{0: string|null, 1: string, 2: int, 3: bool, 4: string|null}> anterior, nuevo, usuario, corrección, nota
 */
function cocinaEventos(Venta $venta): array
{
    return EventoEntrega::query()->where('venta_id', $venta->id)->orderBy('id')->get()
        ->map(fn (EventoEntrega $e) => [$e->estado_anterior?->value, $e->estado_nuevo->value, $e->usuario_id, $e->es_correccion, $e->nota])->all();
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    $this->producto = productoDe($this->empresa, ['nombre' => 'Hamburguesa clásica', 'precio_consumidor' => '25.00']);
    $this->actingAs($this->admin);
    cocinaHora('2026-10-19 12:30');
    cargarStock($this->admin, $this->matriz, $this->producto, '10');
    cargarStock($this->admin, $this->norte, $this->producto, '10');
    cocinaActivar($this->admin, $this->empresa);
    $this->mesero = User::factory()->vendedor($this->matriz)->create(['name' => 'Rosa Mamani']);
    $this->cajero = User::factory()->vendedor($this->matriz)->create(['name' => 'Luis Quispe']);
});

test('without the capability every sale stays delivered as today: no table name, no history, no kitchen actions', function () {
    [$otra, $adminOtra] = empresaConAdministrador();
    $this->actingAs($adminOtra);
    $sucursal = Sucursal::factory()->create(['empresa_id' => $otra->id]);
    $producto = productoDe($otra, ['precio_consumidor' => '25.00']);
    cargarStock($adminOtra, $sucursal, $producto, '5');
    $pedido = crearPedido($adminOtra, $sucursal, [['producto_id' => $producto->id, 'cantidad' => '2']]);

    $venta = app(ConfirmarVenta::class)->ejecutar($adminOtra, $pedido, ['metodo' => 'efectivo', 'en_cuotas' => false, 'cuota_inicial' => null,
        'efectivo' => null, 'electronico' => null, 'clave' => (string) Str::uuid(), 'externo' => true], nombreMesa: 'Mesa 4');

    expect($venta->refresh()->estado_entrega)->toBe(EstadoEntrega::Entregado)
        ->and($venta->nombre_mesa)->toBeNull()
        ->and($venta->entregado_por)->toBeNull()
        ->and(EventoEntrega::query()->where('venta_id', $venta->id)->count())->toBe(0)
        ->and(stockDe($sucursal, $producto))->toBe('3.000');

    expect(fn () => app(MarcarEntregada::class)->ejecutar($adminOtra, $venta))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeshacerEntrega::class)->ejecutar($adminOtra, $venta))->toThrow(AuthorizationException::class);
});

test('a sale without the checkbox stays En cocina with its table name; a request that omits «entregado» too; the checkbox delivers in the name of the seller', function () {
    $omitida = cocinaVender($this, mesa: '  Mesa 4  ');
    $desmarcada = cocinaVender($this, entregado: false, mesa: '');
    $marcada = cocinaVender($this, entregado: true, mesa: 'Juan');

    expect($omitida->refresh()->estado_entrega)->toBe(EstadoEntrega::EnCocina)
        ->and($omitida->nombre_mesa)->toBe('Mesa 4')
        ->and($omitida->entregado_por)->toBeNull()
        ->and(cocinaEventos($omitida))->toBe([[null, 'en_cocina', $this->admin->id, false, null]])
        ->and($desmarcada->refresh()->estado_entrega)->toBe(EstadoEntrega::EnCocina)
        ->and($desmarcada->nombre_mesa)->toBeNull()
        ->and($marcada->refresh()->estado_entrega)->toBe(EstadoEntrega::Entregado)
        ->and($marcada->nombre_mesa)->toBe('Juan')
        ->and($marcada->entregado_por)->toBe($this->admin->id)
        ->and($marcada->entregado_en->equalTo($marcada->fecha))->toBeTrue()
        ->and(cocinaEventos($marcada))->toBe([[null, 'entregado', $this->admin->id, false, null]])
        // El stock se descuenta al confirmar, como en cualquier venta.
        ->and(stockDe($this->matriz, $this->producto))->toBe('7.000');

    $auditoria = Auditoria::query()->where('accion', 'venta.confirmada')->where('entidad_id', $omitida->id)->sole();
    expect($auditoria->despues)->toMatchArray(['estado_entrega' => 'en_cocina', 'nombre_mesa' => 'Mesa 4']);

    // Más de 40 caracteres se rechaza sin vender.
    expect(fn () => cocinaVender($this, mesa: str_repeat('M', 41)))->toThrow(ValidationException::class, '«Nombre o mesa» admite hasta 40 caracteres.');
    expect(Venta::query()->count())->toBe(3);

    // El POS sigue la misma regla.
    app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->matriz->cajaPrincipal(), '100.00');
    $pos = fn (array $extra) => app(VenderEnPos::class)->ejecutar($this->admin, [
        'sucursal_id' => $this->matriz->id, 'cliente_id' => null,
        'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => '1', 'tipo_precio' => 'consumidor', 'descuento_tipo' => 'bs', 'descuento_valor' => '0']],
        'descuento_global_tipo' => 'bs', 'descuento_global_valor' => '0', 'clave' => (string) Str::uuid(),
    ], ['metodo' => 'efectivo', 'en_cuotas' => false, 'cuota_inicial' => null, 'efectivo' => null, 'electronico' => null, 'clave' => (string) Str::uuid()], ...$extra);

    $enCocina = $pos(['nombreMesa' => 'Mesa 2'])->refresh();
    $entregada = $pos(['entregado' => true])->refresh();
    expect($enCocina->estado_entrega)->toBe(EstadoEntrega::EnCocina)
        ->and($enCocina->nombre_mesa)->toBe('Mesa 2')
        ->and($entregada->estado_entrega)->toBe(EstadoEntrega::Entregado)
        ->and($entregada->entregado_por)->toBe($this->admin->id);
});

test('anyone who sees the sale delivers an order En cocina with who and when, one event, and never money', function () {
    $venta = cocinaVender($this, '2', mesa: 'Mesa 4');
    $pagos = PagoVenta::query()->count();
    $movimientos = MovimientoCaja::query()->count();

    cocinaHora('2026-10-19 12:48');
    $entregada = app(MarcarEntregada::class)->ejecutar($this->mesero, $venta);

    expect($entregada->estado_entrega)->toBe(EstadoEntrega::Entregado)
        ->and($entregada->entregado_por)->toBe($this->mesero->id)
        ->and($entregada->entregado_en->equalTo(CarbonImmutable::parse('2026-10-19 12:48', 'America/La_Paz')))->toBeTrue()
        ->and($entregada->despachado_por)->toBeNull()
        ->and(cocinaEventos($venta))->toBe([
            [null, 'en_cocina', $this->admin->id, false, null],
            ['en_cocina', 'entregado', $this->mesero->id, false, null],
        ])
        // Entregar no registra dinero ni mueve stock.
        ->and(PagoVenta::query()->count())->toBe($pagos)
        ->and(MovimientoCaja::query()->count())->toBe($movimientos)
        ->and(stockDe($this->matriz, $this->producto))->toBe('8.000');

    $auditoria = Auditoria::query()->where('accion', 'venta.entregada')->sole();
    expect($auditoria->antes)->toBe(['estado_entrega' => 'en_cocina'])
        ->and($auditoria->despues['estado_entrega'])->toBe('entregado');
});

test('a double click or two users at once record a single delivery; the second hears who delivered it and when', function () {
    $venta = cocinaVender($this, mesa: 'Mesa 4');
    cocinaHora('2026-10-19 12:40');

    app(MarcarEntregada::class)->ejecutar($this->mesero, $venta);

    expect(fn () => app(MarcarEntregada::class)->ejecutar($this->mesero, $venta))
        ->toThrow(ValidationException::class, 'Ya entregado por Rosa Mamani a las 12:40.')
        ->and(fn () => app(MarcarEntregada::class)->ejecutar($this->cajero, $venta))
        ->toThrow(ValidationException::class, 'Ya entregado por Rosa Mamani a las 12:40.');

    expect(EventoEntrega::query()->where('venta_id', $venta->id)->where('estado_nuevo', 'entregado')->count())->toBe(1)
        ->and($venta->fresh()->entregado_por)->toBe($this->mesero->id);
});

test('whoever delivered undoes it within 10 seconds; later, by someone else or a checkbox delivery is rejected', function () {
    $venta = cocinaVender($this, mesa: 'Mesa 4');
    cocinaHora('2026-10-19 12:40:00');
    app(MarcarEntregada::class)->ejecutar($this->mesero, $venta);

    // Otro usuario, aunque vea la venta y esté dentro del plazo.
    $this->travel(5)->seconds();
    expect(fn () => app(DeshacerEntrega::class)->ejecutar($this->cajero, $venta))
        ->toThrow(ValidationException::class, 'Solo quien entregó el pedido puede deshacerlo');

    // El mismo usuario a los 10 segundos: vuelve a En cocina.
    $this->travel(5)->seconds();
    $deshecha = app(DeshacerEntrega::class)->ejecutar($this->mesero, $venta);

    expect($deshecha->estado_entrega)->toBe(EstadoEntrega::EnCocina)
        ->and($deshecha->entregado_por)->toBeNull()
        ->and($deshecha->entregado_en)->toBeNull()
        ->and(cocinaEventos($venta)[2])->toBe(['entregado', 'en_cocina', $this->mesero->id, true, 'Deshecho por el mismo usuario'])
        ->and(Auditoria::query()->where('accion', 'venta.entrega_deshecha')->count())->toBe(1);

    // Un segundo clic en «Deshacer» no retrocede nada más.
    expect(fn () => app(DeshacerEntrega::class)->ejecutar($this->mesero, $venta))
        ->toThrow(ValidationException::class, 'La entrega ya no se puede deshacer');

    // Entregado de nuevo, a los 11 segundos ya no se deshace.
    cocinaHora('2026-10-19 12:45:00');
    app(MarcarEntregada::class)->ejecutar($this->mesero, $venta);
    $this->travel(11)->seconds();
    expect(fn () => app(DeshacerEntrega::class)->ejecutar($this->mesero, $venta))
        ->toThrow(ValidationException::class, 'Pasaron más de 10 segundos: pide a un administrador que corrija la entrega.');
    expect($venta->fresh()->estado_entrega)->toBe(EstadoEntrega::Entregado);

    // La casilla «Entregado» al vender no se deshace así (decisión del propietario del 08/11/2026).
    $alVender = cocinaVender($this, entregado: true);
    expect(fn () => app(DeshacerEntrega::class)->ejecutar($this->admin, $alVender))
        ->toThrow(ValidationException::class, 'Solo se deshace la entrega de un pedido que estaba en cocina.');
});

test('the administrator corrects a delivery back to En cocina with a note, also one ticked when selling; nobody else can', function () {
    $venta = cocinaVender($this, mesa: 'Mesa 4');
    app(MarcarEntregada::class)->ejecutar($this->mesero, $venta);
    $alVender = cocinaVender($this, entregado: true);

    expect(app(CorregirEstadoEntrega::class)->destino($venta->fresh()))->toBe(EstadoEntrega::EnCocina)
        ->and(app(CorregirEstadoEntrega::class)->destino($alVender->fresh()))->toBe(EstadoEntrega::EnCocina);

    expect(fn () => app(CorregirEstadoEntrega::class)->ejecutar($this->mesero, $venta, EstadoEntrega::Entregado, 'Error'))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(CorregirEstadoEntrega::class)->ejecutar($this->admin, $venta, EstadoEntrega::Entregado, ''))
        ->toThrow(ValidationException::class, 'Escribe una nota que explique el cambio.');

    cocinaHora('2026-10-19 13:10');
    $corregida = app(CorregirEstadoEntrega::class)->ejecutar($this->admin, $venta, EstadoEntrega::Entregado, 'Se marcó la mesa equivocada');
    $casilla = app(CorregirEstadoEntrega::class)->ejecutar($this->admin, $alVender, EstadoEntrega::Entregado, 'Todavía no salió');

    expect($corregida->estado_entrega)->toBe(EstadoEntrega::EnCocina)
        ->and($corregida->entregado_por)->toBeNull()
        ->and(cocinaEventos($venta)[2])->toBe(['entregado', 'en_cocina', $this->admin->id, true, 'Se marcó la mesa equivocada'])
        ->and($casilla->estado_entrega)->toBe(EstadoEntrega::EnCocina);

    // En cocina no hay un cambio que deshacer: no retrocede más.
    expect(fn () => app(CorregirEstadoEntrega::class)->ejecutar($this->admin, $venta, EstadoEntrega::EnCocina, 'Otra vez'))
        ->toThrow(ValidationException::class, 'No hay un cambio de entrega que deshacer.');
});

test('a full return of an order En cocina cancels it without forcing the stock back; a partial one keeps it En cocina', function () {
    $completa = cocinaVender($this, '2', mesa: 'Mesa 4');
    $parcial = cocinaVender($this, '3', mesa: 'Mesa 5');
    $conReingreso = cocinaVender($this, '1', mesa: 'Mesa 6');
    expect(stockDe($this->matriz, $this->producto))->toBe('4.000');

    cocinaHora('2026-10-19 12:50');
    cocinaDevolver($this->admin, $completa, '2');
    cocinaDevolver($this->admin, $parcial, '1');
    cocinaDevolver($this->admin, $conReingreso, '1', reingresa: true);

    expect($completa->fresh()->estado_entrega)->toBe(EstadoEntrega::Cancelado)
        ->and(cocinaEventos($completa)[1][0])->toBe('en_cocina')
        ->and(cocinaEventos($completa)[1][1])->toBe('cancelado')
        ->and(cocinaEventos($completa)[1][4])->toStartWith('Devolución ')
        ->and($parcial->fresh()->estado_entrega)->toBe(EstadoEntrega::EnCocina)
        ->and($conReingreso->fresh()->estado_entrega)->toBe(EstadoEntrega::Cancelado)
        // Solo reingresó la línea que el usuario marcó: la comida pudo haberse preparado.
        ->and(stockDe($this->matriz, $this->producto))->toBe('5.000');

    // Cancelada, ya no se entrega.
    expect(fn () => app(MarcarEntregada::class)->ejecutar($this->mesero, $completa))
        ->toThrow(ValidationException::class, 'La venta está cancelada: ya no admite cambios de entrega.');
});

test('a seller cannot deliver or undo an order of another branch, and company B cannot touch orders of A', function () {
    $venta = cocinaVender($this, mesa: 'Mesa 4');
    $vendedorNorte = User::factory()->vendedor($this->norte)->create();

    expect(fn () => app(MarcarEntregada::class)->ejecutar($vendedorNorte, $venta))->toThrow(AuthorizationException::class);

    [$empresaB, $adminB] = empresaConAdministrador();
    cocinaActivar($adminB, $empresaB);
    expect(fn () => app(MarcarEntregada::class)->ejecutar($adminB, $venta))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeshacerEntrega::class)->ejecutar($adminB, $venta))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CorregirEstadoEntrega::class)->ejecutar($adminB, $venta, EstadoEntrega::EnCocina, 'Intento'))->toThrow(AuthorizationException::class);

    // Con B autenticada, la venta de A y su historial ni siquiera se encuentran.
    $this->actingAs($adminB);
    expect(Venta::query()->find($venta->id))->toBeNull()
        ->and(EventoEntrega::query()->where('venta_id', $venta->id)->exists())->toBeFalse();

    $this->actingAs($this->admin);
    expect($venta->fresh()->estado_entrega)->toBe(EstadoEntrega::EnCocina)
        ->and(EventoEntrega::query()->where('venta_id', $venta->id)->count())->toBe(1);
});

test('kitchen cannot be turned off while orders are En cocina, and it cannot be on together with dispatch', function () {
    $primera = cocinaVender($this, mesa: 'Mesa 4');
    $segunda = cocinaVender($this);

    expect(fn () => app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::General, []))
        ->toThrow(ValidationException::class, 'No puedes desactivar «Pedidos en cocina»: 2 ventas todavía están en cocina (V-00001, V-00002).');
    expect(fn () => app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::Restaurante, [Capacidad::Cocina, Capacidad::Despachos]))
        ->toThrow(ValidationException::class);

    app(MarcarEntregada::class)->ejecutar($this->admin, $primera);
    expect(fn () => app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::General, []))
        ->toThrow(ValidationException::class, 'No puedes desactivar «Pedidos en cocina»: la venta V-00002 todavía está en cocina.');

    cocinaDevolver($this->admin, $segunda, '1');
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::General, []);

    expect($this->empresa->fresh()->tieneCapacidad(Capacidad::Cocina))->toBeFalse()
        // El historial se conserva y las ventas nuevas vuelven a nacer Entregado.
        ->and(EventoEntrega::query()->where('venta_id', $primera->id)->count())->toBe(2)
        ->and(cocinaVender($this)->refresh()->estado_entrega)->toBe(EstadoEntrega::Entregado);
});

test('the database accepts En cocina, rejects unknown states, and the Despachos tab never counts kitchen orders', function () {
    $venta = cocinaVender($this);

    expect(DB::table('ventas')->where('id', $venta->id)->value('estado_entrega'))->toBe('en_cocina');
    expect(fn () => DB::table('ventas')->where('id', $venta->id)->update(['estado_entrega' => 'en_preparacion']))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('ventas')->where('id', $venta->id)->update(['nombre_mesa' => str_repeat('M', 41)]))
        ->toThrow(QueryException::class);

    expect(array_keys(ListadoDespachos::conteos($this->admin)))->toBe(['por_despachar', 'despachado', 'entregado', 'cancelado'])
        ->and(ListadoDespachos::consulta($this->admin, ['estado' => 'en_cocina'])->count())->toBe(0);
});

test('a confirmed sale keeps its table name: it cannot be rewritten', function () {
    $venta = cocinaVender($this, mesa: 'Mesa 4');
    $venta->nombre_mesa = 'Mesa 9';

    expect(fn () => $venta->save())->toThrow(LogicException::class, 'Una venta confirmada no puede reescribirse.');
});

test('an order En cocina is a normal sale: stock is checked and taken when confirming', function () {
    $otro = Producto::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Coca-Cola 500 ml', 'precio_consumidor' => '8.00']);
    cargarStock($this->admin, $this->matriz, $otro, '3');
    $pedido = crearPedido($this->admin, $this->matriz, [['producto_id' => $otro->id, 'cantidad' => '3']]);

    expect(fn () => app(ConfirmarVenta::class)->ejecutar($this->admin, crearPedido($this->admin, $this->matriz, [['producto_id' => $otro->id, 'cantidad' => '4']]), [
        'metodo' => 'efectivo', 'en_cuotas' => false, 'cuota_inicial' => null, 'efectivo' => null, 'electronico' => null,
        'clave' => (string) Str::uuid(), 'externo' => true]))->toThrow(ValidationException::class);

    $venta = app(ConfirmarVenta::class)->ejecutar($this->admin, $pedido, ['metodo' => 'efectivo', 'en_cuotas' => false, 'cuota_inicial' => null,
        'efectivo' => null, 'electronico' => null, 'clave' => (string) Str::uuid(), 'externo' => true]);

    expect($venta->refresh()->estado_entrega)->toBe(EstadoEntrega::EnCocina)
        ->and(stockDe($this->matriz, $otro))->toBe('0.000');
});
