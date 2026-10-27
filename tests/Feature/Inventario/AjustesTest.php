<?php

use App\Domain\Inventario\Actions\RegistrarAjuste;
use App\Livewire\Inventario\NuevoAjuste;
use App\Models\MovimientoStock;
use App\Models\OperacionInventario;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Reglas V3 § 3 y prueba obligatoria n.º 4: entrada, salida y ajuste con dirección,
 * nota obligatoria, sin stock negativo, aislamiento e idempotencia.
 */

function ajuste(User $admin, array $datos): OperacionInventario
{
    return app(RegistrarAjuste::class)->ejecutar($admin, $datos + ['clave' => (string) Str::uuid(), 'nota' => 'Nota de prueba']);
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->sucursal = Sucursal::factory()->create(['empresa_id' => $this->empresa->id]);
    $this->producto = productoDe($this->empresa, ['nombre' => 'Arroz 5kg']);
    $this->actingAs($this->admin);
});

test('V3-4 an entry of 2 increases stock by 2 with before and after balances', function () {
    $operacion = ajuste($this->admin, ['tipo' => 'entrada', 'motivo' => 'reposicion_manual', 'sucursal_id' => $this->sucursal->id, 'lineas' => [$this->producto->id => '2']]);

    expect(stockDe($this->sucursal, $this->producto))->toBe('2.000')
        ->and($operacion->codigo())->toBe('AJU-00001')
        ->and($operacion->usuario_id)->toBe($this->admin->id);

    $movimiento = MovimientoStock::query()->sole();
    expect($movimiento->cantidad)->toBe('2.000')
        ->and($movimiento->saldo_anterior)->toBe('0.000')
        ->and($movimiento->saldo_posterior)->toBe('2.000')
        ->and($movimiento->sucursal_id)->toBe($this->sucursal->id);
});

test('V3-4 an exit of 2 with stock 1 is rejected and nothing changes', function () {
    cargarStock($this->admin, $this->sucursal, $this->producto, '1');

    expect(fn () => ajuste($this->admin, ['tipo' => 'salida', 'motivo' => 'merma', 'sucursal_id' => $this->sucursal->id, 'lineas' => [$this->producto->id => '2']]))
        ->toThrow(ValidationException::class);

    expect(stockDe($this->sucursal, $this->producto))->toBe('1.000')
        ->and(OperacionInventario::query()->count())->toBe(1)
        ->and(MovimientoStock::query()->count())->toBe(1);
});

test('V3-4 adjustment direction applies the sign', function () {
    cargarStock($this->admin, $this->sucursal, $this->producto, '10');

    ajuste($this->admin, ['tipo' => 'ajuste', 'direccion' => 'restar', 'sucursal_id' => $this->sucursal->id, 'lineas' => [$this->producto->id => '3']]);
    expect(stockDe($this->sucursal, $this->producto))->toBe('7.000');

    ajuste($this->admin, ['tipo' => 'ajuste', 'direccion' => 'sumar', 'sucursal_id' => $this->sucursal->id, 'lineas' => [$this->producto->id => '5']]);
    expect(stockDe($this->sucursal, $this->producto))->toBe('12.000');

    expect(fn () => ajuste($this->admin, ['tipo' => 'ajuste', 'direccion' => 'otra', 'sucursal_id' => $this->sucursal->id, 'lineas' => [$this->producto->id => '1']]))
        ->toThrow(ValidationException::class);
});

test('V3-4 note, contextual reason and positive quantities are mandatory', function (array $datos) {
    expect(fn () => ajuste($this->admin, $datos + ['sucursal_id' => $this->sucursal->id]))
        ->toThrow(ValidationException::class);
    expect(OperacionInventario::query()->count())->toBe(0);
})->with([
    'sin nota' => fn () => ['tipo' => 'entrada', 'motivo' => 'devolucion', 'nota' => '   ', 'lineas' => [$this->producto->id => '1']],
    'motivo de salida en entrada' => fn () => ['tipo' => 'entrada', 'motivo' => 'merma', 'lineas' => [$this->producto->id => '1']],
    'sin motivo' => fn () => ['tipo' => 'salida', 'lineas' => [$this->producto->id => '1']],
    'cantidad cero' => fn () => ['tipo' => 'entrada', 'motivo' => 'devolucion', 'lineas' => [$this->producto->id => '0']],
    'cantidad negativa' => fn () => ['tipo' => 'entrada', 'motivo' => 'devolucion', 'lineas' => [$this->producto->id => '-3']],
    'decimales en unidad entera' => fn () => ['tipo' => 'entrada', 'motivo' => 'devolucion', 'lineas' => [$this->producto->id => '1.5']],
    'sin productos' => fn () => ['tipo' => 'entrada', 'motivo' => 'devolucion', 'lineas' => []],
    'tipo traspaso' => fn () => ['tipo' => 'traspaso', 'lineas' => [$this->producto->id => '1']],
]);

test('units that admit decimals accept up to three decimals with comma', function () {
    $kilo = new UnidadMedida(['nombre' => 'Kilo prueba', 'abreviatura' => 'kg', 'admite_decimales' => true]);
    $kilo->empresa_id = $this->empresa->id;
    $kilo->save();
    $azucar = productoDe($this->empresa, ['unidad_medida_id' => $kilo->id]);

    ajuste($this->admin, ['tipo' => 'entrada', 'motivo' => 'reposicion_manual', 'sucursal_id' => $this->sucursal->id, 'lineas' => [$azucar->id => '2,125']]);
    ajuste($this->admin, ['tipo' => 'salida', 'motivo' => 'merma', 'sucursal_id' => $this->sucursal->id, 'lineas' => [$azucar->id => '0.125']]);

    expect(stockDe($this->sucursal, $azucar))->toBe('2.000');
});

test('V3-4 tenant isolation: branch or product of another company is rejected', function () {
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $sucursalB = Sucursal::factory()->create(['empresa_id' => $empresaB->id]);
    $productoB = productoDe($empresaB);
    $this->actingAs($this->admin);

    expect(fn () => ajuste($this->admin, ['tipo' => 'entrada', 'motivo' => 'devolucion', 'sucursal_id' => $sucursalB->id, 'lineas' => [$this->producto->id => '1']]))
        ->toThrow(ValidationException::class);
    expect(fn () => ajuste($this->admin, ['tipo' => 'entrada', 'motivo' => 'devolucion', 'sucursal_id' => $this->sucursal->id, 'lineas' => [$productoB->id => '1']]))
        ->toThrow(ValidationException::class);

    expect(DB::table('stock_niveles')->count())->toBe(0)
        ->and(DB::table('operaciones_inventario')->count())->toBe(0);
});

test('T19 repeating the same confirmation does not apply it twice', function () {
    $datos = ['tipo' => 'entrada', 'motivo' => 'reposicion_manual', 'sucursal_id' => $this->sucursal->id,
        'nota' => 'Doble clic', 'lineas' => [$this->producto->id => '5'], 'clave' => (string) Str::uuid()];

    $primera = app(RegistrarAjuste::class)->ejecutar($this->admin, $datos);
    $segunda = app(RegistrarAjuste::class)->ejecutar($this->admin, $datos);

    expect($segunda->id)->toBe($primera->id)
        ->and(stockDe($this->sucursal, $this->producto))->toBe('5.000')
        ->and(MovimientoStock::query()->count())->toBe(1);
});

test('only administrators can register adjustments (owner decision)', function () {
    $vendedor = User::factory()->vendedor($this->sucursal)->create();
    $this->actingAs($vendedor);

    expect(fn () => ajuste($vendedor, ['tipo' => 'entrada', 'motivo' => 'devolucion', 'sucursal_id' => $this->sucursal->id, 'lineas' => [$this->producto->id => '1']]))
        ->toThrow(AuthorizationException::class);

    $this->get(route('inventario.ajuste'))->assertForbidden();
    $this->get(route('inventario.movimientos'))->assertForbidden();
});

test('the database itself refuses negative stock and edited history', function () {
    cargarStock($this->admin, $this->sucursal, $this->producto, '1');

    expect(fn () => DB::table('stock_niveles')->update(['cantidad' => -1]))->toThrow(QueryException::class)
        ->and(fn () => MovimientoStock::query()->first()->delete())->toThrow(LogicException::class)
        ->and(fn () => OperacionInventario::query()->first()->forceFill(['nota' => 'cambiada'])->save())->toThrow(LogicException::class);
});

test('the adjustment screen captures by code, reviews and confirms once', function () {
    $this->producto->forceFill(['codigo_barras' => '7790001112223'])->save();
    cargarStock($this->admin, $this->sucursal, $this->producto, '4');

    $pantalla = Livewire::test(NuevoAjuste::class)
        ->set('tipo', 'salida')
        ->set('motivo', 'producto_danado')
        ->set('sucursal_id', (string) $this->sucursal->id)
        ->set('nota', 'Bolsas rotas')
        ->call('agregarDesdeBuscador', '7790001112223')
        ->call('agregarDesdeBuscador', '7790001112223')
        ->assertSet('lineas', [['id' => $this->producto->id, 'cantidad' => '2']])
        ->assertSee('Stock después')
        ->call('revisar')
        ->assertSet('confirmando', true)
        ->call('confirmar')
        ->assertRedirect(route('inventario.movimientos'));

    expect(stockDe($this->sucursal, $this->producto))->toBe('2.000');

    // Reenviar desde la misma pantalla (misma clave) no vuelve a descontar.
    $pantalla->call('confirmar');
    expect(stockDe($this->sucursal, $this->producto))->toBe('2.000')
        ->and(OperacionInventario::query()->where('tipo', 'salida')->count())->toBe(1);
});

test('the adjustment screen shows server errors when stock is insufficient', function () {
    Livewire::test(NuevoAjuste::class)
        ->set('tipo', 'salida')->set('motivo', 'merma')
        ->set('sucursal_id', (string) $this->sucursal->id)->set('nota', 'Prueba')
        ->call('agregarProducto', $this->producto->id)
        ->call('revisar')->call('confirmar')
        ->assertHasErrors('lineas')
        ->assertSee('No hay stock suficiente');

    expect(OperacionInventario::query()->count())->toBe(0);
});
