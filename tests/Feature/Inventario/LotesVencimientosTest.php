<?php

use App\Domain\Catalogo\Actions\GuardarProducto;
use App\Domain\Catalogo\Actions\MarcarProductosConLote;
use App\Domain\Compras\Actions\RegistrarCompra;
use App\Domain\Inventario\Actions\RegistrarAjuste;
use App\Domain\Inventario\Actions\RegistrarTraspaso;
use App\Domain\Inventario\Actions\RegularizarLote;
use App\Domain\Inventario\DatosLote;
use App\Domain\Inventario\LibroStock;
use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Domain\Ventas\Actions\RegistrarDevolucion;
use App\Enums\Capacidad;
use App\Enums\Rubro;
use App\Enums\TipoOperacionInventario;
use App\Livewire\Catalogo\ProductoFormulario;
use App\Livewire\Catalogo\Productos;
use App\Livewire\Compras\Detalle;
use App\Livewire\Compras\NuevaCompra;
use App\Livewire\Empresa\FuncionesNegocio;
use App\Livewire\Inventario\NuevoAjuste;
use App\Livewire\Inventario\NuevoTraspaso;
use App\Livewire\Inventario\TrazabilidadLote;
use App\Livewire\Inventario\Vencimientos;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\Lote;
use App\Models\MovimientoCaja;
use App\Models\MovimientoStock;
use App\Models\OperacionInventario;
use App\Models\PagoVenta;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Lotes y vencimientos, etapa L1 (ESPECIFICACION_LOTES_Y_VENCIMIENTOS.md § 10): esquema, LibroLotes dentro de LibroStock
 * con su invariante (stock_niveles = Σ stock_lotes) y compras con lote. Las ventas, salidas, traspasos y devoluciones
 * pasan por el mismo núcleo; sus pantallas y mensajes propios son de L2 y L3.
 */

/**
 * @param  list<array<string, mixed>>|array<int, array<string, mixed>>  $lineas
 * @param  array<string, mixed>  $extra
 */
function lotesComprar(User $admin, Sucursal $sucursal, array $lineas, array $extra = []): Compra
{
    return app(RegistrarCompra::class)->ejecutar($admin, $extra + [
        'sucursal_id' => $sucursal->id, 'proveedor_id' => Proveedor::query()->orderBy('id')->value('id'), 'referencia' => null,
        'lineas' => $lineas, 'metodo' => 'efectivo', 'efectivo' => null, 'electronico' => null, 'externo' => true, 'clave' => (string) Str::uuid(),
    ]);
}

/**
 * @return array{producto_id: int, cantidad: string, costo: string, lote: string, vencimiento: string}
 */
function lineaLote(Producto $producto, string $cantidad, string $lote, string $vencimiento, string $costo = '10'): array
{
    return ['producto_id' => $producto->id, 'cantidad' => $cantidad, 'costo' => $costo, 'lote' => $lote, 'vencimiento' => $vencimiento];
}

function loteDe(Producto $producto, string $numero): Lote
{
    return Lote::query()->where('producto_id', $producto->id)->where('numero', $numero)->sole();
}

function loteRegularizacion(Producto $producto): Lote
{
    return Lote::query()->where('producto_id', $producto->id)->where('es_regularizacion', true)->sole();
}

function saldoLote(Sucursal $sucursal, Lote $lote): string
{
    return (string) (DB::table('stock_lotes')->where('sucursal_id', $sucursal->id)->where('lote_id', $lote->id)->value('cantidad') ?? '0.000');
}

/**
 * § 6.4: para todo producto con vencimiento, en cada sucursal, stock_niveles.cantidad = Σ stock_lotes.cantidad.
 */
function lotesInvariante(): void
{
    $niveles = DB::table('stock_niveles')->join('productos', 'productos.id', '=', 'stock_niveles.producto_id')
        ->where('productos.controla_vencimiento', true)->get(['stock_niveles.sucursal_id', 'stock_niveles.producto_id', 'stock_niveles.cantidad']);

    expect($niveles)->not->toBeEmpty();

    foreach ($niveles as $nivel) {
        $suma = DB::table('stock_lotes')->where('sucursal_id', $nivel->sucursal_id)->where('producto_id', $nivel->producto_id)->sum('cantidad');
        expect(Decimal::comparar((string) $suma, (string) $nivel->cantidad))
            ->toBe(0, "Sucursal {$nivel->sucursal_id}, producto {$nivel->producto_id}: total {$nivel->cantidad}, lotes {$suma}");
    }
}

function lotesVender(User $admin, Sucursal $sucursal, Producto $producto, string $cantidad): Venta
{
    return confirmarVenta($admin, crearPedido($admin, $sucursal, [['producto_id' => $producto->id, 'cantidad' => $cantidad]]), ['metodo' => 'efectivo']);
}

/**
 * @param  array<int|string, string>  $lineas
 * @param  array<string, mixed>  $extra
 */
function lotesAjustar(User $admin, string $tipo, Sucursal $sucursal, array $lineas, array $extra = []): OperacionInventario
{
    return app(RegistrarAjuste::class)->ejecutar($admin, $extra + [
        'tipo' => $tipo, 'motivo' => $tipo === 'salida' ? 'merma' : 'reposicion_manual', 'sucursal_id' => $sucursal->id,
        'nota' => 'Prueba de lotes', 'lineas' => $lineas, 'clave' => (string) Str::uuid(),
    ]);
}

/**
 * Marca o desmarca «Controla lote y vencimiento» con la acción de la ficha (lotes § 5.6).
 */
function lotesMarcar(User $admin, Producto $producto, bool $marca): Producto
{
    $producto->refresh();

    return app(GuardarProducto::class)->actualizar($admin, $producto, [
        'nombre' => $producto->nombre, 'tipo_venta' => $producto->tipo_venta, 'unidad_medida_id' => $producto->unidad_medida_id,
        'precio_consumidor' => $producto->precio_consumidor, 'stock_minimo' => '0', 'controla_stock' => true, 'controla_vencimiento' => $marca,
    ]);
}

/**
 * Mensajes de error de la operación, en una sola cadena.
 */
function lotesError(callable $accion): string
{
    try {
        $accion();
    } catch (ValidationException $e) {
        return implode(' | ', array_merge(...array_values($e->errors())));
    }

    throw new RuntimeException('Se esperaba un rechazo.');
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    $this->amoxi = productoDe($this->empresa, ['nombre' => 'Amoxicilina 500 mg', 'controla_vencimiento' => true]);
    $this->alcohol = productoDe($this->empresa, ['nombre' => 'Alcohol 70%']);
    $this->proveedor = new Proveedor(['nombre' => 'Droguería Central']);
    $this->proveedor->empresa_id = $this->empresa->id;
    $this->proveedor->save();
    $this->actingAs($this->admin);
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::Farmacia, [Capacidad::LotesVencimientos]);
    $this->travelTo(CarbonImmutable::parse('2027-01-15 10:00', 'America/La_Paz'));
});

test('the database requires stock control for expiry products and protects lots, balances and their links', function () {
    // Pendiente de K3 (ESPECIFICACION_PRODUCTOS_SIN_STOCK.md § 4.6).
    expect(fn () => DB::table('productos')->where('id', $this->amoxi->id)->update(['controla_stock' => false]))->toThrow(QueryException::class);
    expect(DB::table('productos')->where('id', $this->amoxi->id)->value('controla_stock'))->toBe(1);

    lotesComprar($this->admin, $this->matriz, [lineaLote($this->amoxi, '5', 'A1', '10/03/2027')]);
    $lote = loteDe($this->amoxi, 'A1');
    $regularizacion = fn (Producto $p) => ['empresa_id' => $this->empresa->id, 'producto_id' => $p->id, 'numero' => null, 'vencimiento' => null, 'es_regularizacion' => true];

    DB::table('lotes')->insert($regularizacion($this->amoxi));
    // Un solo lote de regularización por producto (columna generada única).
    expect(fn () => DB::table('lotes')->insert($regularizacion($this->amoxi)))->toThrow(QueryException::class)
        // Un lote normal necesita número y fecha.
        ->and(fn () => DB::table('lotes')->insert(['empresa_id' => $this->empresa->id, 'producto_id' => $this->amoxi->id, 'numero' => 'X9', 'vencimiento' => null, 'es_regularizacion' => false]))->toThrow(QueryException::class)
        // Un número = un vencimiento por producto, sin distinguir mayúsculas.
        ->and(fn () => DB::table('lotes')->insert(['empresa_id' => $this->empresa->id, 'producto_id' => $this->amoxi->id, 'numero' => 'a1', 'vencimiento' => '2027-09-30', 'es_regularizacion' => false]))->toThrow(QueryException::class)
        // Saldo negativo y movimiento incoherente.
        ->and(fn () => DB::table('stock_lotes')->where('lote_id', $lote->id)->update(['cantidad' => '-1']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('movimientos_stock_lotes')->insert([
            'empresa_id' => $this->empresa->id, 'operacion_id' => OperacionInventario::query()->value('id'), 'sucursal_id' => $this->matriz->id,
            'producto_id' => $this->amoxi->id, 'lote_id' => $lote->id, 'cantidad' => '2', 'saldo_anterior' => '5', 'saldo_posterior' => '8',
        ]))->toThrow(QueryException::class)
        // Un saldo no puede apuntar al lote de otro producto ni de otra empresa.
        ->and(fn () => DB::table('stock_lotes')->insert(['empresa_id' => $this->empresa->id, 'sucursal_id' => $this->norte->id, 'producto_id' => $this->alcohol->id, 'lote_id' => $lote->id, 'cantidad' => '0']))->toThrow(QueryException::class);

    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $sucursalB = Sucursal::factory()->create(['empresa_id' => $empresaB->id]);
    expect(fn () => DB::table('stock_lotes')->insert(['empresa_id' => $empresaB->id, 'sucursal_id' => $sucursalB->id, 'producto_id' => $this->amoxi->id, 'lote_id' => $lote->id, 'cantidad' => '0']))
        ->toThrow(QueryException::class);
});

test('a purchase with two lots of the same product creates two lots, one total movement and keeps the invariant', function () {
    $compra = lotesComprar($this->admin, $this->matriz, [
        lineaLote($this->amoxi, '10', 'A1', '10/03/2027', '2.50'),
        lineaLote($this->amoxi, '10', 'B7', '10/06/2027', '2.80'),
        ['producto_id' => $this->alcohol->id, 'cantidad' => '5', 'costo' => '12'],
    ]);

    $a = loteDe($this->amoxi, 'A1');
    $b = loteDe($this->amoxi, 'B7');

    expect($compra->total)->toBe('113.00')
        ->and($a->vencimiento->format('Y-m-d'))->toBe('2027-03-10')
        ->and($b->vencimiento->format('Y-m-d'))->toBe('2027-06-10')
        ->and(stockDe($this->matriz, $this->amoxi))->toBe('20.000')
        ->and(saldoLote($this->matriz, $a))->toBe('10.000')
        ->and(saldoLote($this->matriz, $b))->toBe('10.000')
        ->and(stockDe($this->matriz, $this->alcohol))->toBe('5.000')
        ->and(CompraItem::query()->orderBy('id')->get(['producto_id', 'lote_id', 'cantidad'])->map(fn ($i) => [$i->producto_id, $i->lote_id, $i->cantidad])->all())
        ->toBe([[$this->amoxi->id, $a->id, '10.000'], [$this->amoxi->id, $b->id, '10.000'], [$this->alcohol->id, null, '5.000']])
        // El saldo total se mueve una vez por producto; el detalle va por lote.
        ->and(MovimientoStock::query()->where('producto_id', $this->amoxi->id)->pluck('cantidad')->all())->toBe(['20.000'])
        ->and(DB::table('movimientos_stock_lotes')->where('operacion_id', $compra->operacion_inventario_id)->orderBy('lote_id')->pluck('cantidad')->all())->toBe(['10.000', '10.000'])
        ->and(Lote::query()->count())->toBe(2);

    lotesInvariante();
});

test('month and year is stored as the last day of the month, including a leap February', function () {
    expect(DatosLote::vencimiento('02/2028'))->toBe('2028-02-29')
        ->and(DatosLote::vencimiento('2/2027'))->toBe('2027-02-28')
        ->and(DatosLote::vencimiento('2028-02'))->toBe('2028-02-29')
        ->and(DatosLote::vencimiento('12/2027'))->toBe('2027-12-31')
        ->and(DatosLote::vencimiento('10/03/2027'))->toBe('2027-03-10')
        ->and(DatosLote::vencimiento('2027-03-10'))->toBe('2027-03-10')
        ->and(DatosLote::vencimiento(' 10 / 03 / 2027 '))->toBe('2027-03-10')
        ->and(DatosLote::vencimiento('31/02/2027'))->toBeNull()
        ->and(DatosLote::vencimiento('13/2027'))->toBeNull()
        ->and(DatosLote::vencimiento('03/27'))->toBeNull()
        ->and(DatosLote::vencimiento('01/01/1999'))->toBeNull()
        ->and(DatosLote::vencido('2027-01-15'))->toBeFalse()
        ->and(DatosLote::vencido('2027-01-14'))->toBeTrue()
        ->and(DatosLote::vencido(null))->toBeFalse();

    lotesComprar($this->admin, $this->matriz, [lineaLote($this->amoxi, '3', '  F28  ', '02/2028')]);

    expect(loteDe($this->amoxi, 'F28')->vencimiento->format('Y-m-d'))->toBe('2028-02-29');
});

test('a lot line needs its number and a valid date, and a rejected purchase saves nothing', function () {
    $error = lotesError(fn () => lotesComprar($this->admin, $this->matriz, [
        lineaLote($this->amoxi, '5', '', '03/2027'),
        lineaLote($this->amoxi, '5', 'B7', '31/02/2027'),
        lineaLote($this->amoxi, '5', str_repeat('X', 41), '03/2027'),
    ]));

    expect($error)->toContain('Amoxicilina 500 mg: escribe el número de lote.')
        ->toContain('Amoxicilina 500 mg: escribe el vencimiento del lote como dd/mm/aaaa o mm/aaaa.')
        ->toContain('el número de lote admite hasta 40 caracteres')
        ->and(Compra::query()->count())->toBe(0)
        ->and(Lote::query()->count())->toBe(0)
        ->and(DB::table('stock_lotes')->count())->toBe(0)
        ->and(stockDe($this->matriz, $this->amoxi))->toBe('0.000');

    // Los productos sin vencimiento ignoran estos campos.
    lotesComprar($this->admin, $this->matriz, [['producto_id' => $this->alcohol->id, 'cantidad' => '2', 'costo' => '5', 'lote' => '', 'vencimiento' => 'cualquiera']]);
    expect(stockDe($this->matriz, $this->alcohol))->toBe('2.000')->and(Lote::query()->count())->toBe(0);
});

test('the same lot number with another date is rejected, and with the same date the lot is reused', function () {
    lotesComprar($this->admin, $this->matriz, [lineaLote($this->amoxi, '10', 'L-001', '10/03/2027')]);
    // Mismo lote escrito en minúsculas, en otra sucursal: es el mismo lote (lo identifica la empresa, no la sucursal).
    lotesComprar($this->admin, $this->norte, [lineaLote($this->amoxi, '4', 'l-001', '10/03/2027')]);

    $lote = loteDe($this->amoxi, 'L-001');
    expect(Lote::query()->count())->toBe(1)
        ->and(saldoLote($this->matriz, $lote))->toBe('10.000')
        ->and(saldoLote($this->norte, $lote))->toBe('4.000');

    $error = lotesError(fn () => lotesComprar($this->admin, $this->matriz, [lineaLote($this->amoxi, '1', 'L-001', '04/2027')]));

    expect($error)->toContain('Amoxicilina 500 mg: el lote L-001 ya está registrado con vencimiento 10/03/2027.')
        ->and(Compra::query()->count())->toBe(2)
        ->and($lote->refresh()->vencimiento->format('Y-m-d'))->toBe('2027-03-10');

    // En una misma compra, el mismo lote con dos fechas tampoco se acepta.
    expect(lotesError(fn () => lotesComprar($this->admin, $this->matriz, [
        lineaLote($this->amoxi, '1', 'N-5', '05/2027'), lineaLote($this->amoxi, '1', 'n-5', '06/2027'),
    ])))->toContain('Amoxicilina 500 mg: el lote N-5 tiene dos vencimientos distintos.');

    lotesInvariante();
});

test('a lot left by a purchase that failed after creating it can be retried with a corrected date', function () {
    // Efectivo por caja sin turno abierto: falla dentro de la transacción, después de crear el lote.
    expect(fn () => lotesComprar($this->admin, $this->matriz, [lineaLote($this->amoxi, '6', 'Z-9', '03/2027')], ['externo' => false]))
        ->toThrow(ValidationException::class, 'requiere un turno abierto');
    expect(Compra::query()->count())->toBe(0)
        ->and(loteDe($this->amoxi, 'Z-9')->vencimiento->format('Y-m-d'))->toBe('2027-03-31')
        ->and(DB::table('movimientos_stock_lotes')->count())->toBe(0);

    lotesComprar($this->admin, $this->matriz, [lineaLote($this->amoxi, '6', 'Z-9', '04/2027')]);

    expect(Lote::query()->count())->toBe(1)
        ->and(loteDe($this->amoxi, 'Z-9')->vencimiento->format('Y-m-d'))->toBe('2027-04-30')
        ->and(saldoLote($this->matriz, loteDe($this->amoxi, 'Z-9')))->toBe('6.000');
    lotesInvariante();
});

test('repeated lines are grouped like before, and one group cannot have two costs', function () {
    lotesComprar($this->admin, $this->matriz, [
        ['producto_id' => $this->alcohol->id, 'cantidad' => '2', 'costo' => '5'],
        ['producto_id' => $this->alcohol->id, 'cantidad' => '3', 'costo' => '5'],
        lineaLote($this->amoxi, '2', 'A1', '03/2027', '4'),
        lineaLote($this->amoxi, '1', 'a1', '31/03/2027', '4'),
    ]);

    expect(CompraItem::query()->count())->toBe(2)
        ->and(CompraItem::query()->where('producto_id', $this->alcohol->id)->sole()->cantidad)->toBe('5.000')
        ->and(CompraItem::query()->where('producto_id', $this->amoxi->id)->sole()->cantidad)->toBe('3.000')
        ->and(stockDe($this->matriz, $this->alcohol))->toBe('5.000');

    expect(lotesError(fn () => lotesComprar($this->admin, $this->matriz, [
        ['producto_id' => $this->alcohol->id, 'cantidad' => '2', 'costo' => '5'],
        ['producto_id' => $this->alcohol->id, 'cantidad' => '3', 'costo' => '6'],
    ])))->toContain('Alcohol 70% aparece en varias líneas con costos distintos');
    expect(Compra::query()->count())->toBe(1);
});

test('FEFO: lot A (10/03) and lot B (10/06) with 10 each, selling 12 leaves A at 0 and B at 8', function () {
    lotesComprar($this->admin, $this->matriz, [
        lineaLote($this->amoxi, '10', 'B', '10/06/2027'),
        lineaLote($this->amoxi, '10', 'A', '10/03/2027'),
    ]);

    $venta = lotesVender($this->admin, $this->matriz, $this->amoxi, '12');

    expect(saldoLote($this->matriz, loteDe($this->amoxi, 'A')))->toBe('0.000')
        ->and(saldoLote($this->matriz, loteDe($this->amoxi, 'B')))->toBe('8.000')
        ->and(stockDe($this->matriz, $this->amoxi))->toBe('8.000')
        ->and(DB::table('movimientos_stock_lotes')->where('operacion_id', $venta->operacion_inventario_id)->orderBy('id')->pluck('cantidad', 'lote_id')->all())
        ->toEqual([loteDe($this->amoxi, 'A')->id => '-10.000', loteDe($this->amoxi, 'B')->id => '-2.000']);
    lotesInvariante();
});

test('the expiry day still sells; the next day only expired stock is left and the sale saves nothing', function () {
    lotesComprar($this->admin, $this->matriz, [lineaLote($this->amoxi, '5', 'A', '10/03/2027')]);

    $this->travelTo(CarbonImmutable::parse('2027-03-10 22:30', 'America/La_Paz'));
    lotesVender($this->admin, $this->matriz, $this->amoxi, '1');
    expect(saldoLote($this->matriz, loteDe($this->amoxi, 'A')))->toBe('4.000');

    $this->travelTo(CarbonImmutable::parse('2027-03-11 00:05', 'America/La_Paz'));
    $pedido = crearPedido($this->admin, $this->matriz, [['producto_id' => $this->amoxi->id, 'cantidad' => '1']]);
    $conteos = fn () => [Venta::query()->count(), PagoVenta::query()->count(), MovimientoCaja::query()->count(), OperacionInventario::query()->count(), MovimientoStock::query()->count(), DB::table('movimientos_stock_lotes')->count()];
    $antes = $conteos();

    // El pedido pendiente se guardó; confirmarlo se rechaza y el mensaje distingue vendible de vencido.
    expect(Pedido::query()->find($pedido->id))->not->toBeNull()
        ->and(lotesError(fn () => confirmarVenta($this->admin, $pedido, ['metodo' => 'efectivo'])))
        ->toContain('Amoxicilina 500 mg: disponible 0; 4 vencidas (dar de baja en Ajustes → Salida → Vencido).')
        ->and($conteos())->toBe($antes)
        ->and(stockDe($this->matriz, $this->amoxi))->toBe('4.000');
    lotesInvariante();
});

test('regularization stock sells first; a manual exit takes expired lots first, then regularization, then FEFO', function () {
    // Stock anterior al control por lote: al marcar el producto pasa al lote de regularización (§ 5.6.1).
    DB::table('productos')->where('id', $this->amoxi->id)->update(['controla_vencimiento' => false]);
    lotesAjustar($this->admin, 'entrada', $this->matriz, [$this->amoxi->id => '2']);
    lotesMarcar($this->admin, $this->amoxi, true);
    lotesComprar($this->admin, $this->matriz, [
        lineaLote($this->amoxi, '5', 'B', '10/06/2027'),
        lineaLote($this->amoxi, '5', 'C', '10/03/2027'),
        lineaLote($this->amoxi, '3', 'X', '12/2026'),   // ya vencido: se acepta y queda bloqueado (§ 5.1.4)
    ]);
    $reg = loteRegularizacion($this->amoxi);

    lotesVender($this->admin, $this->matriz, $this->amoxi, '1');
    expect(saldoLote($this->matriz, $reg))->toBe('1.000')
        ->and(saldoLote($this->matriz, loteDe($this->amoxi, 'X')))->toBe('3.000');

    lotesAjustar($this->admin, 'salida', $this->matriz, [$this->amoxi->id => '5']);

    expect(saldoLote($this->matriz, loteDe($this->amoxi, 'X')))->toBe('0.000')
        ->and(saldoLote($this->matriz, $reg))->toBe('0.000')
        ->and(saldoLote($this->matriz, loteDe($this->amoxi, 'C')))->toBe('4.000')
        ->and(saldoLote($this->matriz, loteDe($this->amoxi, 'B')))->toBe('5.000')
        ->and(stockDe($this->matriz, $this->amoxi))->toBe('9.000');

    // Un ajuste que resta también da de baja primero lo vencido; aquí ya no queda, así que sigue por FEFO.
    lotesAjustar($this->admin, 'ajuste', $this->matriz, [$this->amoxi->id => '4'], ['direccion' => 'restar']);
    expect(saldoLote($this->matriz, loteDe($this->amoxi, 'C')))->toBe('0.000');
    lotesInvariante();
});

test('a transfer moves only non-expired lots by FEFO and never moves expired stock', function () {
    lotesComprar($this->admin, $this->matriz, [
        lineaLote($this->amoxi, '4', 'A', '10/03/2027'),
        lineaLote($this->amoxi, '4', 'B', '10/06/2027'),
        lineaLote($this->amoxi, '6', 'X', '12/2026'),
    ]);

    app(RegistrarTraspaso::class)->ejecutar($this->admin, [
        'sucursal_id' => $this->matriz->id, 'sucursal_destino_id' => $this->norte->id, 'nota' => null,
        'lineas' => [$this->amoxi->id => '5'], 'clave' => (string) Str::uuid(),
    ]);

    expect(saldoLote($this->matriz, loteDe($this->amoxi, 'A')))->toBe('0.000')
        ->and(saldoLote($this->matriz, loteDe($this->amoxi, 'B')))->toBe('3.000')
        ->and(saldoLote($this->matriz, loteDe($this->amoxi, 'X')))->toBe('6.000')
        ->and(stockDe($this->norte, $this->amoxi))->toBe('5.000')
        // § 5.3: el destino recibe los mismos lotes y cantidades que salieron del origen, sin regularización.
        ->and(saldoLote($this->norte, loteDe($this->amoxi, 'A')))->toBe('4.000')
        ->and(saldoLote($this->norte, loteDe($this->amoxi, 'B')))->toBe('1.000')
        ->and(saldoLote($this->norte, loteDe($this->amoxi, 'X')))->toBe('0.000')
        ->and(Lote::query()->where('es_regularizacion', true)->exists())->toBeFalse();

    // El libro por lote empareja salida y entrada de cada lote bajo la misma operación.
    $operacion = OperacionInventario::query()->where('tipo', 'traspaso')->sole();
    expect(DB::table('movimientos_stock_lotes')->where('operacion_id', $operacion->id)->orderBy('sucursal_id')->orderBy('lote_id')
        ->get(['sucursal_id', 'lote_id', 'cantidad'])->map(fn ($m) => [(int) $m->sucursal_id, (int) $m->lote_id, (string) $m->cantidad])->all())
        ->toBe([
            [$this->matriz->id, loteDe($this->amoxi, 'A')->id, '-4.000'], [$this->matriz->id, loteDe($this->amoxi, 'B')->id, '-1.000'],
            [$this->norte->id, loteDe($this->amoxi, 'A')->id, '4.000'], [$this->norte->id, loteDe($this->amoxi, 'B')->id, '1.000'],
        ]);

    // Desde el destino se vende por FEFO lo recibido.
    lotesVender($this->admin, $this->norte, $this->amoxi, '5');
    expect(stockDe($this->norte, $this->amoxi))->toBe('0.000');

    expect(lotesError(fn () => app(RegistrarTraspaso::class)->ejecutar($this->admin, [
        'sucursal_id' => $this->matriz->id, 'sucursal_destino_id' => $this->norte->id, 'nota' => null,
        'lineas' => [$this->amoxi->id => '4'], 'clave' => (string) Str::uuid(),
    ])))->toContain('Amoxicilina 500 mg: disponible para traspasar 3; 6 vencidas no se traspasan');
    lotesInvariante();
});

test('a return goes back to the lots that sale took, latest expiry first, and never more than it took from each', function () {
    lotesComprar($this->admin, $this->matriz, [
        lineaLote($this->amoxi, '4', 'A', '10/03/2027'),
        lineaLote($this->amoxi, '4', 'B', '10/06/2027'),
        lineaLote($this->amoxi, '5', 'C', '10/09/2027'),
    ]);
    $venta = lotesVender($this->admin, $this->matriz, $this->amoxi, '6');   // A 4 y B 2 (FEFO)
    lotesVender($this->admin, $this->matriz, $this->amoxi, '2');            // otra venta: B 2
    $item = VentaItem::query()->where('venta_id', $venta->id)->sole();
    $devolver = fn (string $cantidad) => app(RegistrarDevolucion::class)->ejecutar($this->admin, $venta, [
        'lineas' => [$item->id => ['cantidad' => $cantidad, 'reingresa' => true]], 'motivo' => 'Cliente desistió', 'metodo' => 'efectivo',
        'efectivo' => null, 'electronico' => null, 'externo' => true, 'clave' => (string) Str::uuid(),
    ]);

    // Primero al lote que vence más tarde de los que salieron en esa venta (B, no C), hasta lo que sacó de él.
    $devolver('3');
    expect(saldoLote($this->matriz, loteDe($this->amoxi, 'B')))->toBe('2.000')
        ->and(saldoLote($this->matriz, loteDe($this->amoxi, 'A')))->toBe('1.000')
        ->and(saldoLote($this->matriz, loteDe($this->amoxi, 'C')))->toBe('5.000');

    // La segunda descuenta lo ya devuelto: a B ya no le queda nada de esta venta, todo va a A.
    $devolver('3');
    expect(saldoLote($this->matriz, loteDe($this->amoxi, 'B')))->toBe('2.000')
        ->and(saldoLote($this->matriz, loteDe($this->amoxi, 'A')))->toBe('4.000')
        ->and(stockDe($this->matriz, $this->amoxi))->toBe('11.000')
        ->and(Lote::query()->where('es_regularizacion', true)->exists())->toBeFalse()
        ->and(lotesError(fn () => $devolver('1')))->toContain('puedes devolver hasta 0');
    lotesInvariante();
});

test('a return to an expired lot re-enters it and stays blocked for sale', function () {
    lotesComprar($this->admin, $this->matriz, [lineaLote($this->amoxi, '3', 'A', '20/01/2027')]);
    $venta = lotesVender($this->admin, $this->matriz, $this->amoxi, '2');
    $item = VentaItem::query()->where('venta_id', $venta->id)->sole();
    $this->travelTo(CarbonImmutable::parse('2027-01-25 10:00', 'America/La_Paz'));

    app(RegistrarDevolucion::class)->ejecutar($this->admin, $venta, [
        'lineas' => [$item->id => ['cantidad' => '2', 'reingresa' => true]], 'motivo' => 'Vencido en casa', 'metodo' => 'efectivo',
        'efectivo' => null, 'electronico' => null, 'externo' => true, 'clave' => (string) Str::uuid(),
    ]);

    expect(saldoLote($this->matriz, loteDe($this->amoxi, 'A')))->toBe('3.000')
        ->and(lotesError(fn () => lotesVender($this->admin, $this->matriz, $this->amoxi, '1')))
        ->toContain('Amoxicilina 500 mg: disponible 0; 3 vencidas');
    lotesInvariante();
});

test('LibroStock accepts a chosen lot, rejects lots of another company and never allocates twice with the same key', function () {
    lotesComprar($this->admin, $this->matriz, [
        lineaLote($this->amoxi, '4', 'A', '10/03/2027'),
        lineaLote($this->amoxi, '3', 'X', '12/2026'),
    ]);
    $libro = app(LibroStock::class);
    $cabecera = fn (string $clave) => ['tipo' => TipoOperacionInventario::Salida, 'motivo' => 'merma', 'sucursal_id' => $this->matriz->id, 'sucursal_destino_id' => null, 'nota' => 'Baja', 'clave' => $clave];
    $cambio = fn (int $loteId, string $cantidad) => ['sucursal_id' => $this->matriz->id, 'producto_id' => $this->amoxi->id, 'cantidad' => $cantidad, 'nombre' => 'Amoxicilina 500 mg', 'lote_id' => $loteId];

    // Lote elegido: sale de ese lote aunque haya otro que vence antes.
    $clave = (string) Str::uuid();
    $libro->registrar($this->admin, $cabecera($clave), [$cambio(loteDe($this->amoxi, 'A')->id, '-1')]);
    $libro->registrar($this->admin, $cabecera($clave), [$cambio(loteDe($this->amoxi, 'A')->id, '-1')]);

    expect(saldoLote($this->matriz, loteDe($this->amoxi, 'A')))->toBe('3.000')
        ->and(saldoLote($this->matriz, loteDe($this->amoxi, 'X')))->toBe('3.000')
        // Dos movimientos de la compra y uno solo de la salida repetida con la misma clave.
        ->and(DB::table('movimientos_stock_lotes')->count())->toBe(3)
        ->and(lotesError(fn () => $libro->registrar($this->admin, $cabecera((string) Str::uuid()), [$cambio(loteDe($this->amoxi, 'X')->id, '-4')])))
        ->toContain('Amoxicilina 500 mg, lote X: disponible 3.');

    // Empresa B con el mismo módulo: no puede mover ni ingresar en un lote de A cambiando el lote_id.
    $ajeno = loteDe($this->amoxi, 'A')->id;
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $sucursalB = Sucursal::factory()->create(['empresa_id' => $empresaB->id]);
    $productoB = productoDe($empresaB, ['nombre' => 'Ibuprofeno', 'controla_vencimiento' => true]);
    app(CambiarFuncionesEmpresa::class)->ejecutar($adminB, $empresaB, Rubro::Farmacia, [Capacidad::LotesVencimientos]);
    $cabeceraB = fn (TipoOperacionInventario $tipo) => ['tipo' => $tipo, 'motivo' => null, 'sucursal_id' => $sucursalB->id, 'sucursal_destino_id' => null, 'nota' => 'B', 'clave' => (string) Str::uuid()];
    $cambioB = fn (string $cantidad) => ['sucursal_id' => $sucursalB->id, 'producto_id' => $productoB->id, 'cantidad' => $cantidad, 'nombre' => 'Ibuprofeno', 'lote_id' => $ajeno];

    expect(lotesError(fn () => $libro->registrar($adminB, $cabeceraB(TipoOperacionInventario::Entrada), [$cambioB('5')])))->toContain('Ibuprofeno: el lote indicado no existe.')
        ->and(lotesError(fn () => $libro->registrar($adminB, $cabeceraB(TipoOperacionInventario::Salida), [$cambioB('-1')])))->toContain('No hay stock suficiente');

    expect(DB::table('stock_lotes')->where('empresa_id', $empresaB->id)->count())->toBe(0)
        ->and(saldoLote($this->matriz, Lote::query()->withoutGlobalScopes()->findOrFail($ajeno)))->toBe('3.000');
    $this->actingAs($this->admin);
    lotesInvariante();
});

test('an operation is rejected when the lot balances do not add up to the total stock', function () {
    lotesComprar($this->admin, $this->matriz, [lineaLote($this->amoxi, '5', 'A', '10/03/2027')]);
    DB::table('stock_lotes')->where('lote_id', loteDe($this->amoxi, 'A')->id)->update(['cantidad' => '4']);
    $operaciones = OperacionInventario::query()->count();

    expect(lotesError(fn () => lotesVender($this->admin, $this->matriz, $this->amoxi, '1')))
        ->toContain('El stock por lote de Amoxicilina 500 mg no coincide con su stock total en la sucursal (5 frente a 4).')
        ->and(OperacionInventario::query()->count())->toBe($operaciones)
        ->and(stockDe($this->matriz, $this->amoxi))->toBe('5.000');
});

test('without the capability, or with unmarked products, nothing touches the lot tables', function () {
    $consultasDeLotes = function (callable $accion): array {
        $consultas = [];
        DB::listen(function ($query) use (&$consultas): void {
            if (preg_match('/\b(lotes|stock_lotes|movimientos_stock_lotes)\b/', $query->sql) === 1) {
                $consultas[] = $query->sql;
            }
        });
        $accion();

        return $consultas;
    };

    // Empresa con la capacidad: un producto sin marcar no consulta tablas de lotes.
    expect($consultasDeLotes(function () {
        lotesComprar($this->admin, $this->matriz, [['producto_id' => $this->alcohol->id, 'cantidad' => '6', 'costo' => '5']]);
        lotesVender($this->admin, $this->matriz, $this->alcohol, '1');
        lotesAjustar($this->admin, 'salida', $this->matriz, [$this->alcohol->id => '1']);
    }))->toBe([]);

    // Empresa sin la capacidad, aunque el producto haya quedado marcado: funciona como hoy.
    [$empresa, $admin] = empresaConAdministrador();
    $this->actingAs($admin);
    $matriz = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $norte = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $producto = productoDe($empresa, ['nombre' => 'Jarabe', 'controla_vencimiento' => true]);
    $proveedor = new Proveedor(['nombre' => 'Otra droguería']);
    $proveedor->empresa_id = $empresa->id;
    $proveedor->save();

    expect($consultasDeLotes(function () use ($admin, $matriz, $norte, $producto, $proveedor) {
        lotesComprar($admin, $matriz, [['producto_id' => $producto->id, 'cantidad' => '6', 'costo' => '5']], ['proveedor_id' => $proveedor->id]);
        lotesVender($admin, $matriz, $producto, '1');
        lotesAjustar($admin, 'entrada', $matriz, [$producto->id => '2']);
        app(RegistrarTraspaso::class)->ejecutar($admin, [
            'sucursal_id' => $matriz->id, 'sucursal_destino_id' => $norte->id, 'nota' => null,
            'lineas' => [$producto->id => '3'], 'clave' => (string) Str::uuid(),
        ]);
    }))->toBe([]);

    expect(stockDe($matriz, $producto))->toBe('4.000')->and(stockDe($norte, $producto))->toBe('3.000');
});

test('a product with expiry control cannot stop controlling stock, in the form or in the action', function () {
    Livewire::test(ProductoFormulario::class, ['producto' => $this->amoxi])
        ->assertSee('Este producto controla lote y vencimiento, así que siempre controla stock.')
        ->set('controla_stock', false)
        ->call('guardar')
        ->assertHasErrors(['controla_stock' => 'Un producto con lote y vencimiento debe controlar stock. Para venderlo sin control de stock, primero desmarca «Controla lote y vencimiento».']);

    $datos = fn (Producto $p, bool $stock) => [
        'nombre' => $p->nombre, 'tipo_venta' => 'unidad', 'unidad_medida_id' => $p->unidad_medida_id, 'precio_consumidor' => '13', 'stock_minimo' => '0',
        'controla_stock' => $stock, 'controla_vencimiento' => true,
    ];

    expect(fn () => app(GuardarProducto::class)->actualizar($this->admin, $this->amoxi->refresh(), $datos($this->amoxi, false)))
        ->toThrow(ValidationException::class, 'debe controlar stock');

    // L3: con la capacidad, la casilla marca productos existentes y nuevos.
    app(GuardarProducto::class)->actualizar($this->admin, $this->alcohol->refresh(), $datos($this->alcohol, true));
    $nuevo = app(GuardarProducto::class)->crear($this->admin, ['nombre' => 'Paracetamol'] + $datos($this->alcohol, true));

    expect($this->amoxi->refresh()->controla_stock)->toBeTrue()
        ->and($this->alcohol->refresh()->controla_vencimiento)->toBeTrue()
        ->and($nuevo->refresh()->controla_vencimiento)->toBeTrue();
});

test('the new purchase screen asks lot and expiry only for marked products, adds another lot and warns about expired lots', function () {
    $pantalla = Livewire::test(NuevaCompra::class)
        ->set('proveedor_id', (string) $this->proveedor->id)
        ->call('agregarProducto', $this->alcohol->id)
        ->assertDontSee('N.º de lote')
        ->call('agregarProducto', $this->amoxi->id)
        ->assertSee('N.º de lote');

    $uid = $pantalla->get('lineas')[1]['uid'];
    $pantalla->call('otroLote', $uid)
        ->call('otroLote', $pantalla->get('lineas')[0]['uid']);   // el alcohol no tiene lotes: no agrega nada

    expect(array_column($pantalla->get('lineas'), 'id'))->toBe([$this->alcohol->id, $this->amoxi->id, $this->amoxi->id]);

    $pantalla->set('lineas.0.costo', '12')
        ->set('lineas.1.costo', '2,50')
        ->set('lineas.1.lote', 'A1')
        ->set('lineas.1.vencimiento', '03/2027')
        ->assertSee('Vence el 31/03/2027')
        ->set('lineas.2.costo', '2,50')
        ->call('revisar')
        ->assertHasErrors(['lineas.2.lote', 'lineas.2.vencimiento'])
        ->assertSet('confirmando', false)
        ->set('lineas.2.lote', 'V0')
        ->set('lineas.2.vencimiento', '12/2026')
        ->assertSee('Vencido el 31/12/2026: no se podrá vender.')
        ->call('revisar')
        ->assertSet('confirmando', true)
        ->assertSee('Lotes ya vencidos')
        ->call('confirmar');

    $compra = Compra::query()->sole();
    expect(Lote::query()->orderBy('numero')->pluck('numero')->all())->toBe(['A1', 'V0'])
        ->and(stockDe($this->matriz, $this->amoxi))->toBe('2.000')
        ->and(CompraItem::query()->whereNotNull('lote_id')->count())->toBe(2);
    lotesInvariante();

    Livewire::test(Detalle::class, ['compra' => $compra])
        ->assertSee('Lote A1 · vence 31/03/2027')
        ->assertSee('Lote V0 · vence 31/12/2026');
});

test('removing one lot line keeps the other lines of the same product', function () {
    $pantalla = Livewire::test(NuevaCompra::class)->call('agregarProducto', $this->amoxi->id);
    $pantalla->call('otroLote', $pantalla->get('lineas')[0]['uid'])->set('lineas.1.lote', 'B2');

    $pantalla->call('quitarLinea', $pantalla->get('lineas')[0]['uid']);

    expect($pantalla->get('lineas'))->toHaveCount(1)
        ->and($pantalla->get('lineas')[0]['lote'])->toBe('B2');
});

test('repeating a purchase with lots and the same key registers it once', function () {
    $datos = ['clave' => (string) Str::uuid()];
    $lineas = [lineaLote($this->amoxi, '10', 'A1', '10/03/2027'), lineaLote($this->amoxi, '5', 'B7', '06/2027')];

    $primera = lotesComprar($this->admin, $this->matriz, $lineas, $datos);
    $segunda = lotesComprar($this->admin, $this->matriz, $lineas, $datos);

    expect($segunda->id)->toBe($primera->id)
        ->and(Compra::query()->count())->toBe(1)
        ->and(Lote::query()->count())->toBe(2)
        ->and(DB::table('movimientos_stock_lotes')->count())->toBe(2)
        ->and(stockDe($this->matriz, $this->amoxi))->toBe('15.000');
    lotesInvariante();
});

test('the lot fields read Vencimiento in Spanish and are translated in English', function () {
    Livewire::test(NuevaCompra::class)->call('agregarProducto', $this->amoxi->id)
        ->assertSeeHtml('<span>N.º de lote</span>')
        ->assertSeeHtml('<span>Vencimiento</span>')
        ->assertSee('Otro lote');

    app()->setLocale('en');

    Livewire::test(NuevaCompra::class)->call('agregarProducto', $this->amoxi->id)
        ->assertSeeHtml('<span>Batch no.</span>')
        ->assertSeeHtml('<span>Expiry</span>')
        ->assertSee('Another batch')
        ->assertDontSee('Due date');

    expect(lotesError(fn () => lotesComprar($this->admin, $this->matriz, [lineaLote($this->amoxi, '1', '', '03/2027')])))
        ->toBe('Amoxicilina 500 mg: enter the batch number.');
});

/*
 * Etapas L2 a L4: ajustes con lote, marcar y desmarcar productos, regularización, apagar la capacidad, reporte,
 * trazabilidad y aviso en Inicio.
 */

test('L2 an entry asks one lot per line for marked products, groups the others and saves nothing when a lot is missing', function () {
    $operaciones = OperacionInventario::query()->count();
    $lineas = [
        ['producto_id' => $this->amoxi->id, 'cantidad' => '4', 'lote' => 'N1', 'vencimiento' => '30/09/2027'],
        ['producto_id' => $this->amoxi->id, 'cantidad' => '2', 'lote' => '', 'vencimiento' => ''],
        ['producto_id' => $this->alcohol->id, 'cantidad' => '1'],
        ['producto_id' => $this->alcohol->id, 'cantidad' => '2'],
    ];

    expect(lotesError(fn () => lotesAjustar($this->admin, 'entrada', $this->matriz, $lineas)))
        ->toContain('Amoxicilina 500 mg: escribe el número de lote.')
        ->and(OperacionInventario::query()->count())->toBe($operaciones)
        ->and(Lote::query()->count())->toBe(0)
        ->and(stockDe($this->matriz, $this->alcohol))->toBe('0.000');

    $lineas[1] = ['producto_id' => $this->amoxi->id, 'cantidad' => '2', 'lote' => 'N2', 'vencimiento' => '08/2027'];
    $operacion = lotesAjustar($this->admin, 'entrada', $this->matriz, $lineas);

    expect(saldoLote($this->matriz, loteDe($this->amoxi, 'N1')))->toBe('4.000')
        ->and(saldoLote($this->matriz, loteDe($this->amoxi, 'N2')))->toBe('2.000')
        ->and(loteDe($this->amoxi, 'N2')->vencimiento->format('Y-m-d'))->toBe('2027-08-31')
        ->and(stockDe($this->matriz, $this->amoxi))->toBe('6.000')
        // Un movimiento total por producto; el alcohol (sin lote) se agrupa como siempre.
        ->and(MovimientoStock::query()->where('operacion_id', $operacion->id)->pluck('cantidad', 'producto_id')->all())
        ->toEqual([$this->amoxi->id => '6.000', $this->alcohol->id => '3.000']);
    lotesInvariante();
});

test('L2 an exit for «Vencido» takes expired lots first, and a chosen lot leaves only from that lot', function () {
    lotesComprar($this->admin, $this->matriz, [
        lineaLote($this->amoxi, '4', 'A', '10/03/2027'),
        lineaLote($this->amoxi, '5', 'B', '10/06/2027'),
        lineaLote($this->amoxi, '3', 'X', '12/2026'),
    ]);

    $baja = lotesAjustar($this->admin, 'salida', $this->matriz, [$this->amoxi->id => '3'], ['motivo' => 'vencido']);

    expect($baja->motivo)->toBe('vencido')
        ->and(saldoLote($this->matriz, loteDe($this->amoxi, 'X')))->toBe('0.000')
        ->and(saldoLote($this->matriz, loteDe($this->amoxi, 'A')))->toBe('4.000');

    // Un ajuste que resta con lote elegido sale de ese lote aunque otro venza antes o después.
    lotesAjustar($this->admin, 'ajuste', $this->matriz, [['producto_id' => $this->amoxi->id, 'cantidad' => '1', 'lote_id' => loteDe($this->amoxi, 'B')->id]], ['direccion' => 'restar']);

    expect(saldoLote($this->matriz, loteDe($this->amoxi, 'B')))->toBe('4.000')
        ->and(saldoLote($this->matriz, loteDe($this->amoxi, 'A')))->toBe('4.000')
        ->and(lotesError(fn () => lotesAjustar($this->admin, 'salida', $this->matriz, [['producto_id' => $this->amoxi->id, 'cantidad' => '5', 'lote_id' => loteDe($this->amoxi, 'A')->id]])))
        ->toContain('Amoxicilina 500 mg, lote A: disponible 4.')
        ->and(stockDe($this->matriz, $this->amoxi))->toBe('8.000');
    lotesInvariante();
});

test('L2 the adjustment screen asks lot and expiry on entries and offers automatic or a chosen lot on exits', function () {
    Livewire::test(NuevoAjuste::class)
        ->set('sucursal_id', (string) $this->matriz->id)
        ->set('tipo', 'entrada')->set('motivo', 'reposicion_manual')->set('nota', 'Muestra médica')
        ->call('agregarProducto', $this->amoxi->id)
        ->assertSee('N.º de lote')
        ->call('revisar')
        ->assertHasErrors(['lineas.0.lote', 'lineas.0.vencimiento'])
        ->assertSet('confirmando', false)
        ->set('lineas.0.cantidad', '5')
        ->set('lineas.0.lote', 'N1')->set('lineas.0.vencimiento', '30/09/2027')
        ->call('revisar')
        ->assertSet('confirmando', true)
        ->assertSee('Lote N1 · vence 30/09/2027')
        ->call('confirmar')
        ->assertRedirect(route('inventario.movimientos'));

    expect(saldoLote($this->matriz, loteDe($this->amoxi, 'N1')))->toBe('5.000');

    lotesComprar($this->admin, $this->matriz, [lineaLote($this->amoxi, '2', 'V1', '12/2026')]);
    $vencido = loteDe($this->amoxi, 'V1');

    Livewire::test(NuevoAjuste::class)
        ->set('sucursal_id', (string) $this->matriz->id)
        ->set('tipo', 'salida')->set('motivo', 'vencido')->set('nota', 'Baja de vencidos')
        ->call('agregarProducto', $this->amoxi->id)
        ->assertSee('Automático (vence primero)')
        ->assertSee('Lote V1 · vence 31/12/2026')
        ->assertSee('Incluye 2 vencidas.')
        ->set('lineas.0.lote_id', (string) $vencido->id)
        ->set('lineas.0.cantidad', '2')
        ->call('revisar')
        ->assertSet('confirmando', true)
        ->call('confirmar')
        ->assertHasNoErrors();

    expect(saldoLote($this->matriz, $vencido))->toBe('0.000')
        ->and(saldoLote($this->matriz, loteDe($this->amoxi, 'N1')))->toBe('5.000');
    lotesInvariante();
});

test('L3 marking a product with stock moves it to regularization in each branch; unmarking needs its lots empty', function () {
    lotesAjustar($this->admin, 'entrada', $this->matriz, [$this->alcohol->id => '5']);
    lotesAjustar($this->admin, 'entrada', $this->norte, [$this->alcohol->id => '2']);
    $niveles = fn () => DB::table('stock_niveles')->where('producto_id', $this->alcohol->id)->orderBy('sucursal_id')->pluck('cantidad')->all();
    $antes = $niveles();

    lotesMarcar($this->admin, $this->alcohol, true);
    $reg = loteRegularizacion($this->alcohol);
    $inicio = OperacionInventario::query()->where('motivo', 'inicio_control_lotes')->orderBy('sucursal_id')->get();

    expect($this->alcohol->refresh()->controla_vencimiento)->toBeTrue()
        ->and($niveles())->toBe($antes)
        ->and(saldoLote($this->matriz, $reg))->toBe('5.000')
        ->and(saldoLote($this->norte, $reg))->toBe('2.000')
        // Operaciones solo de lotes, una por sucursal: serie propia y sin movimiento del stock total.
        ->and($inicio->pluck('sucursal_id')->all())->toBe([$this->matriz->id, $this->norte->id])
        ->and($inicio->pluck('serie')->unique()->all())->toBe([TipoOperacionInventario::SERIE_LOTES])
        ->and(MovimientoStock::query()->whereIn('operacion_id', $inicio->pluck('id'))->count())->toBe(0)
        ->and(DB::table('movimientos_stock_lotes')->whereIn('operacion_id', $inicio->pluck('id'))->count())->toBe(2)
        ->and(Auditoria::query()->where('accion', 'producto.actualizado')->where('entidad_id', $this->alcohol->id)->exists())->toBeTrue();
    lotesInvariante();

    // Con stock en lotes reales no se puede desmarcar: el mensaje nombra cada lote.
    lotesComprar($this->admin, $this->matriz, [lineaLote($this->alcohol, '2', 'AL-9', '31/08/2027')]);
    expect(lotesError(fn () => lotesMarcar($this->admin, $this->alcohol, false)))
        ->toContain('lote AL-9 (vence 31/08/2027) en Casa Matriz: 2')
        ->and($this->alcohol->refresh()->controla_vencimiento)->toBeTrue();

    // Dado de baja ese lote, se desmarca: la regularización vuelve a cero con su movimiento y se vende como antes.
    lotesAjustar($this->admin, 'salida', $this->matriz, [['producto_id' => $this->alcohol->id, 'cantidad' => '2', 'lote_id' => loteDe($this->alcohol, 'AL-9')->id]]);
    lotesMarcar($this->admin, $this->alcohol, false);
    $fin = OperacionInventario::query()->where('motivo', 'fin_control_lotes')->pluck('id');

    expect($this->alcohol->refresh()->controla_vencimiento)->toBeFalse()
        ->and($niveles())->toBe($antes)
        ->and((string) DB::table('stock_lotes')->where('producto_id', $this->alcohol->id)->sum('cantidad'))->toBe('0.000')
        ->and($fin)->toHaveCount(2)
        ->and(DB::table('movimientos_stock_lotes')->whereIn('operacion_id', $fin)->count())->toBe(2);

    lotesVender($this->admin, $this->matriz, $this->alcohol, '5');
    expect(stockDe($this->matriz, $this->alcohol))->toBe('0.000');
});

test('L3 the products list marks every product with stock control in one confirmed step, and only an administrator can', function () {
    lotesAjustar($this->admin, 'entrada', $this->matriz, [$this->alcohol->id => '3']);
    $servicio = productoDe($this->empresa, ['nombre' => 'Inyección aplicada', 'controla_stock' => false]);

    Livewire::test(Productos::class)
        ->assertSee('1 producto con control de stock todavía no controla lote y vencimiento.')
        ->set('confirmandoLotes', true)
        ->assertSee('Se marcará 1 producto.')
        ->call('marcarConLote')
        ->assertSet('confirmandoLotes', false)
        ->assertDontSeeHtml('data-test="marcar-lotes"');

    expect($this->alcohol->refresh()->controla_vencimiento)->toBeTrue()
        ->and($servicio->refresh()->controla_vencimiento)->toBeFalse()
        ->and(saldoLote($this->matriz, loteRegularizacion($this->alcohol)))->toBe('3.000')
        ->and(Auditoria::query()->where('accion', 'producto.actualizado')->where('entidad_id', $this->alcohol->id)->sole()->despues)
        ->toBe(['controla_vencimiento' => true, 'masivo' => true])
        ->and(app(MarcarProductosConLote::class)->ejecutar($this->admin, null))->toBe(0);
    lotesInvariante();

    $vendedor = User::factory()->vendedor($this->matriz)->create();
    expect(fn () => app(MarcarProductosConLote::class)->ejecutar($vendedor, null))->toThrow(AuthorizationException::class);
});

test('L3 the product form checks lot control by default with the capability and lists the lots by branch', function () {
    Livewire::test(ProductoFormulario::class)
        ->assertSet('controla_vencimiento', true)
        ->assertSeeHtml('data-test="producto-controla-vencimiento"');

    lotesAjustar($this->admin, 'entrada', $this->matriz, [$this->alcohol->id => '3']);
    Livewire::test(ProductoFormulario::class, ['producto' => $this->alcohol])
        ->assertSet('controla_vencimiento', false)
        ->assertDontSeeHtml('data-test="producto-lotes"')
        ->set('controla_vencimiento', true)
        ->assertSee('Al guardar, el stock actual de cada sucursal pasa a «Sin lote (regularización)»; el total no cambia.');

    lotesComprar($this->admin, $this->norte, [lineaLote($this->amoxi, '4', 'A1', '10/03/2027')]);
    Livewire::test(ProductoFormulario::class, ['producto' => $this->amoxi])
        ->assertSeeHtml('data-test="producto-lotes"')
        ->assertSee('Lotes por sucursal')
        ->assertSee('Lote A1 · vence 10/03/2027')
        ->assertSee('Sucursal Norte');

    // Sin la capacidad: ni casilla ni sección, y la acción ignora el campo.
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::Farmacia, [], null, CambiarFuncionesEmpresa::CONFIRMAR_LOTES);

    Livewire::test(ProductoFormulario::class, ['producto' => $this->amoxi->refresh()])
        ->assertDontSeeHtml('data-test="producto-controla-vencimiento"')
        ->assertDontSeeHtml('data-test="producto-lotes"');
    lotesMarcar($this->admin, $this->alcohol, true);

    expect($this->alcohol->refresh()->controla_vencimiento)->toBeFalse();
});

test('L3 regularizing moves «Sin fecha» stock to real lots with a note, once per key, and never more than there is', function () {
    DB::table('productos')->where('id', $this->amoxi->id)->update(['controla_vencimiento' => false]);
    lotesAjustar($this->admin, 'entrada', $this->matriz, [$this->amoxi->id => '6']);
    lotesMarcar($this->admin, $this->amoxi, true);
    $reg = loteRegularizacion($this->amoxi);

    $datos = [
        'sucursal_id' => $this->matriz->id, 'producto_id' => $this->amoxi->id, 'nota' => 'Caja encontrada', 'clave' => (string) Str::uuid(),
        'lineas' => [['lote' => 'R9', 'vencimiento' => '09/2027', 'cantidad' => '1']],
    ];
    $primera = app(RegularizarLote::class)->ejecutar($this->admin, $datos);
    $segunda = app(RegularizarLote::class)->ejecutar($this->admin, $datos);

    expect($segunda->id)->toBe($primera->id)
        ->and(saldoLote($this->matriz, loteDe($this->amoxi, 'R9')))->toBe('1.000')
        ->and(saldoLote($this->matriz, $reg))->toBe('5.000');

    $pantalla = Livewire::test(Vencimientos::class)
        ->set('estado', 'sin_fecha')
        ->assertSee('Sin fecha · por regularizar')
        ->call('regularizar', $this->matriz->id, $this->amoxi->id)
        ->assertSet('regLineas.0.cantidad', '5')
        ->set('regLineas.0.lote', 'R1')->set('regLineas.0.vencimiento', '30/06/2027')->set('regLineas.0.cantidad', '3')
        ->call('otroLote')
        ->set('regLineas.1.lote', 'R0')->set('regLineas.1.vencimiento', '12/2026')->set('regLineas.1.cantidad', '3')
        ->assertSee('R0 · 31/12/2026')
        ->call('confirmarRegularizacion')
        ->assertHasErrors(['regNota'])
        ->set('regNota', 'Conteo físico de cajas')
        ->call('confirmarRegularizacion')
        ->assertHasErrors(['lineas']);

    expect(OperacionInventario::query()->where('motivo', 'regularizacion_lote')->count())->toBe(1)
        ->and(saldoLote($this->matriz, $reg))->toBe('5.000');

    $pantalla->set('regLineas.1.cantidad', '2')
        ->call('confirmarRegularizacion')
        ->assertHasNoErrors()
        ->assertSet('regProductoId', null);

    $operacion = OperacionInventario::query()->where('motivo', 'regularizacion_lote')->latest('id')->firstOrFail();
    expect(saldoLote($this->matriz, loteDe($this->amoxi, 'R1')))->toBe('3.000')
        ->and(saldoLote($this->matriz, loteDe($this->amoxi, 'R0')))->toBe('2.000')
        ->and(saldoLote($this->matriz, $reg))->toBe('0.000')
        ->and(stockDe($this->matriz, $this->amoxi))->toBe('6.000')
        ->and($operacion->serie)->toBe(TipoOperacionInventario::SERIE_LOTES)
        ->and($operacion->nota)->toBe('Conteo físico de cajas')
        ->and(MovimientoStock::query()->where('operacion_id', $operacion->id)->count())->toBe(0);
    lotesInvariante();

    // Un producto sin marcar no se regulariza.
    expect(lotesError(fn () => app(RegularizarLote::class)->ejecutar($this->admin, ['producto_id' => $this->alcohol->id, 'clave' => (string) Str::uuid()] + $datos)))
        ->toContain('El producto no controla lote y vencimiento.');
});

test('L4 the expiry report filters by state and branch, exports the same rows and stays inside the company', function () {
    guiaTerminada($this->empresa);
    lotesComprar($this->admin, $this->matriz, [
        lineaLote($this->amoxi, '2', 'V', '12/2026'),        // vencido
        lineaLote($this->amoxi, '3', 'D20', '04/02/2027'),   // vence en 20 días
        lineaLote($this->amoxi, '4', 'D45', '01/03/2027'),   // en 45 días
        lineaLote($this->amoxi, '5', 'D80', '05/04/2027'),   // en 80 días
        lineaLote($this->amoxi, '6', 'L', '31/12/2027'),     // vigente
    ]);
    lotesComprar($this->admin, $this->norte, [lineaLote($this->amoxi, '1', 'D20', '04/02/2027')]);
    $lotes = fn (string $estado, string $sucursal = '') => Livewire::test(Vencimientos::class)->set('estado', $estado)->set('sucursal', $sucursal)
        ->viewData('lotes')->getCollection()->map(fn ($fila) => $fila->numero.'@'.$fila->sucursal)->all();

    expect($lotes('todos'))->toBe(['V@Casa Matriz', 'D20@Casa Matriz', 'D20@Sucursal Norte', 'D45@Casa Matriz', 'D80@Casa Matriz', 'L@Casa Matriz'])
        ->and($lotes('vencidos'))->toBe(['V@Casa Matriz'])
        ->and($lotes('30'))->toBe(['D20@Casa Matriz', 'D20@Sucursal Norte'])
        ->and($lotes('60'))->toBe(['D20@Casa Matriz', 'D20@Sucursal Norte', 'D45@Casa Matriz'])
        ->and($lotes('90'))->toBe(['D20@Casa Matriz', 'D20@Sucursal Norte', 'D45@Casa Matriz', 'D80@Casa Matriz'])
        ->and($lotes('30', (string) $this->norte->id))->toBe(['D20@Sucursal Norte'])
        ->and($lotes('sin_fecha'))->toBe([]);

    Livewire::test(Vencimientos::class)
        ->assertSee('Vencido')
        ->assertSee('Vence en 20 días')
        ->assertSeeHtml('data-test="resumen-vencidos"');

    $csv = $this->get(route('inventario.vencimientos.exportar', ['estado' => 'vencidos']))->assertOk()->streamedContent();
    expect($csv)->toContain('Producto')->toContain('Vencimiento')->toContain('Amoxicilina 500 mg')->toContain('31/12/2026')->not->toContain('D20');

    $this->get(route('inventario.vencimientos'))->assertOk()->assertSee('Amoxicilina 500 mg');
    $loteId = loteDe($this->amoxi, 'D20')->id;
    $this->get(route('inventario.lote', $loteId))->assertOk()->assertSee('Lote D20 · vence 04/02/2027');

    // El vendedor no ve el reporte ni la trazabilidad (misma Policy que el historial).
    $vendedor = User::factory()->vendedor($this->matriz)->create();
    $this->actingAs($vendedor);
    $this->get(route('inventario.vencimientos'))->assertForbidden();
    $this->get(route('inventario.vencimientos.exportar'))->assertForbidden();
    $this->get(route('inventario.lote', $loteId))->assertForbidden();

    // Empresa B con el mismo módulo: no ve ni exporta los lotes de A, ni abre su trazabilidad, ni la regulariza.
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    guiaTerminada($empresaB);
    $sucursalB = Sucursal::factory()->create(['empresa_id' => $empresaB->id]);
    app(CambiarFuncionesEmpresa::class)->ejecutar($adminB, $empresaB, Rubro::Farmacia, [Capacidad::LotesVencimientos]);

    expect(Livewire::test(Vencimientos::class)->viewData('lotes')->total())->toBe(0)
        ->and($this->get(route('inventario.vencimientos.exportar'))->assertOk()->streamedContent())->not->toContain('Amoxicilina');
    $this->get(route('inventario.lote', $loteId))->assertNotFound();
    expect(fn () => app(RegularizarLote::class)->ejecutar($adminB, [
        'sucursal_id' => $sucursalB->id, 'producto_id' => $this->amoxi->id, 'nota' => 'Intento', 'clave' => (string) Str::uuid(),
        'lineas' => [['lote' => 'Q1', 'vencimiento' => '09/2027', 'cantidad' => '1']],
    ]))->toThrow(ValidationException::class);
    expect(DB::table('lotes')->where('empresa_id', $empresaB->id)->count())->toBe(0);

    // Sin la capacidad las rutas no existen.
    [$empresaC, $adminC] = empresaConAdministrador();
    $this->actingAs($adminC);
    guiaTerminada($empresaC);
    $this->get(route('inventario.vencimientos'))->assertNotFound();
    $this->get(route('inventario.vencimientos.exportar'))->assertNotFound();
});

test('L4 lot traceability shows each document with its customer or supplier, transfers, exits and stock by branch', function () {
    $cliente = new Cliente(['nombre' => 'María Rojas']);
    $cliente->save();
    lotesComprar($this->admin, $this->matriz, [lineaLote($this->amoxi, '10', 'T1', '10/06/2027')]);
    $venta = confirmarVenta($this->admin, crearPedido($this->admin, $this->matriz, [['producto_id' => $this->amoxi->id, 'cantidad' => '3']], ['cliente_id' => $cliente->id]), ['metodo' => 'efectivo']);
    $item = VentaItem::query()->where('venta_id', $venta->id)->sole();
    app(RegistrarDevolucion::class)->ejecutar($this->admin, $venta, [
        'lineas' => [$item->id => ['cantidad' => '1', 'reingresa' => true]], 'motivo' => 'Cambio de receta', 'metodo' => 'efectivo',
        'efectivo' => null, 'electronico' => null, 'externo' => true, 'clave' => (string) Str::uuid(),
    ]);
    app(RegistrarTraspaso::class)->ejecutar($this->admin, [
        'sucursal_id' => $this->matriz->id, 'sucursal_destino_id' => $this->norte->id, 'nota' => null,
        'lineas' => [$this->amoxi->id => '2'], 'clave' => (string) Str::uuid(),
    ]);
    lotesAjustar($this->admin, 'salida', $this->matriz, [$this->amoxi->id => '1']);
    $lote = loteDe($this->amoxi, 'T1');

    $pantalla = Livewire::test(TrazabilidadLote::class, ['lote' => $lote])
        ->assertSee('Lote T1 · vence 10/06/2027')
        ->assertSee('Droguería Central')
        ->assertSee('María Rojas')
        ->assertSee($venta->codigo())
        ->assertSee('Traspaso a Sucursal Norte')
        ->assertSee('Merma')
        ->assertSee('Casa Matriz · 5')
        ->assertSee('Sucursal Norte · 2');

    // Compra, venta, devolución, traspaso (salida y entrada) y la merma.
    expect($pantalla->viewData('movimientos')->total())->toBe(6);
});

test('con «Lotes y vencimientos» apagada, un producto marcado se puede pasar a «sin control de stock» y pierde la marca', function () {
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::Farmacia, [], null, 'DESACTIVAR');
    expect($this->amoxi->refresh()->controla_vencimiento)->toBeTrue();

    Livewire::test(ProductoFormulario::class, ['producto' => $this->amoxi])
        ->assertDontSee('Este producto controla lote y vencimiento, así que siempre controla stock.')
        ->set('controla_stock', false)
        ->call('guardar')
        ->assertHasNoErrors();

    $this->amoxi->refresh();
    expect($this->amoxi->controla_stock)->toBeFalse()
        ->and($this->amoxi->controla_vencimiento)->toBeFalse();
});

test('L3 turning the capability off asks to type DESACTIVAR, keeps total stock and marks and empties the lots; turning it on regularizes', function () {
    lotesComprar($this->admin, $this->matriz, [
        lineaLote($this->amoxi, '4', 'A', '10/03/2027'),
        lineaLote($this->amoxi, '2', 'X', '12/2026'),
    ]);
    $niveles = fn () => DB::table('stock_niveles')->orderBy('sucursal_id')->orderBy('producto_id')->get(['sucursal_id', 'producto_id', 'cantidad'])->map(fn ($n) => (array) $n)->all();
    $antes = $niveles();
    $apagar = fn (?string $palabra) => app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::Farmacia, [], null, $palabra);

    expect(lotesError(fn () => $apagar(null)))
        ->toContain('Se dejará de controlar el lote de 1 producto.')
        ->toContain('Escribe DESACTIVAR para continuar.')
        ->and(lotesError(fn () => $apagar('desactivo')))->toContain('Se dejará de controlar el lote de 1 producto.')
        ->and($this->empresa->fresh()->tieneCapacidad(Capacidad::LotesVencimientos))->toBeTrue()
        ->and(saldoLote($this->matriz, loteDe($this->amoxi, 'A')))->toBe('4.000');

    expect($apagar(' desactivar '))->toBeTrue();
    $fin = OperacionInventario::query()->where('motivo', 'fin_control_lotes')->sole();

    expect($this->empresa->fresh()->tieneCapacidad(Capacidad::LotesVencimientos))->toBeFalse()
        ->and($niveles())->toBe($antes)
        ->and((string) DB::table('stock_lotes')->sum('cantidad'))->toBe('0.000')
        ->and(DB::table('movimientos_stock_lotes')->where('operacion_id', $fin->id)->count())->toBe(2)
        ->and($this->amoxi->refresh()->controla_vencimiento)->toBeTrue()
        ->and(Auditoria::query()->where('accion', 'empresa.funciones_cambiadas')->latest('id')->first()->despues['lotes_en_cero'])->toBe(2);

    // Sin el módulo se vende como antes, también lo que estaba vencido.
    lotesVender($this->admin, $this->matriz, $this->amoxi, '6');
    expect(stockDe($this->matriz, $this->amoxi))->toBe('0.000');

    // Al volver a activarlo, el stock de los productos marcados pasa al lote de regularización.
    lotesAjustar($this->admin, 'entrada', $this->matriz, [$this->amoxi->id => '3']);
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::Farmacia, [Capacidad::LotesVencimientos]);

    expect(saldoLote($this->matriz, loteRegularizacion($this->amoxi)))->toBe('3.000')
        ->and(saldoLote($this->matriz, loteDe($this->amoxi, 'A')))->toBe('0.000')
        ->and(Auditoria::query()->where('accion', 'empresa.funciones_cambiadas')->latest('id')->first()->despues['regularizado'])->toBe('3.000');
    lotesInvariante();
});

test('L3 the functions screen keeps the confirmation open until DESACTIVAR is typed', function () {
    lotesComprar($this->admin, $this->matriz, [lineaLote($this->amoxi, '4', 'A', '10/03/2027')]);

    Livewire::test(FuncionesNegocio::class)
        ->set('capacidades', [])
        ->call('revisar')
        ->assertSet('revisando', true)
        ->assertSee('Se dejará de controlar el lote de 1 producto.')
        ->assertSeeHtml('data-test="funciones-palabra-lotes"')
        ->set('confirmacionLotes', 'si')
        ->call('guardar')
        ->assertHasErrors(['confirmacionLotes'])
        ->assertSet('revisando', true)
        ->set('confirmacionLotes', 'DESACTIVAR')
        ->call('guardar')
        ->assertHasNoErrors()
        ->assertSet('revisando', false);

    expect($this->empresa->fresh()->tieneCapacidad(Capacidad::LotesVencimientos))->toBeFalse()
        ->and(saldoLote($this->matriz, loteDe($this->amoxi, 'A')))->toBe('0.000')
        ->and(stockDe($this->matriz, $this->amoxi))->toBe('4.000');
});

test('L4 Inicio warns about expired and soon-to-expire lots, and the menu shows Vencimientos only with the capability', function () {
    guiaTerminada($this->empresa);
    $menu = 'href="'.route('inventario.vencimientos').'"';

    $this->get(route('dashboard'))->assertOk()->assertSeeHtml($menu)->assertDontSeeHtml('data-test="aviso-vencimientos"');

    lotesComprar($this->admin, $this->matriz, [
        lineaLote($this->amoxi, '2', 'X', '12/2026'),
        lineaLote($this->amoxi, '3', 'D20', '04/02/2027'),
    ]);
    $this->get(route('dashboard'))
        ->assertSeeHtml('data-test="aviso-vencimientos"')
        ->assertSee('1 lote vencido con stock')
        ->assertSee('Ver vencimientos');

    lotesAjustar($this->admin, 'salida', $this->matriz, [$this->amoxi->id => '2'], ['motivo' => 'vencido']);
    $this->get(route('dashboard'))
        ->assertSee('1 lote vence en los próximos 30 días')
        ->assertDontSee('lote vencido con stock');

    // El vendedor no recibe el aviso ni el enlace.
    $this->actingAs(User::factory()->vendedor($this->matriz)->create());
    $this->get(route('dashboard'))->assertOk()->assertDontSeeHtml($menu)->assertDontSeeHtml('data-test="aviso-vencimientos"');

    // Sin la capacidad no hay enlace en el menú.
    [$empresaC, $adminC] = empresaConAdministrador();
    $this->actingAs($adminC);
    guiaTerminada($empresaC);
    Sucursal::factory()->create(['empresa_id' => $empresaC->id]);
    $this->get(route('dashboard'))->assertOk()->assertDontSeeHtml($menu);
});

test('L2 the transfer screen shows only sellable stock and says how many expired units stay behind', function () {
    lotesComprar($this->admin, $this->matriz, [
        lineaLote($this->amoxi, '4', 'A', '10/03/2027'),
        lineaLote($this->amoxi, '3', 'X', '12/2026'),
    ]);

    Livewire::test(NuevoTraspaso::class)
        ->set('sucursal_id', (string) $this->matriz->id)
        ->set('sucursal_destino_id', (string) $this->norte->id)
        ->call('agregarProducto', $this->amoxi->id)
        ->assertSee('3 vencidas no se traspasan.');
});
