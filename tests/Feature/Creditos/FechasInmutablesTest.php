<?php

use App\Livewire\Creditos\Historial;
use App\Models\Cliente;
use App\Models\Sucursal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('registering an installment never rewrites the sale, payment or cash dates', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'America/La_Paz'));
    [$empresa, $admin] = empresaConAdministrador();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $producto = productoDe($empresa, ['precio_consumidor' => '10.00']);
    $this->actingAs($admin);
    cargarStock($admin, $sucursal, $producto, '10');
    $cliente = new Cliente(['nombre' => 'X']);
    $cliente->save();
    $venta = confirmarVenta($admin, crearPedido($admin, $sucursal, [['producto_id' => $producto->id, 'cantidad' => '2']], ['cliente_id' => $cliente->id]),
        ['metodo' => 'efectivo', 'en_cuotas' => true, 'cuota_inicial' => '5']);
    $fechas = fn () => [
        DB::table('ventas')->value('fecha'),
        DB::table('pagos_venta')->where('tipo', 'inicial')->value('fecha_cobro'),
        DB::table('movimientos_caja')->orderBy('id')->value('fecha_operacion'),
    ];
    $antes = $fechas();

    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'America/La_Paz'));
    Livewire::test(Historial::class, ['venta' => $venta])->set('monto', '1')->call('registrar')->assertHasNoErrors();

    expect($antes)->toBe(['2026-10-19 13:00:00', '2026-10-19 13:00:00', '2026-10-19 13:00:00'])
        ->and($fechas())->toBe($antes);
});
