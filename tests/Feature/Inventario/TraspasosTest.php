<?php

use App\Domain\Inventario\Actions\RegistrarTraspaso;
use App\Livewire\Inventario\NuevoTraspaso;
use App\Models\MovimientoStock;
use App\Models\OperacionInventario;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * T08 y prueba V3 n.º 5: traspaso atómico, trazable, que conserva la suma global y no
 * admite origen = destino, falta de stock ni doble ejecución.
 */

function traspaso(User $admin, array $datos): OperacionInventario
{
    return app(RegistrarTraspaso::class)->ejecutar($admin, $datos + ['clave' => (string) Str::uuid(), 'nota' => null]);
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->origen = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->destino = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sucursal Norte']);
    $this->arroz = productoDe($this->empresa, ['nombre' => 'Arroz 5kg']);
    $this->aceite = productoDe($this->empresa, ['nombre' => 'Aceite 1L']);
    $this->actingAs($this->admin);
    cargarStock($this->admin, $this->origen, $this->arroz, '20');
    cargarStock($this->admin, $this->origen, $this->aceite, '3');
});

test('T08 transferring 8 units subtracts 8 at origin and adds 8 at destination with paired history', function () {
    $operacion = traspaso($this->admin, ['sucursal_id' => $this->origen->id, 'sucursal_destino_id' => $this->destino->id,
        'nota' => 'Reposición de sucursal', 'lineas' => [$this->arroz->id => '8']]);

    expect(stockDe($this->origen, $this->arroz))->toBe('12.000')
        ->and(stockDe($this->destino, $this->arroz))->toBe('8.000')
        ->and($operacion->codigo())->toBe('TRA-00001')
        ->and($operacion->sucursal_destino_id)->toBe($this->destino->id);

    $lineas = MovimientoStock::query()->where('operacion_id', $operacion->id)->orderBy('cantidad')->get();
    expect($lineas)->toHaveCount(2)
        ->and([$lineas[0]->sucursal_id, $lineas[0]->cantidad, $lineas[0]->saldo_anterior, $lineas[0]->saldo_posterior])
        ->toBe([$this->origen->id, '-8.000', '20.000', '12.000'])
        ->and([$lineas[1]->sucursal_id, $lineas[1]->cantidad, $lineas[1]->saldo_anterior, $lineas[1]->saldo_posterior])
        ->toBe([$this->destino->id, '8.000', '0.000', '8.000']);
});

test('V3-5 a transfer keeps the global stock sum', function () {
    $total = fn () => (string) DB::table('stock_niveles')->where('producto_id', $this->arroz->id)->sum('cantidad');
    $antes = $total();

    traspaso($this->admin, ['sucursal_id' => $this->origen->id, 'sucursal_destino_id' => $this->destino->id, 'lineas' => [$this->arroz->id => '5']]);
    traspaso($this->admin, ['sucursal_id' => $this->destino->id, 'sucursal_destino_id' => $this->origen->id, 'lineas' => [$this->arroz->id => '2']]);

    expect(Decimal::comparar($total(), $antes))->toBe(0);
});

test('T08 if one line lacks stock nothing changes on either side', function () {
    expect(fn () => traspaso($this->admin, ['sucursal_id' => $this->origen->id, 'sucursal_destino_id' => $this->destino->id,
        'lineas' => [$this->arroz->id => '8', $this->aceite->id => '4']]))
        ->toThrow(ValidationException::class);

    expect(stockDe($this->origen, $this->arroz))->toBe('20.000')
        ->and(stockDe($this->destino, $this->arroz))->toBe('0.000')
        ->and(stockDe($this->origen, $this->aceite))->toBe('3.000')
        ->and(OperacionInventario::query()->where('tipo', 'traspaso')->count())->toBe(0);
});

test('V3-5 origin equal to destination is rejected', function () {
    expect(fn () => traspaso($this->admin, ['sucursal_id' => $this->origen->id, 'sucursal_destino_id' => $this->origen->id, 'lineas' => [$this->arroz->id => '1']]))
        ->toThrow(ValidationException::class);
});

test('V3-5 inactive or foreign destination is rejected', function () {
    $cerrada = Sucursal::factory()->inactiva()->create(['empresa_id' => $this->empresa->id]);
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $ajena = Sucursal::factory()->create(['empresa_id' => $empresaB->id]);
    $this->actingAs($this->admin);

    foreach ([$cerrada->id, $ajena->id, 999999] as $destino) {
        expect(fn () => traspaso($this->admin, ['sucursal_id' => $this->origen->id, 'sucursal_destino_id' => $destino, 'lineas' => [$this->arroz->id => '1']]))
            ->toThrow(ValidationException::class);
    }

    expect(stockDe($this->origen, $this->arroz))->toBe('20.000');
});

test('V3-5 a repeated transfer request is executed only once', function () {
    $datos = ['sucursal_id' => $this->origen->id, 'sucursal_destino_id' => $this->destino->id, 'nota' => null,
        'lineas' => [$this->arroz->id => '8'], 'clave' => (string) Str::uuid()];

    app(RegistrarTraspaso::class)->ejecutar($this->admin, $datos);
    app(RegistrarTraspaso::class)->ejecutar($this->admin, $datos);

    expect(stockDe($this->origen, $this->arroz))->toBe('12.000')
        ->and(stockDe($this->destino, $this->arroz))->toBe('8.000')
        ->and(OperacionInventario::query()->where('tipo', 'traspaso')->count())->toBe(1);
});

test('a seller cannot transfer stock (owner decision)', function () {
    $vendedor = User::factory()->vendedor($this->origen)->create();
    $this->actingAs($vendedor)->get(route('inventario.traspaso'))->assertForbidden();
});

test('the transfer screen validates and confirms through the domain action', function () {
    Livewire::test(NuevoTraspaso::class)
        ->set('sucursal_id', (string) $this->origen->id)
        ->set('sucursal_destino_id', (string) $this->origen->id)
        ->call('agregarProducto', $this->arroz->id)
        ->call('revisar')
        ->assertHasErrors(['sucursal_destino_id' => 'different'])
        ->set('sucursal_destino_id', (string) $this->destino->id)
        ->set('lineas.0.cantidad', '6')
        ->call('revisar')
        ->assertSet('confirmando', true)
        ->call('confirmar')
        ->assertRedirect(route('inventario.movimientos'));

    expect(stockDe($this->origen, $this->arroz))->toBe('14.000')
        ->and(stockDe($this->destino, $this->arroz))->toBe('6.000');
});
