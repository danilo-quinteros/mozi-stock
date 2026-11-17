<?php

use App\Domain\Compras\Actions\RegistrarCompra;
use App\Domain\Produccion\Actions\AnularOrden;
use App\Domain\Produccion\Actions\AprobarOrden;
use App\Domain\Produccion\Actions\EmitirOrden;
use App\Domain\Produccion\Actions\FinalizarOrden;
use App\Domain\Produccion\Actions\GuardarMateriaPrima;
use App\Domain\Produccion\Actions\GuardarReceta;
use App\Domain\Produccion\Consultas\ReporteProduccion;
use App\Domain\Reportes\Periodo;
use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Enums\Capacidad;
use App\Enums\Rubro;
use App\Livewire\Produccion\Reportes;
use App\Models\Empresa;
use App\Models\OrdenProduccion;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Producción, etapa P4 (ESPECIFICACION_PRODUCCION.md § 12): reportes de las órdenes finalizadas en el período.
 *
 * Suavizante: 100 L = 95 L agua + 2,5 kg fragancia (costo 46) + 3 botellas (1,20). Limpiador: 10 L = 10 L agua (0,50).
 *   OP-0001 suavizante 50 L, Casa Matriz, finalizada 10/11 10:00 → 48 L; usó 47,5 agua, 1 fragancia, 2 botellas = 72,15.
 *   OP-0002 suavizante 10 L, Casa Matriz, finalizada 15/11 23:30 La Paz (16/11 03:30 UTC) → 10,3 L; lo teórico = 17,45.
 *   OP-0003 limpiador 20 L, Norte, finalizada 12/11 09:00 → 20 L; 20 agua = 10,00.
 *   OP-0004 suavizante 10 L, finalizada 31/10 20:00 La Paz (01/11 00:00 UTC): es de octubre.
 *   OP-0005 emitida y OP-0006 aprobada y anulada: no entran en ningún reporte.
 */

function rpUnidad(Empresa $empresa, string $nombre, string $abreviatura, bool $decimales): UnidadMedida
{
    $unidad = new UnidadMedida(['nombre' => $nombre, 'abreviatura' => $abreviatura, 'admite_decimales' => $decimales, 'unidad_medida_sin' => 57]);
    $unidad->empresa_id = $empresa->id;
    $unidad->save();

    return $unidad;
}

/**
 * @param  list<array<string, string|int>>  $lineas
 */
function rpComprar(User $admin, Sucursal $sucursal, array $lineas): void
{
    app(RegistrarCompra::class)->ejecutar($admin, [
        'sucursal_id' => $sucursal->id, 'proveedor_id' => Proveedor::query()->orderBy('id')->value('id'), 'referencia' => null,
        'lineas' => $lineas, 'metodo' => 'efectivo', 'efectivo' => null, 'externo' => true, 'electronico' => null, 'clave' => (string) Str::uuid(),
    ]);
}

function rpEmitir(User $admin, Producto $producto, Sucursal $sucursal, string $cantidad): OrdenProduccion
{
    return app(EmitirOrden::class)->emitir($admin, [
        'producto_id' => $producto->id, 'sucursal_id' => $sucursal->id, 'cantidad' => $cantidad,
        'fecha_programada' => '2026-12-01', 'nota' => null, 'clave' => (string) Str::uuid(),
    ]);
}

/**
 * Emite, aprueba y finaliza una orden con el reloj en $finUtc. Sin consumos, usa lo teórico.
 *
 * @param  array<int, string>|null  $consumos
 */
function rpProducir(User $admin, Producto $producto, Sucursal $sucursal, string $cantidad, string $finUtc, string $obtenida, ?array $consumos = null): OrdenProduccion
{
    Carbon::setTestNow(Carbon::parse($finUtc, 'UTC'));

    try {
        $orden = rpEmitir($admin, $producto, $sucursal, $cantidad);
        app(AprobarOrden::class)->ejecutar($admin, $orden);
        $teoricas = $orden->items()->get()->mapWithKeys(fn ($i): array => [$i->producto_id => (string) $i->cantidad_teorica])->all();
        app(FinalizarOrden::class)->ejecutar($admin, $orden->fresh(), [
            'cantidad_obtenida' => $obtenida, 'consumos' => $consumos ?? $teoricas, 'vencimiento' => Carbon::now()->addDays(180)->format('Y-m-d'),
        ]);
    } finally {
        Carbon::setTestNow();
    }

    return $orden->fresh();
}

/**
 * @return list<array<string, string|int|null>>
 */
function rpFilas(Builder $consulta): array
{
    return $consulta->get()->map(fn ($f): array => (array) $f)->all();
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Norte']);
    $litro = rpUnidad($this->empresa, 'Litro', 'L', true);
    $kg = rpUnidad($this->empresa, 'Kilogramo', 'kg', true);
    $und = rpUnidad($this->empresa, 'Unidad', 'und.', false);
    $this->suavizante = Producto::factory()->enUnidad($litro)->porMedida()
        ->create(['nombre' => 'Suavizante floral', 'sku' => 'SUA-01', 'controla_vencimiento' => true]);
    $this->limpiador = Producto::factory()->enUnidad($litro)->porMedida()->create(['nombre' => 'Limpiador multiuso', 'sku' => 'LIM-01']);
    $proveedor = new Proveedor(['nombre' => 'Químicos del Oriente']);
    $proveedor->empresa_id = $this->empresa->id;
    $proveedor->save();
    $this->actingAs($this->admin);
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::General, [Capacidad::Produccion]);

    $materia = fn (UnidadMedida $unidad, string $nombre, bool $lote = false): Producto => app(GuardarMateriaPrima::class)->crear($this->admin, [
        'nombre' => $nombre, 'unidad_medida_id' => $unidad->id, 'controla_vencimiento' => $lote,
    ]);
    $this->agua = $materia($litro, 'Agua desmineralizada');
    $this->fragancia = $materia($kg, 'Fragancia floral', true);
    $this->botella = $materia($und, 'Botella 1 L');
    $receta = fn (Producto $producto, string $base, array $lineas) => app(GuardarReceta::class)->ejecutar($this->admin, [
        'producto_id' => $producto->id, 'cantidad_base' => $base, 'nota' => null, 'version' => null,
        'lineas' => array_map(fn (array $l): array => ['producto_id' => $l[0]->id, 'cantidad' => $l[1]], $lineas),
    ]);
    $receta($this->suavizante, '100', [[$this->agua, '95'], [$this->fragancia, '2.5'], [$this->botella, '3']]);
    $receta($this->limpiador, '10', [[$this->agua, '10']]);

    rpComprar($this->admin, $this->matriz, [
        ['producto_id' => $this->fragancia->id, 'cantidad' => '2', 'costo' => '40', 'lote' => 'F-1', 'vencimiento' => '31/03/2028'],
        ['producto_id' => $this->fragancia->id, 'cantidad' => '3', 'costo' => '50', 'lote' => 'F-2', 'vencimiento' => '31/12/2028'],
        ['producto_id' => $this->agua->id, 'cantidad' => '200', 'costo' => '0.50'],
        ['producto_id' => $this->botella->id, 'cantidad' => '10', 'costo' => '1.20'],
    ]);
    rpComprar($this->admin, $this->norte, [['producto_id' => $this->agua->id, 'cantidad' => '100', 'costo' => '0.50']]);

    rpProducir($this->admin, $this->suavizante, $this->matriz, '50', '2026-11-10 14:00:00', '48',
        [$this->agua->id => '47.5', $this->fragancia->id => '1', $this->botella->id => '2']);
    rpProducir($this->admin, $this->suavizante, $this->matriz, '10', '2026-11-16 03:30:00', '10.3');
    rpProducir($this->admin, $this->limpiador, $this->norte, '20', '2026-11-12 13:00:00', '20');
    rpProducir($this->admin, $this->suavizante, $this->matriz, '10', '2026-11-01 00:00:00', '10');
    rpEmitir($this->admin, $this->suavizante, $this->matriz, '10');
    $anulada = rpEmitir($this->admin, $this->suavizante, $this->matriz, '10');
    app(AprobarOrden::class)->ejecutar($this->admin, $anulada);
    app(AnularOrden::class)->ejecutar($this->admin, $anulada->fresh(), 'Se canceló el pedido');

    $this->noviembre = Periodo::rango('2026-11-01', '2026-11-30');
});

test('P4: production by product adds up only the orders finalized in the period (La Paz days)', function () {
    $empresa = $this->empresa->id;

    expect(ReporteProduccion::resumen($empresa, $this->noviembre))->toBe(['ordenes' => 3, 'costo' => '99.60', 'con_merma' => 1])
        ->and(rpFilas(ReporteProduccion::porProducto($empresa, $this->noviembre)))->toBe([
            ['id' => $this->limpiador->id, 'nombre' => 'Limpiador multiuso', 'sku' => 'LIM-01', 'unidad' => 'L', 'ordenes' => 1,
                'planificada' => '20.000', 'obtenida' => '20.000', 'costo_total' => '10.000000'],
            ['id' => $this->suavizante->id, 'nombre' => 'Suavizante floral', 'sku' => 'SUA-01', 'unidad' => 'L', 'ordenes' => 2,
                'planificada' => '60.000', 'obtenida' => '58.300', 'costo_total' => '89.600000'],
        ])
        // 58,3 ÷ 60 = 97,17 %; 89,60 ÷ 58,3 = 1,5368782… → 1,536878.
        ->and(ReporteProduccion::rendimiento('60.000', '58.300'))->toBe('97.2')
        ->and(ReporteProduccion::costoUnitario('89.600000', '58.300'))->toBe('1.536878')
        ->and(ReporteProduccion::costoUnitario('10', '0'))->toBeNull();

    // La orden finalizada el 31/10 a las 20:00 de La Paz es de octubre aunque en UTC ya sea 1 de noviembre; la del
    // 15/11 a las 23:30 es del 15 aunque en UTC sea 16.
    expect(ReporteProduccion::resumen($empresa, Periodo::dia('2026-10-31')))->toBe(['ordenes' => 1, 'costo' => '17.45', 'con_merma' => 0])
        ->and(ReporteProduccion::resumen($empresa, Periodo::dia('2026-11-15'))['ordenes'])->toBe(1)
        ->and(ReporteProduccion::resumen($empresa, Periodo::dia('2026-11-16'))['ordenes'])->toBe(0);
});

test('P4: by order shows yield and cost of each finalized order, newest first; search by number or product', function () {
    $empresa = $this->empresa->id;
    $filas = ReporteProduccion::porOrden($empresa, $this->noviembre)->get();

    expect($filas->map(fn ($f): string => OrdenProduccion::formatearCodigo((int) $f->numero))->all())->toBe(['OP-0002', 'OP-0003', 'OP-0001'])
        ->and($filas->map(fn ($f): array => [(string) $f->obtenida, (string) $f->costo_total, (string) $f->costo_unitario, $f->sucursal])->all())->toBe([
            ['10.300', '17.450000', '1.694175', 'Casa Matriz'],
            ['20.000', '10.000000', '0.500000', 'Norte'],
            ['48.000', '72.150000', '1.503125', 'Casa Matriz'],
        ])
        ->and($filas->map(fn ($f): ?string => ReporteProduccion::rendimiento((string) $f->planificada, (string) $f->obtenida))->all())->toBe(['103.0', '100.0', '96.0'])
        ->and(ReporteProduccion::porOrden($empresa, $this->noviembre, null, 'OP-0002')->pluck('o.numero')->all())->toBe([2])
        ->and(ReporteProduccion::porOrden($empresa, $this->noviembre, null, '3')->pluck('o.numero')->all())->toBe([3])
        ->and(ReporteProduccion::porOrden($empresa, $this->noviembre, null, 'limpia')->pluck('o.numero')->all())->toBe([3])
        ->and(ReporteProduccion::porOrden($empresa, $this->noviembre, $this->norte->id)->pluck('o.numero')->all())->toBe([3]);
});

test('P4: raw-material consumption compares theoretical and used quantities with their cost', function () {
    $empresa = $this->empresa->id;

    // Agua 47,5 + 9,5 + 20; botellas 2 (1,5 → 2) + 1 (0,3 → 1); fragancia 1,25 + 0,25 teórica, 1 + 0,25 usada.
    expect(rpFilas(ReporteProduccion::materias($empresa, $this->noviembre)))->toBe([
        ['id' => $this->agua->id, 'nombre' => 'Agua desmineralizada', 'sku' => 'MP-0001', 'unidad' => 'L', 'ordenes' => 3,
            'teorica' => '77.000', 'usada' => '77.000', 'costo' => '38.500000000'],
        ['id' => $this->botella->id, 'nombre' => 'Botella 1 L', 'sku' => 'MP-0003', 'unidad' => 'und.', 'ordenes' => 2,
            'teorica' => '3.000', 'usada' => '3.000', 'costo' => '3.600000000'],
        ['id' => $this->fragancia->id, 'nombre' => 'Fragancia floral', 'sku' => 'MP-0002', 'unidad' => 'kg', 'ordenes' => 2,
            'teorica' => '1.500', 'usada' => '1.250', 'costo' => '57.500000000'],
    ])
        ->and(ReporteProduccion::desvio('1.500', '1.250'))->toBe('-16.7')
        ->and(ReporteProduccion::desvio('0', '1'))->toBeNull()
        ->and(rpFilas(ReporteProduccion::materias($empresa, $this->noviembre, $this->norte->id)))->toHaveCount(1)
        ->and(ReporteProduccion::materias($empresa, $this->noviembre, null, 'fragancia')->pluck('p.nombre')->all())->toBe(['Fragancia floral'])
        ->and(ReporteProduccion::resumen($empresa, $this->noviembre, $this->norte->id))->toBe(['ordenes' => 1, 'costo' => '10.00', 'con_merma' => 0]);
});

test('P4: the screen shows the three tabs with yield, waste and cost', function () {
    $this->get(route('produccion.reportes'))->assertOk()->assertSee('Reportes de producción');

    Livewire::withQueryParams(['desde' => '2026-11-01', 'hasta' => '2026-11-30'])->test(Reportes::class)
        ->assertSeeInOrder(['Órdenes finalizadas', '3', 'Bs 99,60', 'Órdenes con merma', '1'])
        ->assertSeeInOrder(['Limpiador multiuso', '100,0 %', 'Suavizante floral', '97,2 %', 'Merma 2,8 %', 'Bs 89,60', 'Bs 1,5369'])
        ->set('pestana', 'ordenes')
        ->assertSeeInOrder(['OP-0002', '23:30', '103,0 %', 'Excedente 3,0 %', 'OP-0003', 'OP-0001', '96,0 %', 'Merma 4,0 %', 'Bs 72,15', 'Bs 1,5031'])
        ->assertSee('15/11/2026 23:30')
        ->assertSee(route('produccion.ordenes.ver', OrdenProduccion::query()->where('numero', 1)->value('id')))
        ->assertDontSee('OP-0004')
        ->set('pestana', 'materias')
        ->assertSeeInOrder(['Agua desmineralizada', 'Botella 1 L', 'Fragancia floral', '0,25 kg', '16,7 %', 'Bs 57,50'])
        ->assertSee('−0,25 kg')->assertSee('16,7 % menos de lo teórico')
        ->set('sucursal', (string) $this->norte->id)
        ->assertSee('Agua desmineralizada')->assertDontSee('Fragancia floral')
        ->set('pestana', 'nada')
        ->assertSee('Limpiador multiuso')->assertDontSee('Suavizante floral');
});

test('P4: each tab exports CSV with the same filters', function () {
    $filtros = ['desde' => '2026-11-01', 'hasta' => '2026-11-30'];

    $productos = $this->get(route('produccion.reportes.exportar', $filtros));
    $productos->assertOk()->assertDownload('produccion-productos-2026-11-01_2026-11-30.csv');
    expect($productos->streamedContent())->toContain('Producto;Código;Unidad;Órdenes;Planificado;Obtenido;"Rendimiento %";Costo;"Costo unitario"')
        ->toContain('"Limpiador multiuso";LIM-01;L;1;20.000;20.000;100.0;10.00;0.5000')
        ->toContain('"Suavizante floral";SUA-01;L;2;60.000;58.300;97.2;89.60;1.5369');

    $ordenes = $this->get(route('produccion.reportes.exportar', $filtros + ['pestana' => 'ordenes', 'sucursal' => $this->matriz->id]))->streamedContent();
    expect($ordenes)->toContain('OP-0002;"15/11/2026 23:30";"Suavizante floral";SUA-01;"Casa Matriz";L;10.000;10.300;103.0;17.45;1.6942')
        ->toContain('OP-0001;"10/11/2026 10:00"')
        ->not->toContain('OP-0003')->not->toContain('OP-0004');

    $materias = $this->get(route('produccion.reportes.exportar', $filtros + ['pestana' => 'materias', 'q' => 'fragancia']))->streamedContent();
    expect($materias)->toContain('"Materia prima";Código;Unidad;Órdenes;Teórico;Usado;Diferencia;"Diferencia %";Costo')
        ->toContain('"Fragancia floral";MP-0002;kg;2;1.500;1.250;-0.250;-16.7;57.50')
        ->not->toContain('Agua desmineralizada');
});

test('P4: another company, a seller or a company without Producción cannot see the reports', function () {
    $vendedor = User::factory()->vendedor($this->matriz)->create();
    $this->actingAs($vendedor)->get(route('produccion.reportes'))->assertForbidden();
    $this->get(route('produccion.reportes.exportar'))->assertForbidden();

    [$otra, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    Sucursal::factory()->create(['empresa_id' => $otra->id, 'nombre' => 'Sucursal B']);
    $filtros = ['desde' => '2026-11-01', 'hasta' => '2026-11-30', 'sucursal' => $this->matriz->id];
    $this->get(route('produccion.reportes', $filtros))->assertNotFound();
    $this->get(route('produccion.reportes.exportar', $filtros))->assertNotFound();

    app(CambiarFuncionesEmpresa::class)->ejecutar($adminB, $otra, Rubro::General, [Capacidad::Produccion]);
    Livewire::withQueryParams($filtros)->test(Reportes::class)
        ->assertSee('Sin órdenes finalizadas en el período.')->assertDontSee('Suavizante floral')
        ->set('pestana', 'materias')->assertDontSee('Agua desmineralizada');

    foreach (['productos', 'ordenes', 'materias'] as $pestana) {
        $csv = $this->get(route('produccion.reportes.exportar', $filtros + ['pestana' => $pestana]))->streamedContent();
        expect(substr_count(trim($csv), "\n"))->toBe(0)->and($csv)->not->toContain('Suavizante')->not->toContain('Agua');
    }

    expect(ReporteProduccion::resumen($otra->id, $this->noviembre, $this->matriz->id))->toBe(['ordenes' => 0, 'costo' => '0.00', 'con_merma' => 0]);
});
