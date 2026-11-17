<?php

use App\Domain\Compras\Actions\RegistrarCompra;
use App\Domain\Inventario\DatosLote;
use App\Domain\Produccion\Actions\AnularOrden;
use App\Domain\Produccion\Actions\AprobarOrden;
use App\Domain\Produccion\Actions\EmitirOrden;
use App\Domain\Produccion\Actions\FinalizarOrden;
use App\Domain\Produccion\Actions\GuardarMateriaPrima;
use App\Domain\Produccion\Actions\GuardarReceta;
use App\Domain\Produccion\Escalado;
use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Enums\Capacidad;
use App\Enums\EstadoOrdenProduccion;
use App\Enums\Rubro;
use App\Livewire\Inventario\Movimientos;
use App\Livewire\Inventario\TrazabilidadLote;
use App\Livewire\Produccion\OrdenDetalle;
use App\Livewire\Produccion\Ordenes;
use App\Livewire\Produccion\OrdenFinalizar;
use App\Livewire\Produccion\OrdenFormulario;
use App\Models\Empresa;
use App\Models\Lote;
use App\Models\OrdenProduccion;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Receta;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Producción, etapa P3 (ESPECIFICACION_PRODUCCION.md § 6, § 8, § 9 y § 13): órdenes de producción con stock, lotes y
 * costo. Receta del suavizante: 100 L = 95 L de agua + 2,5 kg de fragancia (con lote) + 3 botellas (unidades enteras).
 */

function opUnidad(Empresa $empresa, string $nombre, string $abreviatura, bool $decimales): UnidadMedida
{
    $unidad = new UnidadMedida(['nombre' => $nombre, 'abreviatura' => $abreviatura, 'admite_decimales' => $decimales, 'unidad_medida_sin' => 57]);
    $unidad->empresa_id = $empresa->id;
    $unidad->save();

    return $unidad;
}

/**
 * @param  list<array<string, string|int>>  $lineas
 */
function opComprar(User $admin, Sucursal $sucursal, array $lineas): void
{
    app(RegistrarCompra::class)->ejecutar($admin, [
        'sucursal_id' => $sucursal->id, 'proveedor_id' => Proveedor::query()->orderBy('id')->value('id'), 'referencia' => null,
        'lineas' => $lineas, 'metodo' => 'efectivo', 'efectivo' => null, 'electronico' => null, 'externo' => true, 'clave' => (string) Str::uuid(),
    ]);
}

/**
 * @param  array<string, mixed>  $extra
 */
function opEmitir(User $actor, Producto $producto, Sucursal $sucursal, string $cantidad, array $extra = []): OrdenProduccion
{
    return app(EmitirOrden::class)->emitir($actor, $extra + [
        'producto_id' => $producto->id, 'sucursal_id' => $sucursal->id, 'cantidad' => $cantidad,
        'fecha_programada' => '2026-12-01', 'nota' => null, 'clave' => (string) Str::uuid(),
    ]);
}

function opStockLote(Producto $producto, string $numero): string
{
    return (string) DB::table('stock_lotes as sl')->join('lotes as l', 'l.id', '=', 'sl.lote_id')
        ->where('sl.producto_id', $producto->id)->where('l.numero', $numero)->value('sl.cantidad');
}

/**
 * @return array<int, string> producto_id => cantidad teórica
 */
function opTeoricas(OrdenProduccion $orden): array
{
    return $orden->items()->get()->mapWithKeys(fn ($i): array => [$i->producto_id => (string) $i->cantidad_teorica])->all();
}

/**
 * Todos los mensajes de la ValidationException que lanza $accion (getMessage() solo trae el primero).
 */
function opErrores(callable $accion): string
{
    try {
        $accion();
    } catch (ValidationException $e) {
        return collect($e->errors())->flatten()->implode(' ');
    }

    return '';
}

function opOperaciones(?string $motivo = null): int
{
    return DB::table('operaciones_inventario')->where('tipo', 'produccion')->when($motivo, fn ($q) => $q->where('motivo', $motivo))->count();
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Norte']);
    $this->litro = opUnidad($this->empresa, 'Litro', 'L', true);
    $this->kg = opUnidad($this->empresa, 'Kilogramo', 'kg', true);
    $this->und = opUnidad($this->empresa, 'Unidad', 'und.', false);
    $this->suavizante = Producto::factory()->enUnidad($this->litro)->porMedida()
        ->create(['nombre' => 'Suavizante floral', 'controla_vencimiento' => true]);
    $proveedor = new Proveedor(['nombre' => 'Químicos del Oriente']);
    $proveedor->empresa_id = $this->empresa->id;
    $proveedor->save();
    $this->actingAs($this->admin);
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::General, [Capacidad::Produccion]);

    $materia = fn (UnidadMedida $unidad, string $nombre, bool $lote = false): Producto => app(GuardarMateriaPrima::class)->crear($this->admin, [
        'nombre' => $nombre, 'unidad_medida_id' => $unidad->id, 'controla_vencimiento' => $lote,
    ]);
    $this->agua = $materia($this->litro, 'Agua desmineralizada');
    $this->fragancia = $materia($this->kg, 'Fragancia floral', true);
    $this->botella = $materia($this->und, 'Botella 1 L');
    $this->receta = app(GuardarReceta::class)->ejecutar($this->admin, [
        'producto_id' => $this->suavizante->id, 'cantidad_base' => '100', 'nota' => null, 'version' => null,
        'lineas' => [
            ['producto_id' => $this->agua->id, 'cantidad' => '95'],
            ['producto_id' => $this->fragancia->id, 'cantidad' => '2.5'],
            ['producto_id' => $this->botella->id, 'cantidad' => '3'],
        ],
    ]);

    // Fragancia en dos lotes: F-1 vence antes. Costo promedio (2 × 40 + 3 × 50) / 5 = 46.
    opComprar($this->admin, $this->matriz, [
        ['producto_id' => $this->fragancia->id, 'cantidad' => '2', 'costo' => '40', 'lote' => 'F-1', 'vencimiento' => '31/03/2028'],
        ['producto_id' => $this->fragancia->id, 'cantidad' => '3', 'costo' => '50', 'lote' => 'F-2', 'vencimiento' => '31/12/2028'],
        ['producto_id' => $this->agua->id, 'cantidad' => '200', 'costo' => '0.50'],
        ['producto_id' => $this->botella->id, 'cantidad' => '10', 'costo' => '1.20'],
    ]);
});

test('P3: scaling rounds to 3 decimals and whole-unit materials up (M5)', function () {
    expect(Escalado::teorica('50', '100', '3', true))->toBe('2.000')
        ->and(Escalado::teorica('100', '100', '3', true))->toBe('3.000')
        ->and(Escalado::teorica('33.333', '100', '3', true))->toBe('1.000')
        ->and(Escalado::teorica('0.001', '100', '3', true))->toBe('1.000')
        ->and(Escalado::teorica('50', '100', '2.5', false))->toBe('1.250')
        ->and(Escalado::teorica('1', '3', '1', false))->toBe('0.333')
        ->and(Escalado::teorica('2', '3', '1', false))->toBe('0.667')
        ->and(Escalado::variacion('50', '48'))->toBe('-4.0')
        ->and(Escalado::variacion('50', '51.5'))->toBe('3.0');
});

test('P3: emitting copies the recipe, scales it and moves no stock; repeating the request returns the same order', function () {
    $clave = (string) Str::uuid();
    $orden = opEmitir($this->admin, $this->suavizante, $this->matriz, '50', ['clave' => $clave, 'nota' => 'Pedido mayorista']);

    expect($orden->fresh())
        ->codigo()->toBe('OP-0001')
        ->estado->toBe(EstadoOrdenProduccion::Emitida)
        ->receta_id->toBe($this->receta->id)
        ->cantidad_base->toBe('100.000')
        ->cantidad_planificada->toBe('50.000')
        ->and(opTeoricas($orden))->toBe([$this->agua->id => '47.500', $this->fragancia->id => '1.250', $this->botella->id => '2.000'])
        ->and(stockDe($this->matriz, $this->agua))->toBe('200.000')
        ->and(opStockLote($this->fragancia, 'F-1'))->toBe('2.000')
        ->and(opOperaciones())->toBe(0)
        ->and(DB::table('auditoria')->where('accion', 'orden_produccion.emitida')->count())->toBe(1);

    $repetida = opEmitir($this->admin, $this->suavizante, $this->matriz, '50', ['clave' => $clave]);
    expect($repetida->id)->toBe($orden->id)
        ->and(OrdenProduccion::query()->count())->toBe(1)
        ->and(opEmitir($this->admin, $this->suavizante, $this->matriz, '10')->codigo())->toBe('OP-0002');
});

test('P3: emitting validates the product, its active recipe and the quantity', function () {
    $sinReceta = Producto::factory()->enUnidad($this->litro)->porMedida()->create(['nombre' => 'Lavandina']);

    expect(fn () => opEmitir($this->admin, $sinReceta, $this->matriz, '10'))->toThrow(ValidationException::class, 'Lavandina no tiene receta')
        ->and(fn () => opEmitir($this->admin, $this->agua, $this->matriz, '10'))->toThrow(ValidationException::class, 'es materia prima')
        ->and(fn () => opEmitir($this->admin, $this->suavizante, $this->matriz, '0'))->toThrow(ValidationException::class, 'mayor que cero')
        ->and(fn () => opEmitir($this->admin, $this->suavizante, $this->matriz, '10', ['fecha_programada' => '31/12/2026']))->toThrow(ValidationException::class, 'fecha programada')
        ->and(fn () => opEmitir($this->admin, $this->suavizante, $this->matriz, '10', ['clave' => 'no-es-uuid']))->toThrow(ValidationException::class, 'clave válida');

    app(GuardarReceta::class)->cambiarEstado($this->admin, $this->suavizante, false);
    expect(fn () => opEmitir($this->admin, $this->suavizante, $this->matriz, '10'))->toThrow(ValidationException::class, 'está desactivada')
        ->and(OrdenProduccion::query()->count())->toBe(0);
});

test('P3: approving discounts exactly the scaled recipe by FEFO, keeps the costs and is idempotent', function () {
    $orden = opEmitir($this->admin, $this->suavizante, $this->matriz, '50');
    app(AprobarOrden::class)->ejecutar($this->admin, $orden);
    app(AprobarOrden::class)->ejecutar($this->admin, $orden->fresh());

    $orden->refresh();
    $operacion = DB::table('operaciones_inventario')->where('id', $orden->operacion_consumo_id)->first();

    expect($orden->estado)->toBe(EstadoOrdenProduccion::Aprobada)
        ->and($orden->aprobada_por)->toBe($this->admin->id)
        ->and(stockDe($this->matriz, $this->agua))->toBe('152.500')
        ->and(stockDe($this->matriz, $this->botella))->toBe('8.000')
        ->and(stockDe($this->matriz, $this->fragancia))->toBe('3.750')
        // FEFO: sale del lote que vence antes.
        ->and(opStockLote($this->fragancia, 'F-1'))->toBe('0.750')
        ->and(opStockLote($this->fragancia, 'F-2'))->toBe('3.000')
        ->and($operacion->serie)->toBe('PRD')
        ->and($operacion->motivo)->toBe('consumo')
        ->and($operacion->nota)->toBe('Orden OP-0001 · Suavizante floral')
        ->and(opOperaciones())->toBe(1)
        ->and($orden->items()->get()->mapWithKeys(fn ($i) => [$i->producto_id => (string) $i->costo_unitario])->all())
        ->toBe([$this->agua->id => '0.500000', $this->fragancia->id => '46.000000', $this->botella->id => '1.200000'])
        ->and(DB::table('auditoria')->where('accion', 'orden_produccion.aprobada')->count())->toBe(1);
});

test('P3: approving without enough stock saves nothing; the second order that needs the last stock fails', function () {
    $grande = opEmitir($this->admin, $this->suavizante, $this->matriz, '1000');

    expect(fn () => app(AprobarOrden::class)->ejecutar($this->admin, $grande))->toThrow(ValidationException::class, 'No hay stock suficiente')
        ->and($grande->fresh()->estado)->toBe(EstadoOrdenProduccion::Emitida)
        ->and(stockDe($this->matriz, $this->agua))->toBe('200.000')
        ->and(opStockLote($this->fragancia, 'F-1'))->toBe('2.000')
        ->and(opOperaciones())->toBe(0);

    // La página muestra el faltante como aviso antes de aprobar.
    Livewire::test(OrdenDetalle::class, ['orden' => $grande])->assertSee('No alcanza el stock')->assertSee('faltan 750');

    // Dos órdenes de 150 L: 142,5 L de agua cada una; hay 200. La primera se aprueba, la segunda falla por stock.
    $primera = opEmitir($this->admin, $this->suavizante, $this->matriz, '150');
    $segunda = opEmitir($this->admin, $this->suavizante, $this->matriz, '150');
    app(AprobarOrden::class)->ejecutar($this->admin, $primera);

    expect(opErrores(fn () => app(AprobarOrden::class)->ejecutar($this->admin, $segunda)))->toContain('Agua desmineralizada: stock disponible 57,5')
        ->and($segunda->fresh()->estado)->toBe(EstadoOrdenProduccion::Emitida)
        ->and(stockDe($this->matriz, $this->agua))->toBe('57.500');
});

test('P3: approving never uses expired raw material lots (M3)', function () {
    DB::table('lotes')->where('producto_id', $this->fragancia->id)->update(['vencimiento' => '2020-01-31']);
    $orden = opEmitir($this->admin, $this->suavizante, $this->matriz, '50');

    expect(opErrores(fn () => app(AprobarOrden::class)->ejecutar($this->admin, $orden)))->toContain('Fragancia floral: disponible 0; 5 vencidas')
        ->and($orden->fresh()->estado)->toBe(EstadoOrdenProduccion::Emitida)
        ->and(stockDe($this->matriz, $this->fragancia))->toBe('5.000');
});

test('P3: finalizing with less consumption returns the difference to its lot and enters the product in lot OP with its cost', function () {
    $orden = opEmitir($this->admin, $this->suavizante, $this->matriz, '50');
    app(AprobarOrden::class)->ejecutar($this->admin, $orden);
    $vencimiento = Carbon::parse(DatosLote::hoy())->addDays(180)->format('Y-m-d');
    $datos = ['cantidad_obtenida' => '48', 'consumos' => [$this->agua->id => '47.5', $this->fragancia->id => '1', $this->botella->id => '2'], 'vencimiento' => $vencimiento];
    app(FinalizarOrden::class)->ejecutar($this->admin, $orden->fresh(), $datos);
    app(FinalizarOrden::class)->ejecutar($this->admin, $orden->fresh(), $datos);

    $orden->refresh();
    $lote = DB::table('lotes')->where('producto_id', $this->suavizante->id)->where('numero', 'OP-0001')->first();

    // Costo = 47,5 × 0,50 + 1 × 46 + 2 × 1,20 = 72,15; unitario = 72,15 / 48 = 1,503125.
    expect($orden->estado)->toBe(EstadoOrdenProduccion::Finalizada)
        ->and($orden->cantidad_obtenida)->toBe('48.000')
        ->and($orden->costo_total)->toBe('72.150000')
        ->and($orden->costo_unitario)->toBe('1.503125')
        ->and($this->suavizante->fresh()->costo_promedio)->toBe('1.503125')
        ->and(opStockLote($this->fragancia, 'F-1'))->toBe('1.000')
        ->and(opStockLote($this->fragancia, 'F-2'))->toBe('3.000')
        ->and(stockDe($this->matriz, $this->agua))->toBe('152.500')
        ->and(stockDe($this->matriz, $this->suavizante))->toBe('48.000')
        ->and(substr((string) $lote->vencimiento, 0, 10))->toBe($vencimiento)
        ->and($orden->lote_id)->toBe((int) $lote->id)
        ->and(opStockLote($this->suavizante, 'OP-0001'))->toBe('48.000')
        ->and(opOperaciones('cierre'))->toBe(1)
        ->and($orden->items()->get()->mapWithKeys(fn ($i) => [$i->producto_id => (string) $i->cantidad_real])->all())
        ->toBe([$this->agua->id => '47.500', $this->fragancia->id => '1.000', $this->botella->id => '2.000']);

    // La trazabilidad del lote producido y del lote de fragancia lleva a la orden.
    Livewire::test(TrazabilidadLote::class, ['lote' => Lote::query()->findOrFail($lote->id)])->assertSee('OP-0001')->assertSee(route('produccion.ordenes.ver', $orden));
    $loteFragancia = DB::table('lotes')->where('producto_id', $this->fragancia->id)->where('numero', 'F-1')->value('id');
    Livewire::test(TrazabilidadLote::class, ['lote' => Lote::query()->findOrFail($loteFragancia)])->assertSee('Orden OP-0001');

    // Otra orden con el producto ya en stock: costo 9,5 × 0,50 + 0,25 × 46 + 1 × 1,20 = 17,45 (0,3 botellas → 1);
    // PPP = (48 × 1,503125 + 17,45) / 58 = 89,60 / 58 = 1,5448275… → 1,544828.
    $otra = opEmitir($this->admin, $this->suavizante, $this->matriz, '10');
    app(AprobarOrden::class)->ejecutar($this->admin, $otra);
    app(FinalizarOrden::class)->ejecutar($this->admin, $otra->fresh(), ['cantidad_obtenida' => '10', 'consumos' => opTeoricas($otra), 'vencimiento' => $vencimiento]);

    expect($otra->fresh())->costo_total->toBe('17.450000')->costo_unitario->toBe('1.745000')
        ->and($this->suavizante->fresh()->costo_promedio)->toBe('1.544828');
});

test('P3: finalizing with more consumption discounts the extra; if stock is short nothing is saved', function () {
    $orden = opEmitir($this->admin, $this->suavizante, $this->matriz, '50');
    app(AprobarOrden::class)->ejecutar($this->admin, $orden);
    $consumos = [$this->agua->id => '300', $this->fragancia->id => '1.25', $this->botella->id => '2'];

    expect(fn () => app(FinalizarOrden::class)->ejecutar($this->admin, $orden->fresh(), ['cantidad_obtenida' => '50', 'consumos' => $consumos, 'vencimiento' => '15/06/2027']))
        ->toThrow(ValidationException::class, 'No hay stock suficiente')
        ->and($orden->fresh()->estado)->toBe(EstadoOrdenProduccion::Aprobada)
        ->and(stockDe($this->matriz, $this->suavizante))->toBe('0.000')
        ->and(opOperaciones('cierre'))->toBe(0);

    $consumos[$this->agua->id] = '50';
    $consumos[$this->fragancia->id] = '2';
    app(FinalizarOrden::class)->ejecutar($this->admin, $orden->fresh(), ['cantidad_obtenida' => '51.5', 'consumos' => $consumos, 'vencimiento' => '15/06/2027']);

    expect(stockDe($this->matriz, $this->agua))->toBe('150.000')
        // 0,75 más de fragancia por FEFO: lo que quedaba en F-1.
        ->and(opStockLote($this->fragancia, 'F-1'))->toBe('0.000')
        ->and(opStockLote($this->fragancia, 'F-2'))->toBe('3.000')
        ->and(stockDe($this->matriz, $this->suavizante))->toBe('51.500')
        ->and(substr((string) DB::table('lotes')->where('numero', 'OP-0001')->value('vencimiento'), 0, 10))->toBe('2027-06-15');
});

test('P3: finalizing validates quantities, whole units and the lot expiry', function () {
    $orden = opEmitir($this->admin, $this->suavizante, $this->matriz, '50');
    $consumos = [$this->agua->id => '47.5', $this->fragancia->id => '1.25', $this->botella->id => '2'];
    $finalizar = fn (array $datos) => app(FinalizarOrden::class)->ejecutar($this->admin, $orden->fresh(), $datos + ['cantidad_obtenida' => '50', 'consumos' => $consumos]);

    expect(fn () => $finalizar(['vencimiento' => '31/12/2027']))->toThrow(ValidationException::class, 'Aprueba la orden OP-0001');

    app(AprobarOrden::class)->ejecutar($this->admin, $orden);

    // Sin vida útil en el producto (se quitó el 16/11/2026): el vencimiento del lote producido siempre se escribe.
    expect(fn () => $finalizar([]))->toThrow(ValidationException::class, 'Escribe la fecha de vencimiento del lote producido')
        ->and(fn () => $finalizar(['vencimiento' => '  ']))->toThrow(ValidationException::class, 'Escribe la fecha de vencimiento del lote producido')
        ->and(fn () => $finalizar(['vencimiento' => '31-31-2027']))->toThrow(ValidationException::class, 'dd/mm/aaaa')
        ->and(fn () => $finalizar(['vencimiento' => '01/01/2020']))->toThrow(ValidationException::class, 'anterior a hoy')
        ->and(fn () => $finalizar(['vencimiento' => '31/12/2027', 'cantidad_obtenida' => '0']))->toThrow(ValidationException::class, 'cantidad obtenida')
        ->and(fn () => $finalizar(['vencimiento' => '31/12/2027', 'consumos' => [$this->botella->id => '1.5'] + $consumos]))->toThrow(ValidationException::class, 'unidades enteras')
        ->and(fn () => $finalizar(['vencimiento' => '31/12/2027', 'consumos' => [$this->agua->id => '47.5']]))->toThrow(ValidationException::class, 'escribe lo que se usó')
        ->and($orden->fresh()->estado)->toBe(EstadoOrdenProduccion::Aprobada);
});

test('P3: annulling returns every raw material of an approved order to its lots; a finalized order cannot be annulled', function () {
    $emitida = opEmitir($this->admin, $this->suavizante, $this->matriz, '10');
    app(AnularOrden::class)->ejecutar($this->admin, $emitida, 'Se canceló el pedido');

    expect($emitida->fresh())->estado->toBe(EstadoOrdenProduccion::Anulada)->operacion_anulacion_id->toBeNull()
        ->and(opOperaciones())->toBe(0);

    $orden = opEmitir($this->admin, $this->suavizante, $this->matriz, '100');
    app(AprobarOrden::class)->ejecutar($this->admin, $orden);

    expect(fn () => app(AnularOrden::class)->ejecutar($this->admin, $orden->fresh(), 'no'))->toThrow(ValidationException::class, 'Explica el motivo');

    app(AnularOrden::class)->ejecutar($this->admin, $orden->fresh(), 'Falla en la mezcladora');
    app(AnularOrden::class)->ejecutar($this->admin, $orden->fresh(), 'Falla en la mezcladora');

    expect($orden->fresh())->estado->toBe(EstadoOrdenProduccion::Anulada)->motivo_anulacion->toBe('Falla en la mezcladora')
        ->and(stockDe($this->matriz, $this->agua))->toBe('200.000')
        ->and(stockDe($this->matriz, $this->botella))->toBe('10.000')
        ->and(opStockLote($this->fragancia, 'F-1'))->toBe('2.000')
        ->and(opStockLote($this->fragancia, 'F-2'))->toBe('3.000')
        ->and($this->fragancia->fresh()->costo_promedio)->toBe('46.000000')
        ->and(opOperaciones('anulacion'))->toBe(1)
        ->and(fn () => app(AprobarOrden::class)->ejecutar($this->admin, $orden->fresh()))->toThrow(ValidationException::class, 'está anulada');

    $finalizada = opEmitir($this->admin, $this->suavizante, $this->matriz, '10');
    app(AprobarOrden::class)->ejecutar($this->admin, $finalizada);
    app(FinalizarOrden::class)->ejecutar($this->admin, $finalizada->fresh(), ['cantidad_obtenida' => '10', 'consumos' => opTeoricas($finalizada), 'vencimiento' => '31/12/2027']);

    expect(fn () => app(AnularOrden::class)->ejecutar($this->admin, $finalizada->fresh(), 'Ya no se quiere'))->toThrow(ValidationException::class, 'ya está finalizada');
});

test('P3: editing an emitted order recalculates from its own recipe copy; a new recipe version does not change it', function () {
    $orden = opEmitir($this->admin, $this->suavizante, $this->matriz, '50');
    app(GuardarReceta::class)->ejecutar($this->admin, [
        'producto_id' => $this->suavizante->id, 'cantidad_base' => '100', 'nota' => null, 'version' => 1,
        'lineas' => [['producto_id' => $this->agua->id, 'cantidad' => '90'], ['producto_id' => $this->fragancia->id, 'cantidad' => '5']],
    ]);

    expect(opTeoricas($orden))->toBe([$this->agua->id => '47.500', $this->fragancia->id => '1.250', $this->botella->id => '2.000']);

    app(EmitirOrden::class)->editar($this->admin, $orden, ['sucursal_id' => $this->norte->id, 'cantidad' => '100', 'fecha_programada' => '2026-12-05', 'nota' => 'Otra fecha']);

    expect($orden->fresh())
        ->sucursal_id->toBe($this->norte->id)
        ->receta_id->toBe($this->receta->id)
        ->fecha_programada->format('Y-m-d')->toBe('2026-12-05')
        ->and(opTeoricas($orden))->toBe([$this->agua->id => '95.000', $this->fragancia->id => '2.500', $this->botella->id => '3.000'])
        ->and(DB::table('auditoria')->where('accion', 'orden_produccion.editada')->count())->toBe(1)
        // Una orden nueva sí usa la versión 2.
        ->and(opTeoricas(opEmitir($this->admin, $this->suavizante, $this->matriz, '100')))->toBe([$this->agua->id => '90.000', $this->fragancia->id => '5.000']);

    // En Norte no hay stock: no se aprueba. De vuelta a Casa Matriz se aprueba y ya no se edita.
    expect(fn () => app(AprobarOrden::class)->ejecutar($this->admin, $orden->fresh()))->toThrow(ValidationException::class, 'No hay stock suficiente');
    app(EmitirOrden::class)->editar($this->admin, $orden->fresh(), ['sucursal_id' => $this->matriz->id, 'cantidad' => '100', 'fecha_programada' => '2026-12-05', 'nota' => null]);
    app(AprobarOrden::class)->ejecutar($this->admin, $orden->fresh());

    expect(fn () => app(EmitirOrden::class)->editar($this->admin, $orden->fresh(), ['sucursal_id' => $this->matriz->id, 'cantidad' => '10', 'fecha_programada' => '2026-12-05']))
        ->toThrow(ValidationException::class, 'ya está aprobada');
});

test('P3: Producción cannot be turned off while orders are open; off, the screens answer 404', function () {
    $orden = opEmitir($this->admin, $this->suavizante, $this->matriz, '10');
    Livewire::withQueryParams([])->test(Movimientos::class)->assertSeeHtml('value="produccion"');

    expect(fn () => app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa->fresh(), Rubro::General, [Capacidad::LotesVencimientos]))
        ->toThrow(ValidationException::class, 'la orden OP-0001 está abierta');

    app(AnularOrden::class)->ejecutar($this->admin, $orden, 'Se canceló el pedido');
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa->fresh(), Rubro::General, [Capacidad::LotesVencimientos]);

    $this->get(route('produccion.ordenes'))->assertNotFound();
    $this->get(route('produccion.ordenes.ver', $orden))->assertNotFound();
    Livewire::withQueryParams([])->test(Movimientos::class)->assertDontSeeHtml('value="produccion"');
    expect(fn () => opEmitir($this->admin, $this->suavizante, $this->matriz, '10'))->toThrow(AuthorizationException::class);
});

test('P3: another company or a seller cannot see or act on the orders', function () {
    $orden = opEmitir($this->admin, $this->suavizante, $this->matriz, '10');
    $vendedor = User::factory()->vendedor($this->matriz)->create();

    $this->actingAs($vendedor)->get(route('produccion.ordenes'))->assertForbidden();
    expect(fn () => app(AprobarOrden::class)->ejecutar($vendedor, $orden))->toThrow(AuthorizationException::class);

    [$otra, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $sucursalB = Sucursal::factory()->create(['empresa_id' => $otra->id, 'nombre' => 'Sucursal B']);
    app(CambiarFuncionesEmpresa::class)->ejecutar($adminB, $otra, Rubro::General, [Capacidad::Produccion]);

    $this->get(route('produccion.ordenes.ver', $orden))->assertNotFound();
    $this->get(route('produccion.ordenes.finalizar', $orden))->assertNotFound();
    Livewire::test(Ordenes::class)->assertDontSee('OP-0001');

    expect(fn () => app(AprobarOrden::class)->ejecutar($adminB, $orden))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(AnularOrden::class)->ejecutar($adminB, $orden, 'Intento ajeno'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => opEmitir($adminB, $this->suavizante, $sucursalB, '10'))->toThrow(ValidationException::class, 'Elige un producto de tu empresa')
        ->and(fn () => app(EmitirOrden::class)->editar($adminB, $orden, ['sucursal_id' => $sucursalB->id, 'cantidad' => '5', 'fecha_programada' => '2026-12-01']))->toThrow(ModelNotFoundException::class)
        ->and($orden->fresh()->estado)->toBe(EstadoOrdenProduccion::Emitida);

    $this->actingAs($this->admin);
    expect(fn () => opEmitir($this->admin, $this->suavizante, $sucursalB, '10'))->toThrow(ValidationException::class, 'Selecciona una sucursal activa');
});

test('P3: the screens emit, approve and finalize an order', function () {
    Livewire::withQueryParams(['producto' => (string) $this->suavizante->id])->test(OrdenFormulario::class)
        ->assertSet('producto_id', (string) $this->suavizante->id)
        ->set('sucursal_id', (string) $this->matriz->id)
        ->set('cantidad', '300')
        ->assertSee('No alcanza el stock')
        ->set('cantidad', '50')
        ->assertDontSee('No alcanza el stock')
        ->assertSee('47,5')
        ->call('guardar')
        ->assertRedirect(route('produccion.ordenes.ver', OrdenProduccion::query()->sole()));

    $orden = OrdenProduccion::query()->sole();
    Livewire::withQueryParams([])->test(Ordenes::class)->assertSee('OP-0001')->assertSee('Suavizante floral');

    Livewire::test(OrdenDetalle::class, ['orden' => $orden])
        ->set('aprobando', true)
        ->assertSee('Se descontará del almacén de Casa Matriz')
        ->call('aprobar')
        ->assertSet('aprobando', false)
        ->assertSee('Registrar lo producido');

    Livewire::test(OrdenFinalizar::class, ['orden' => $orden->fresh()])
        ->assertSet('cantidad_obtenida', '50')
        ->assertSet('consumos.'.$this->fragancia->id, '1.25')
        ->assertSet('vencimiento', '')
        ->assertSee('Fecha en que vence lo producido. Se guarda en el lote OP-0001.')
        ->set('cantidad_obtenida', '45')
        ->assertSee('Merma de 10 %')
        ->set('consumos.'.$this->agua->id, '45')
        ->assertSee('Vuelven 2,5')
        ->call('finalizar')
        ->assertHasErrors('vencimiento')
        ->set('vencimiento', Carbon::parse(DatosLote::hoy())->addDays(180)->format('Y-m-d'))
        ->call('finalizar')
        ->assertRedirect(route('produccion.ordenes.ver', $orden));

    expect($orden->fresh()->estado)->toBe(EstadoOrdenProduccion::Finalizada)
        ->and(stockDe($this->matriz, $this->suavizante))->toBe('45.000')
        ->and(stockDe($this->matriz, $this->agua))->toBe('155.000');

    Livewire::test(OrdenDetalle::class, ['orden' => $orden->fresh()])->assertSee('Lote producido')->assertSee('−10 %', false);
});
