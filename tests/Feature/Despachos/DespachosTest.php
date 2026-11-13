<?php

use App\Domain\Caja\Actions\AbrirTurnoCaja;
use App\Domain\Creditos\Actions\RegistrarAbono;
use App\Domain\Despachos\Actions\CancelarEntregaDevuelta;
use App\Domain\Despachos\Actions\CorregirEstadoEntrega;
use App\Domain\Despachos\Actions\DespacharVenta;
use App\Domain\Despachos\Actions\MarcarEntregada;
use App\Domain\Despachos\Consultas\ListadoDespachos;
use App\Domain\Despachos\Consultas\ReservadoPorDespachar;
use App\Domain\Reportes\Consultas\Cobros;
use App\Domain\Reportes\Periodo;
use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Domain\Ventas\Actions\ConfirmarVenta;
use App\Domain\Ventas\Actions\RegistrarDevolucion;
use App\Domain\Ventas\Actions\VenderEnPos;
use App\Enums\Capacidad;
use App\Enums\EstadoEntrega;
use App\Enums\Rubro;
use App\Models\Cliente;
use App\Models\Devolucion;
use App\Models\Empresa;
use App\Models\EventoEntrega;
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
 * Despachos, etapa D1 (ESPECIFICACION_DESPACHOS.md § 8): estados Por despachar → Despachado → Entregado,
 * independientes del pago. Distribuidora con Casa Matriz y Sucursal Norte; producto de Bs 20 con 10
 * unidades en cada sucursal. Semana de prueba en La Paz: lunes 19 a jueves 22 de octubre de 2026.
 */

function despachoHora(string $momento): void
{
    test()->travelTo(CarbonImmutable::parse($momento, 'America/La_Paz'));
}

/**
 * Distribuidora con la capacidad encendida por la acción real de Configuración.
 */
function despachoActivar(User $admin, Empresa $empresa): void
{
    app(CambiarFuncionesEmpresa::class)->ejecutar($admin, $empresa, Rubro::Distribuidora, [Capacidad::Despachos]);
}

/**
 * Confirma una venta del administrador con la acción real. Sin $entregado, como una petición que omite el campo.
 *
 * @param  array<string, mixed>  $pago
 */
function despachoVender(object $t, string $cantidad, array $pago = [], ?bool $entregado = null, ?Sucursal $sucursal = null, ?Producto $producto = null): Venta
{
    $pedido = crearPedido($t->admin, $sucursal ?? $t->matriz, [['producto_id' => ($producto ?? $t->producto)->id, 'cantidad' => $cantidad]],
        ['cliente_id' => $t->cliente->id]);
    $pago += ['metodo' => 'efectivo', 'en_cuotas' => false, 'cuota_inicial' => null, 'efectivo' => null, 'electronico' => null,
        'clave' => (string) Str::uuid(), 'externo' => true];

    return $entregado === null
        ? app(ConfirmarVenta::class)->ejecutar($t->admin, $pedido, $pago)
        : app(ConfirmarVenta::class)->ejecutar($t->admin, $pedido, $pago, entregado: $entregado);
}

function despachoDevolver(User $admin, Venta $venta, string $cantidad, bool $reingresa = false): Devolucion
{
    $linea = VentaItem::query()->where('venta_id', $venta->id)->orderBy('orden')->value('id');

    return app(RegistrarDevolucion::class)->ejecutar($admin, $venta, [
        'lineas' => [$linea => ['cantidad' => $cantidad, 'reingresa' => $reingresa]],
        'motivo' => 'El cliente ya no lo quiere', 'metodo' => 'efectivo', 'efectivo' => null, 'electronico' => null,
        'externo' => true, 'clave' => (string) Str::uuid(),
    ]);
}

/**
 * Vendedor de la sucursal, con o sin permiso de almacén (lo asigna el administrador).
 */
function despachoVendedor(Sucursal $sucursal, bool $almacen = false): User
{
    $usuario = User::factory()->vendedor($sucursal)->create();
    $usuario->forceFill(['puede_despachar' => $almacen])->save();

    return $usuario;
}

/**
 * @return list<array{0: string|null, 1: string, 2: int, 3: bool}> anterior, nuevo, usuario, corrección
 */
function despachoEventos(Venta $venta): array
{
    return EventoEntrega::query()->where('venta_id', $venta->id)->orderBy('id')->get()
        ->map(fn (EventoEntrega $e) => [$e->estado_anterior?->value, $e->estado_nuevo->value, $e->usuario_id, $e->es_correccion])->all();
}

function despachoCobradoEl(int $empresaId, string $dia): string
{
    return Cobros::resumen($empresaId, Periodo::dia($dia))['total'];
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    $this->producto = productoDe($this->empresa, ['nombre' => 'Aceite 1L', 'precio_consumidor' => '20.00']);
    $this->actingAs($this->admin);
    despachoHora('2026-10-19 08:00');
    cargarStock($this->admin, $this->matriz, $this->producto, '10');
    cargarStock($this->admin, $this->norte, $this->producto, '10');
    $this->cliente = new Cliente(['nombre' => 'Distribuidora El Sol', 'documento' => '7788990']);
    $this->cliente->save();
    despachoActivar($this->admin, $this->empresa);
    $this->almacen = despachoVendedor($this->matriz, almacen: true);
    $this->vendedor = despachoVendedor($this->matriz);
});

test('without the capability every new sale is born delivered, without who or history, exactly as before', function () {
    [$otra, $adminOtra] = empresaConAdministrador();
    $this->actingAs($adminOtra);
    $sucursal = Sucursal::factory()->create(['empresa_id' => $otra->id]);
    $producto = productoDe($otra, ['precio_consumidor' => '20.00']);
    cargarStock($adminOtra, $sucursal, $producto, '5');

    $venta = confirmarVenta($adminOtra, crearPedido($adminOtra, $sucursal, [['producto_id' => $producto->id, 'cantidad' => '2']]), ['metodo' => 'efectivo']);

    expect($venta->refresh()->estado_entrega)->toBe(EstadoEntrega::Entregado)
        ->and($venta->entregado_por)->toBeNull()
        ->and($venta->entregado_en)->toBeNull()
        ->and(EventoEntrega::query()->where('venta_id', $venta->id)->count())->toBe(0)
        ->and(stockDe($sucursal, $producto))->toBe('3.000');

    // Ninguna acción de entrega existe para esa empresa, aunque se llame directamente.
    expect(fn () => app(DespacharVenta::class)->ejecutar($adminOtra, $venta))->toThrow(AuthorizationException::class)
        ->and(fn () => app(MarcarEntregada::class)->ejecutar($adminOtra, $venta))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CorregirEstadoEntrega::class)->ejecutar($adminOtra, $venta, EstadoEntrega::Entregado, 'Prueba'))->toThrow(AuthorizationException::class);
});

test('sales made before turning the capability on stay delivered; only new ones start pending dispatch', function () {
    [$otra, $adminOtra] = empresaConAdministrador();
    $this->actingAs($adminOtra);
    $sucursal = Sucursal::factory()->create(['empresa_id' => $otra->id]);
    $producto = productoDe($otra, ['precio_consumidor' => '20.00']);
    cargarStock($adminOtra, $sucursal, $producto, '5');
    $anterior = confirmarVenta($adminOtra, crearPedido($adminOtra, $sucursal, [['producto_id' => $producto->id, 'cantidad' => '1']]), ['metodo' => 'efectivo']);

    despachoActivar($adminOtra, $otra);
    $nueva = confirmarVenta($adminOtra, crearPedido($adminOtra, $sucursal, [['producto_id' => $producto->id, 'cantidad' => '1']]), ['metodo' => 'efectivo']);

    expect($anterior->refresh()->estado_entrega)->toBe(EstadoEntrega::Entregado)
        ->and($nueva->refresh()->estado_entrega)->toBe(EstadoEntrega::PorDespachar);

    // Una venta antigua no tiene un cambio de entrega que deshacer.
    expect(fn () => app(CorregirEstadoEntrega::class)->ejecutar($adminOtra, $anterior, EstadoEntrega::Entregado, 'Error'))
        ->toThrow(ValidationException::class, 'No hay un cambio de entrega que deshacer.');
});

test('a sale pending dispatch takes the stock at once and those units cannot be sold again', function () {
    $venta = despachoVender($this, '10');

    expect($venta->refresh()->estado_entrega)->toBe(EstadoEntrega::PorDespachar)
        ->and(stockDe($this->matriz, $this->producto))->toBe('0.000')
        ->and(ReservadoPorDespachar::cantidades($this->empresa->id, $this->matriz->id))->toBe([$this->matriz->id => [$this->producto->id => '10.000']]);

    expect(fn () => despachoVender($this, '1'))->toThrow(ValidationException::class);
    expect(stockDe($this->matriz, $this->producto))->toBe('0.000');
});

test('a request that omits «entregado» leaves the sale pending dispatch; ticking it delivers at once in the name of the seller', function () {
    despachoHora('2026-10-19 09:15');
    $omitido = despachoVender($this, '1');
    $desmarcado = despachoVender($this, '1', entregado: false);
    $marcado = despachoVender($this, '1', entregado: true);

    expect($omitido->refresh()->estado_entrega)->toBe(EstadoEntrega::PorDespachar)
        ->and($omitido->entregado_por)->toBeNull()
        ->and(despachoEventos($omitido))->toBe([[null, 'por_despachar', $this->admin->id, false]])
        ->and($desmarcado->refresh()->estado_entrega)->toBe(EstadoEntrega::PorDespachar)
        ->and($marcado->refresh()->estado_entrega)->toBe(EstadoEntrega::Entregado)
        ->and($marcado->entregado_por)->toBe($this->admin->id)
        ->and($marcado->entregado_en->equalTo($marcado->fecha))->toBeTrue()
        ->and($marcado->despachado_por)->toBeNull()
        ->and(despachoEventos($marcado))->toBe([[null, 'entregado', $this->admin->id, false]]);

    // El POS sigue la misma regla.
    app(AbrirTurnoCaja::class)->ejecutar($this->admin, $this->matriz->cajaPrincipal(), '100.00');
    $pos = fn (array $extra) => app(VenderEnPos::class)->ejecutar($this->admin, [
        'sucursal_id' => $this->matriz->id, 'cliente_id' => null,
        'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => '1', 'tipo_precio' => 'consumidor', 'descuento_tipo' => 'bs', 'descuento_valor' => '0']],
        'descuento_global_tipo' => 'bs', 'descuento_global_valor' => '0', 'clave' => (string) Str::uuid(),
    ], ['metodo' => 'efectivo', 'en_cuotas' => false, 'cuota_inicial' => null, 'efectivo' => null, 'electronico' => null, 'clave' => (string) Str::uuid()], ...$extra);

    expect($pos([])->refresh()->estado_entrega)->toBe(EstadoEntrega::PorDespachar)
        ->and($pos(['entregado' => true])->refresh()->estado_entrega)->toBe(EstadoEntrega::Entregado);
});

test('the warehouse user of the branch dispatches with who, when and «Retira», without moving stock or money', function () {
    $venta = despachoVender($this, '4');
    $stock = stockDe($this->matriz, $this->producto);
    $caja = DB::table('movimientos_caja')->count();
    $pagos = DB::table('pagos_venta')->count();

    despachoHora('2026-10-19 10:42');
    app(DespacharVenta::class)->ejecutar($this->almacen, $venta, '  Juan Pérez  ');

    $venta->refresh();
    expect($venta->estado_entrega)->toBe(EstadoEntrega::Despachado)
        ->and($venta->despachado_por)->toBe($this->almacen->id)
        ->and($venta->despachado_en->equalTo(now()))->toBeTrue()
        ->and($venta->despacho_retira)->toBe('Juan Pérez')
        ->and(despachoEventos($venta))->toBe([[null, 'por_despachar', $this->admin->id, false], ['por_despachar', 'despachado', $this->almacen->id, false]])
        ->and(stockDe($this->matriz, $this->producto))->toBe($stock)
        ->and(DB::table('movimientos_caja')->count())->toBe($caja)
        ->and(DB::table('pagos_venta')->count())->toBe($pagos)
        ->and(DB::table('auditoria')->where('accion', 'venta.despachada')->where('entidad_id', $venta->id)->count())->toBe(1);

    // El administrador también puede despachar, en cualquier sucursal.
    $norte = despachoVender($this, '1', sucursal: $this->norte);
    app(DespacharVenta::class)->ejecutar($this->admin, $norte);
    expect($norte->refresh()->estado_entrega)->toBe(EstadoEntrega::Despachado)->and($norte->despacho_retira)->toBeNull();
});

test('a seller without warehouse permission, or with it from another branch, cannot dispatch even calling the action', function () {
    $venta = despachoVender($this, '2');
    $almacenNorte = despachoVendedor($this->norte, almacen: true);

    expect(fn () => app(DespacharVenta::class)->ejecutar($this->vendedor, $venta))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DespacharVenta::class)->ejecutar($almacenNorte, $venta))->toThrow(AuthorizationException::class)
        ->and(fn () => app(MarcarEntregada::class)->ejecutar($almacenNorte, $venta, despacharTambien: true))->toThrow(AuthorizationException::class);

    // La acción no recibe sucursal: decide la sucursal guardada del usuario contra la de la venta.
    expect($venta->refresh()->estado_entrega)->toBe(EstadoEntrega::PorDespachar)
        ->and(EventoEntrega::query()->where('venta_id', $venta->id)->count())->toBe(1);
});

test('delivering requires the dispatch first; «Despachar y entregar» leaves two events in the name of the warehouse user', function () {
    $venta = despachoVender($this, '3');

    expect(fn () => app(MarcarEntregada::class)->ejecutar($this->vendedor, $venta))
        ->toThrow(ValidationException::class, 'La venta todavía no fue despachada')
        ->and(fn () => app(MarcarEntregada::class)->ejecutar($this->vendedor, $venta, despacharTambien: true))
        ->toThrow(AuthorizationException::class);

    despachoHora('2026-10-19 11:00');
    app(MarcarEntregada::class)->ejecutar($this->almacen, $venta, 'Rosa Quispe', 'Retiró en almacén', despacharTambien: true);

    $venta->refresh();
    expect($venta->estado_entrega)->toBe(EstadoEntrega::Entregado)
        ->and([$venta->despachado_por, $venta->entregado_por])->toBe([$this->almacen->id, $this->almacen->id])
        ->and([$venta->despacho_retira, $venta->entrega_recibio])->toBe(['Rosa Quispe', 'Rosa Quispe'])
        ->and(array_slice(despachoEventos($venta), 1))->toBe([
            ['por_despachar', 'despachado', $this->almacen->id, false],
            ['despachado', 'entregado', $this->almacen->id, false],
        ])
        ->and(EventoEntrega::query()->where('venta_id', $venta->id)->latest('id')->value('nota'))->toBe('Retiró en almacén');
});

test('anyone who can see the sale marks it delivered after the dispatch, without registering money', function () {
    $venta = despachoVender($this, '2', ['en_cuotas' => true, 'cuota_inicial' => '0']);
    app(DespacharVenta::class)->ejecutar($this->almacen, $venta, 'Chofer de la empresa');
    $caja = DB::table('movimientos_caja')->count();
    $pagos = DB::table('pagos_venta')->count();
    $vendedorNorte = despachoVendedor($this->norte);

    expect(fn () => app(MarcarEntregada::class)->ejecutar($vendedorNorte, $venta))->toThrow(AuthorizationException::class);

    app(MarcarEntregada::class)->ejecutar($this->vendedor, $venta, 'Don Mario', 'Pagará el viernes');

    $venta->refresh();
    expect($venta->estado_entrega)->toBe(EstadoEntrega::Entregado)
        ->and($venta->entregado_por)->toBe($this->vendedor->id)
        ->and($venta->entrega_recibio)->toBe('Don Mario')
        ->and($venta->saldo_pendiente)->toBe('40.00')
        ->and(DB::table('movimientos_caja')->count())->toBe($caja)
        ->and(DB::table('pagos_venta')->count())->toBe($pagos);
});

test('Bs 200 pending dispatch with Bs 0 down and Bs 200 paid on delivery Thursday is collected on Thursday only', function () {
    despachoHora('2026-10-19 10:00');
    $venta = despachoVender($this, '10', ['en_cuotas' => true, 'cuota_inicial' => '0']);

    despachoHora('2026-10-22 09:00');
    app(DespacharVenta::class)->ejecutar($this->almacen, $venta);
    despachoHora('2026-10-22 15:30');
    app(MarcarEntregada::class)->ejecutar($this->vendedor, $venta, 'Cliente');
    app(RegistrarAbono::class)->ejecutar($this->admin, $venta, [
        'sucursal_id' => $this->matriz->id, 'metodo' => 'efectivo', 'monto' => '200', 'efectivo' => null,
        'electronico' => null, 'nota' => null, 'externo' => true, 'clave' => (string) Str::uuid(),
    ]);

    expect(despachoCobradoEl($this->empresa->id, '2026-10-19'))->toBe('0.00')
        ->and(despachoCobradoEl($this->empresa->id, '2026-10-22'))->toBe('200.00')
        ->and($venta->refresh()->saldo_pendiente)->toBe('0.00')
        ->and($venta->estado_entrega)->toBe(EstadoEntrega::Entregado);
});

test('paid in full when ordering and delivered three days later: collected on the order day, the delivery moves no cash', function () {
    despachoHora('2026-10-19 10:00');
    $venta = despachoVender($this, '10', ['metodo' => 'efectivo']);
    $caja = DB::table('movimientos_caja')->count();

    despachoHora('2026-10-22 11:00');
    app(MarcarEntregada::class)->ejecutar($this->almacen, $venta, despacharTambien: true);

    expect(despachoCobradoEl($this->empresa->id, '2026-10-19'))->toBe('200.00')
        ->and(despachoCobradoEl($this->empresa->id, '2026-10-22'))->toBe('0.00')
        ->and(DB::table('movimientos_caja')->count())->toBe($caja)
        ->and(DB::table('pagos_venta')->where('venta_id', $venta->id)->count())->toBe(1);
});

test('a full return before dispatch cancels the sale, puts the stock back even unasked and refunds as today', function () {
    $venta = despachoVender($this, '5', ['metodo' => 'efectivo']);
    expect(stockDe($this->matriz, $this->producto))->toBe('5.000');

    despachoHora('2026-10-19 12:00');
    $devolucion = despachoDevolver($this->admin, $venta, '5', reingresa: false);

    $venta->refresh();
    expect($venta->estado_entrega)->toBe(EstadoEntrega::Cancelado)
        ->and(stockDe($this->matriz, $this->producto))->toBe('10.000')
        ->and($devolucion->reembolso)->toBe('100.00')
        ->and((bool) DB::table('devolucion_items')->where('devolucion_id', $devolucion->id)->value('reingresa_stock'))->toBeTrue()
        ->and(despachoEventos($venta)[1])->toBe(['por_despachar', 'cancelado', $this->admin->id, false])
        ->and(ReservadoPorDespachar::cantidades($this->empresa->id))->toBe([]);

    expect(fn () => app(DespacharVenta::class)->ejecutar($this->almacen, $venta))
        ->toThrow(ValidationException::class, 'La venta está cancelada');
});

test('a full return of a credit sale pending dispatch reduces the debt as today and cancels it', function () {
    $venta = despachoVender($this, '5', ['en_cuotas' => true, 'cuota_inicial' => '0']);
    $devolucion = despachoDevolver($this->admin, $venta, '5');

    expect($venta->refresh()->estado_entrega)->toBe(EstadoEntrega::Cancelado)
        ->and($devolucion->deuda_reducida)->toBe('100.00')
        ->and($devolucion->reembolso)->toBe('0.00')
        ->and($venta->saldo_pendiente)->toBe('0.00');
});

test('a partial return before dispatch keeps it pending and lowers the reserved units', function () {
    $venta = despachoVender($this, '5');
    despachoDevolver($this->admin, $venta, '2', reingresa: false);

    expect($venta->refresh()->estado_entrega)->toBe(EstadoEntrega::PorDespachar)
        ->and(stockDe($this->matriz, $this->producto))->toBe('7.000')
        ->and(ReservadoPorDespachar::cantidades($this->empresa->id, $this->matriz->id))->toBe([$this->matriz->id => [$this->producto->id => '3.000']]);

    // El despacho entrega lo que queda.
    app(DespacharVenta::class)->ejecutar($this->almacen, $venta);
    expect(ReservadoPorDespachar::cantidades($this->empresa->id))->toBe([]);
});

test('dispatch and full return lock the same sale: a cancelled sale never ends up dispatched', function () {
    // Gana la devolución: el despacho encuentra la venta cancelada.
    $primera = despachoVender($this, '2');
    despachoDevolver($this->admin, $primera, '2');
    expect(fn () => app(DespacharVenta::class)->ejecutar($this->almacen, $primera))->toThrow(ValidationException::class)
        ->and($primera->refresh()->estado_entrega)->toBe(EstadoEntrega::Cancelado);

    // Gana el despacho: la devolución es normal (el usuario decide el reingreso) y el estado no cambia.
    $segunda = despachoVender($this, '2');
    app(DespacharVenta::class)->ejecutar($this->almacen, $segunda);
    $stock = stockDe($this->matriz, $this->producto);
    despachoDevolver($this->admin, $segunda, '2', reingresa: false);

    expect($segunda->refresh()->estado_entrega)->toBe(EstadoEntrega::Despachado)
        ->and(stockDe($this->matriz, $this->producto))->toBe($stock);

    // Rechazada en la puerta y devuelta por completo: el administrador la cancela con una nota.
    expect(fn () => app(CancelarEntregaDevuelta::class)->ejecutar($this->admin, $segunda, ' '))->toThrow(ValidationException::class)
        ->and(fn () => app(CancelarEntregaDevuelta::class)->ejecutar($this->almacen, $segunda, 'Rechazo'))->toThrow(AuthorizationException::class);
    app(CancelarEntregaDevuelta::class)->ejecutar($this->admin, $segunda, 'El cliente rechazó en la puerta');
    expect($segunda->refresh()->estado_entrega)->toBe(EstadoEntrega::Cancelado)
        ->and(last(despachoEventos($segunda)))->toBe(['despachado', 'cancelado', $this->admin->id, false]);

    // Con devolución parcial no se puede cancelar así.
    $tercera = despachoVender($this, '2');
    app(DespacharVenta::class)->ejecutar($this->almacen, $tercera);
    despachoDevolver($this->admin, $tercera, '1');
    expect(fn () => app(CancelarEntregaDevuelta::class)->ejecutar($this->admin, $tercera, 'Rechazo'))
        ->toThrow(ValidationException::class, 'primero registra la devolución de todos los productos');
});

test('a double click on Despachar records a single event and answers who dispatched it and when', function () {
    $venta = despachoVender($this, '1');
    despachoHora('2026-10-19 10:42');
    app(DespacharVenta::class)->ejecutar($this->almacen, $venta);

    expect(fn () => app(DespacharVenta::class)->ejecutar($this->admin, $venta))
        ->toThrow(ValidationException::class, 'Ya despachada por '.$this->almacen->name.' a las 10:42.');
    expect(EventoEntrega::query()->where('venta_id', $venta->id)->where('estado_nuevo', 'despachado')->count())->toBe(1)
        ->and($venta->refresh()->despachado_por)->toBe($this->almacen->id);

    // Lo mismo al entregar dos veces, también al día siguiente.
    app(MarcarEntregada::class)->ejecutar($this->vendedor, $venta);
    despachoHora('2026-10-20 09:00');
    expect(fn () => app(MarcarEntregada::class)->ejecutar($this->vendedor, $venta))
        ->toThrow(ValidationException::class, 'Ya entregada por '.$this->vendedor->name.' el 19/10/2026 a las 10:42.');
    expect(EventoEntrega::query()->where('venta_id', $venta->id)->count())->toBe(3);
});

test('company B can neither see, dispatch, deliver nor correct sales of A, not even changing ids', function () {
    $ventaA = despachoVender($this, '2');
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $sucursalB = Sucursal::factory()->create(['empresa_id' => $empresaB->id]);
    $almacenB = despachoVendedor($sucursalB, almacen: true);
    despachoActivar($adminB, $empresaB);

    expect(Venta::query()->find($ventaA->id))->toBeNull()
        ->and(ListadoDespachos::consulta($adminB)->pluck('ventas.id')->all())->toBe([])
        ->and(ListadoDespachos::consulta($adminB, ['sucursal_id' => $this->matriz->id])->count())->toBe(0)
        ->and(ReservadoPorDespachar::cantidades($empresaB->id))->toBe([]);

    expect(fn () => app(DespacharVenta::class)->ejecutar($adminB, $ventaA))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DespacharVenta::class)->ejecutar($almacenB, $ventaA))->toThrow(AuthorizationException::class)
        ->and(fn () => app(MarcarEntregada::class)->ejecutar($adminB, $ventaA, despacharTambien: true))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CorregirEstadoEntrega::class)->ejecutar($adminB, $ventaA, EstadoEntrega::PorDespachar, 'Intento'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CancelarEntregaDevuelta::class)->ejecutar($adminB, $ventaA, 'Intento'))->toThrow(AuthorizationException::class);

    $this->actingAs($this->admin);
    expect($ventaA->refresh()->estado_entrega)->toBe(EstadoEntrega::PorDespachar)
        ->and(EventoEntrega::query()->where('venta_id', $ventaA->id)->count())->toBe(1);
});

test('the Despachos list shows only the company and, for a seller, only their branch', function () {
    despachoHora('2026-10-19 09:00');
    $matriz = despachoVender($this, '1');
    despachoHora('2026-10-20 09:00');
    $norte = despachoVender($this, '1', sucursal: $this->norte);
    $despachada = despachoVender($this, '1');
    app(DespacharVenta::class)->ejecutar($this->almacen, $despachada);
    despachoVender($this, '1', entregado: true);
    $otroCliente = new Cliente(['nombre' => 'Almacén Central', 'documento' => '1122334']);
    $otroCliente->save();
    $this->cliente = $otroCliente;
    $central = despachoVender($this, '1', sucursal: $this->norte);
    $vendedorNorte = despachoVendedor($this->norte);

    $ids = fn (User $u, array $f = []) => ListadoDespachos::consulta($u, $f)->pluck('ventas.id')->all();

    expect($ids($this->admin))->toBe([$matriz->id, $norte->id, $central->id])   // por orden de llegada
        ->and($ids($this->admin, ['sucursal_id' => $this->norte->id]))->toBe([$norte->id, $central->id])
        ->and($ids($this->admin, ['estado' => 'despachado']))->toBe([$despachada->id])
        ->and($ids($this->admin, ['cliente' => 'central']))->toBe([$central->id])
        ->and($ids($this->admin, ['desde' => '2026-10-19', 'hasta' => '2026-10-19']))->toBe([$matriz->id])
        ->and($ids($this->admin, ['estado' => 'entregado']))->toHaveCount(1)
        ->and($ids($this->vendedor))->toBe([$matriz->id])
        // El vendedor no puede pedir otra sucursal.
        ->and($ids($vendedorNorte, ['sucursal_id' => $this->matriz->id]))->toBe([$norte->id, $central->id]);
});

test('reserved for dispatch is the pending sold quantity minus returns, by product and branch', function () {
    $otro = productoDe($this->empresa, ['nombre' => 'Arroz 5kg', 'precio_consumidor' => '50.00']);
    cargarStock($this->admin, $this->matriz, $otro, '20');

    $a = despachoVender($this, '4');
    despachoVender($this, '3', producto: $otro);
    despachoVender($this, '2', sucursal: $this->norte);
    despachoDevolver($this->admin, $a, '1');
    $despachada = despachoVender($this, '5', producto: $otro);
    app(DespacharVenta::class)->ejecutar($this->almacen, $despachada);
    despachoVender($this, '1', entregado: true);

    expect(ReservadoPorDespachar::cantidades($this->empresa->id))->toBe([
        $this->matriz->id => [$this->producto->id => '3.000', $otro->id => '3.000'],
        $this->norte->id => [$this->producto->id => '2.000'],
    ])->and(ReservadoPorDespachar::cantidades($this->empresa->id, $this->matriz->id, [$otro->id]))->toBe([
        $this->matriz->id => [$otro->id => '3.000'],
    ]);
});

test('the administrator undoes the last delivery change with a note, one step at a time; nobody else can', function () {
    $venta = despachoVender($this, '2');
    app(MarcarEntregada::class)->ejecutar($this->almacen, $venta, 'Rosa', despacharTambien: true);

    expect(fn () => app(CorregirEstadoEntrega::class)->ejecutar($this->almacen, $venta, EstadoEntrega::Entregado, 'Error'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CorregirEstadoEntrega::class)->ejecutar($this->admin, $venta, EstadoEntrega::Entregado, '   '))->toThrow(ValidationException::class, 'Escribe una nota');

    app(CorregirEstadoEntrega::class)->ejecutar($this->admin, $venta, EstadoEntrega::Entregado, 'Se marcó entregado por error');
    $venta->refresh();
    expect($venta->estado_entrega)->toBe(EstadoEntrega::Despachado)
        ->and([$venta->entregado_por, $venta->entregado_en, $venta->entrega_recibio])->toBe([null, null, null])
        ->and($venta->despachado_por)->toBe($this->almacen->id)
        ->and(last(despachoEventos($venta)))->toBe(['entregado', 'despachado', $this->admin->id, true]);

    // El segundo clic llega con el estado que vio el administrador: no retrocede otro paso.
    expect(fn () => app(CorregirEstadoEntrega::class)->ejecutar($this->admin, $venta, EstadoEntrega::Entregado, 'Se marcó entregado por error'))
        ->toThrow(ValidationException::class, 'cambió mientras tanto');

    app(CorregirEstadoEntrega::class)->ejecutar($this->admin, $venta, EstadoEntrega::Despachado, 'No salió del almacén');
    expect($venta->refresh()->estado_entrega)->toBe(EstadoEntrega::PorDespachar)
        ->and($venta->despachado_por)->toBeNull()
        ->and(fn () => app(CorregirEstadoEntrega::class)->ejecutar($this->admin, $venta, EstadoEntrega::PorDespachar, 'Otra'))
        ->toThrow(ValidationException::class, 'No hay un cambio de entrega que deshacer.');

    // Entregado al vender (casilla): se deshace a Por despachar.
    $alVender = despachoVender($this, '1', entregado: true);
    app(CorregirEstadoEntrega::class)->ejecutar($this->admin, $alVender, EstadoEntrega::Entregado, 'El cliente no se lo llevó');
    expect($alVender->refresh()->estado_entrega)->toBe(EstadoEntrega::PorDespachar)
        ->and(EventoEntrega::query()->where('venta_id', $venta->id)->where('es_correccion', true)->count())->toBe(2)
        ->and(DB::table('auditoria')->where('accion', 'venta.entrega_corregida')->count())->toBe(3);

    // Una venta cancelada no admite más cambios.
    despachoDevolver($this->admin, $alVender, '1');
    expect(fn () => app(CorregirEstadoEntrega::class)->ejecutar($this->admin, $alVender, EstadoEntrega::Cancelado, 'Volver'))
        ->toThrow(ValidationException::class, 'La venta está cancelada');
});

test('the history of delivery is append only', function () {
    $venta = despachoVender($this, '1');
    $evento = EventoEntrega::query()->where('venta_id', $venta->id)->firstOrFail();

    expect(fn () => $evento->forceFill(['nota' => 'Cambio'])->save())->toThrow(LogicException::class)
        ->and(fn () => $evento->delete())->toThrow(LogicException::class);
});

test('the database rejects unknown delivery states', function () {
    $venta = despachoVender($this, '1');

    expect(fn () => DB::table('ventas')->where('id', $venta->id)->update(['estado_entrega' => 'en_camino']))
        ->toThrow(QueryException::class);
});
