<?php

use App\Domain\Catalogo\Actions\GuardarProducto;
use App\Domain\Compras\Actions\RegistrarCompra;
use App\Domain\Produccion\Actions\GuardarMateriaPrima;
use App\Domain\Produccion\Actions\GuardarReceta;
use App\Domain\Produccion\Actions\RegistrarEnvasado;
use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Enums\Capacidad;
use App\Enums\Rubro;
use App\Livewire\Catalogo\ProductoFormulario;
use App\Livewire\Inventario\Movimientos;
use App\Livewire\Inventario\TrazabilidadLote;
use App\Livewire\Produccion\EnvasadoDetalle;
use App\Livewire\Produccion\EnvasadoFormulario;
use App\Livewire\Produccion\Envasados;
use App\Models\Empresa;
use App\Models\Envasado;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Producción, etapa P5 (ESPECIFICACION_PRODUCCION.md § 7 y § 9): envasado de un granel en presentaciones. Granel
 * «Suavizante floral» en litros con lotes G-1 (vence antes) y G-2, a Bs 2/L. Presentaciones: botella de 1 L (1 L de
 * granel + 1 botella + 1 tapa) y bidón de 5 L (5 L + 1 bidón + 1 tapa).
 */

function envUnidad(Empresa $empresa, string $nombre, string $abreviatura, bool $decimales): UnidadMedida
{
    $unidad = new UnidadMedida(['nombre' => $nombre, 'abreviatura' => $abreviatura, 'admite_decimales' => $decimales, 'unidad_medida_sin' => 57]);
    $unidad->empresa_id = $empresa->id;
    $unidad->save();

    return $unidad;
}

/**
 * @param  list<array<string, string|int>>  $lineas
 */
function envComprar(User $admin, Sucursal $sucursal, array $lineas): void
{
    app(RegistrarCompra::class)->ejecutar($admin, [
        'sucursal_id' => $sucursal->id, 'proveedor_id' => Proveedor::query()->orderBy('id')->value('id'), 'referencia' => null,
        'lineas' => $lineas, 'metodo' => 'efectivo', 'efectivo' => null, 'electronico' => null, 'externo' => true, 'clave' => (string) Str::uuid(),
    ]);
}

/**
 * @param  list<array{producto_id: int|string, cantidad: string}>  $envases
 */
function envPresentar(User $admin, Producto $presentacion, ?Producto $granel, string $porUnidad, array $envases): Producto
{
    $ficha = $presentacion->fresh();

    return app(GuardarProducto::class)->actualizar($admin, $ficha, [
        'nombre' => $ficha->nombre, 'contenido' => $ficha->contenido, 'tipo_venta' => $ficha->tipo_venta, 'unidad_medida_id' => $ficha->unidad_medida_id,
        'precio_consumidor' => $ficha->precio_consumidor, 'stock_minimo' => '0', 'clase' => $ficha->clase,
        'controla_stock' => $ficha->controla_stock, 'controla_vencimiento' => $ficha->controla_vencimiento,
        'presentacion' => ['granel_id' => $granel === null ? '' : (string) $granel->id, 'granel_por_unidad' => $porUnidad, 'envases' => $envases],
    ]);
}

/**
 * @param  array<string, mixed>  $datos
 */
function envRegistrar(User $actor, array $datos): Envasado
{
    return app(RegistrarEnvasado::class)->ejecutar($actor, $datos + ['lote_id' => null, 'envases' => [], 'nota' => null, 'clave' => (string) Str::uuid()]);
}

function envStockLote(Producto $producto, string $numero): string
{
    return (string) (DB::table('stock_lotes as sl')->join('lotes as l', 'l.id', '=', 'sl.lote_id')
        ->where('sl.producto_id', $producto->id)->where('l.numero', $numero)->value('sl.cantidad') ?? '0.000');
}

function envLote(Producto $producto, string $numero): int
{
    return (int) DB::table('lotes')->where('producto_id', $producto->id)->where('numero', $numero)->value('id');
}

/**
 * Todos los mensajes de la ValidationException que lanza $accion.
 */
function envErrores(callable $accion): string
{
    try {
        $accion();
    } catch (ValidationException $e) {
        return collect($e->errors())->flatten()->implode(' ');
    }

    return '';
}

beforeEach(function () {
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->litro = envUnidad($this->empresa, 'Litro', 'L', true);
    $this->und = envUnidad($this->empresa, 'Unidad', 'und.', false);
    $proveedor = new Proveedor(['nombre' => 'Envases del Sur']);
    $proveedor->empresa_id = $this->empresa->id;
    $proveedor->save();
    $this->actingAs($this->admin);
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa, Rubro::General, [Capacidad::Produccion]);

    $materia = fn (UnidadMedida $unidad, string $nombre): Producto => app(GuardarMateriaPrima::class)->crear($this->admin, [
        'nombre' => $nombre, 'unidad_medida_id' => $unidad->id, 'controla_vencimiento' => false,
    ]);
    $this->agua = $materia($this->litro, 'Agua desmineralizada');
    $this->botella = $materia($this->und, 'Botella 1 L');
    $this->bidon = $materia($this->und, 'Bidón 5 L');
    $this->tapa = $materia($this->und, 'Tapa rosca');

    // El granel es un producto fabricado: tiene receta (§ 2).
    $this->granel = Producto::factory()->enUnidad($this->litro)->porMedida()
        ->create(['nombre' => 'Suavizante floral', 'controla_vencimiento' => true]);
    app(GuardarReceta::class)->ejecutar($this->admin, [
        'producto_id' => $this->granel->id, 'cantidad_base' => '1', 'nota' => null, 'version' => null,
        'lineas' => [['producto_id' => $this->agua->id, 'cantidad' => '1']],
    ]);

    $this->p1 = Producto::factory()->enUnidad($this->litro)->create(['nombre' => 'Suavizante floral botella', 'contenido' => '1', 'controla_vencimiento' => true]);
    $this->p5 = Producto::factory()->enUnidad($this->litro)->create(['nombre' => 'Suavizante floral bidón', 'contenido' => '5', 'controla_vencimiento' => true]);
    envPresentar($this->admin, $this->p1, $this->granel, '1', [['producto_id' => $this->botella->id, 'cantidad' => '1'], ['producto_id' => $this->tapa->id, 'cantidad' => '1']]);
    envPresentar($this->admin, $this->p5, $this->granel, '5', [['producto_id' => $this->bidon->id, 'cantidad' => '1'], ['producto_id' => $this->tapa->id, 'cantidad' => '1']]);

    envComprar($this->admin, $this->matriz, [
        ['producto_id' => $this->granel->id, 'cantidad' => '100', 'costo' => '2', 'lote' => 'G-1', 'vencimiento' => '31/12/2027'],
        ['producto_id' => $this->granel->id, 'cantidad' => '50', 'costo' => '2', 'lote' => 'G-2', 'vencimiento' => '30/06/2028'],
        ['producto_id' => $this->botella->id, 'cantidad' => '50', 'costo' => '1.20'],
        ['producto_id' => $this->bidon->id, 'cantidad' => '10', 'costo' => '4'],
        ['producto_id' => $this->tapa->id, 'cantidad' => '100', 'costo' => '0.10'],
    ]);
    // La compra solo recalcula el costo de materias primas; el del granel sale de sus órdenes (P3).
    $this->granel->forceFill(['costo_promedio' => '2.000000'])->save();
});

test('P5: a presentation stores its bulk product, bulk per unit and packaging; invalid setups are rejected', function () {
    expect($this->p1->fresh())->granel_id->toBe($this->granel->id)->granel_por_unidad->toBe('1.000')
        ->and($this->p1->envases()->get()->map(fn ($e) => [$e->producto_id, (string) $e->cantidad])->all())
        ->toBe([[$this->botella->id, '1.000'], [$this->tapa->id, '1.000']])
        ->and(DB::table('auditoria')->where('accion', 'producto.envases')->count())->toBe(2);

    $galon = Producto::factory()->enUnidad($this->litro)->create(['nombre' => 'Suavizante floral galón', 'contenido' => '4', 'controla_vencimiento' => true]);
    $tapa = [['producto_id' => $this->tapa->id, 'cantidad' => '1']];

    expect(fn () => envPresentar($this->admin, $galon, $galon, '4', $tapa))->toThrow(ValidationException::class, 'Elige como producto de origen otro producto')
        // Sin cadenas: una presentación no puede ser granel ni el granel puede ser presentación de otro.
        ->and(fn () => envPresentar($this->admin, $galon, $this->p1, '4', $tapa))->toThrow(ValidationException::class, 'Elige como producto de origen otro producto')
        ->and(fn () => envPresentar($this->admin, $this->granel, Producto::factory()->enUnidad($this->litro)->create(['nombre' => 'Base', 'controla_vencimiento' => true]), '1', []))
        ->toThrow(ValidationException::class, 'ya es el producto de origen de otros productos envasados')
        ->and(fn () => envPresentar($this->admin, $this->agua, $this->granel, '1', []))->toThrow(ValidationException::class, 'Solo un producto de venta')
        ->and(envErrores(fn () => envPresentar($this->admin, $galon, $this->granel, '0', $tapa)))->toContain('mayor que cero')
        ->and(envErrores(fn () => envPresentar($this->admin, $galon, $this->granel, '4', [['producto_id' => $this->p1->id, 'cantidad' => '1']])))->toContain('Elige una materia prima de tu empresa')
        ->and(envErrores(fn () => envPresentar($this->admin, $galon, $this->granel, '4', [...$tapa, ...$tapa])))->toContain('Tapa rosca ya está en la lista')
        ->and(envErrores(fn () => envPresentar($this->admin, $galon, $this->granel, '4', [['producto_id' => $this->tapa->id, 'cantidad' => '1.5']])))->toContain('unidades enteras');

    $sinLote = Producto::factory()->enUnidad($this->litro)->create(['nombre' => 'Suavizante sin lote', 'controla_vencimiento' => false]);
    expect(envErrores(fn () => envPresentar($this->admin, $sinLote, $this->granel, '1', [])))->toContain('marca también «Controla lote y vencimiento»')
        ->and($galon->fresh()->granel_id)->toBeNull()
        ->and($galon->envases()->count())->toBe(0);

    // El formulario propone el granel por unidad con el contenido (4 L de un granel en litros) y guarda los envases.
    Livewire::test(ProductoFormulario::class, ['producto' => $galon->fresh()])
        ->assertSee('Producto obtenido mediante envasado')
        ->assertSee('Producto de origen (a granel)')
        ->assertSee('Suavizante floral')
        ->set('granel_id', (string) $this->granel->id)
        ->assertSee('Cantidad utilizada por envase (L)')
        ->assertSee('Materiales de envasado')
        ->assertSet('granel_por_unidad', '4')
        ->call('agregarEnvase')
        ->set('envases.0.producto_id', (string) $this->bidon->id)
        ->call('agregarEnvase')
        ->set('envases.1.producto_id', (string) $this->tapa->id)
        ->call('quitarEnvase', 0)
        ->call('guardar')
        ->assertHasNoErrors();

    expect($galon->fresh())->granel_id->toBe($this->granel->id)->granel_por_unidad->toBe('4.000')
        ->and($galon->envases()->pluck('producto_id')->all())->toBe([$this->tapa->id]);

    // Granel vacío: deja de ser presentación y pierde sus envases.
    envPresentar($this->admin, $galon->fresh(), null, '', []);
    expect($galon->fresh())->granel_id->toBeNull()->granel_por_unidad->toBeNull()
        ->and($galon->envases()->count())->toBe(0);
});

test('P5: packaging takes the bulk from the chosen lot and enters the presentations with that lot; merma cost is shared', function () {
    $clave = (string) Str::uuid();
    $datos = [
        'sucursal_id' => $this->matriz->id, 'granel_id' => $this->granel->id, 'lote_id' => envLote($this->granel, 'G-1'),
        'unidades' => [$this->p1->id => '20', $this->p5->id => '4'], 'granel_real' => '41',
        'envases' => [$this->botella->id => '20', $this->bidon->id => '4', $this->tapa->id => '25'], 'nota' => 'Turno mañana', 'clave' => $clave,
    ];
    $envasado = envRegistrar($this->admin, $datos);
    $repetido = envRegistrar($this->admin, $datos);
    $operacion = DB::table('operaciones_inventario')->where('id', $envasado->operacion_id)->first();

    // Granel: 20 × 1 + 4 × 5 = 40 L necesarios; se usaron 41 (1 L de merma), todo del lote G-1.
    expect($repetido->id)->toBe($envasado->id)
        ->and(Envasado::query()->count())->toBe(1)
        ->and($envasado->fresh())
        ->codigo()->toBe('ENV-00001')
        ->granel_teorico->toBe('40.000')
        ->granel_real->toBe('41.000')
        ->granel_costo_unitario->toBe('2.000000')
        // Costo: 41 × 2 + 20 × 1,20 + 4 × 4 + 25 × 0,10 = 124,50.
        ->costo_total->toBe('124.500000')
        ->lote_id->toBe(envLote($this->granel, 'G-1'))
        ->nota->toBe('Turno mañana')
        ->and($operacion->serie)->toBe('ENV')
        ->and($operacion->tipo)->toBe('envasado')
        ->and($operacion->nota)->toBe('Envasado de Suavizante floral · lote G-1')
        ->and(envStockLote($this->granel, 'G-1'))->toBe('59.000')
        ->and(envStockLote($this->granel, 'G-2'))->toBe('50.000')
        ->and(stockDe($this->matriz, $this->granel))->toBe('109.000')
        ->and(stockDe($this->matriz, $this->botella))->toBe('30.000')
        ->and(stockDe($this->matriz, $this->bidon))->toBe('6.000')
        ->and(stockDe($this->matriz, $this->tapa))->toBe('75.000')
        // Las presentaciones entran con el lote y el vencimiento del granel.
        ->and(envStockLote($this->p1, 'G-1'))->toBe('20.000')
        ->and(envStockLote($this->p5, 'G-1'))->toBe('4.000')
        ->and(substr((string) DB::table('lotes')->where('id', envLote($this->p1, 'G-1'))->value('vencimiento'), 0, 10))->toBe('2027-12-31')
        ->and(stockDe($this->matriz, $this->p1))->toBe('20.000');

    // Reparto: granel 41 + 41; botella 24 a la de 1 L; bidón 16 a la de 5 L; tapas 2,50 en proporción 20:4.
    // 1 L: 41 + 24 + 2,083333… = 67,083333 → 3,354167 c/u. 5 L: 124,50 − 67,083333 = 57,416667 → 14,354167 c/u.
    expect($envasado->items()->get()->map(fn ($i) => [$i->producto_id, (string) $i->unidades, (string) $i->granel_por_unidad, (string) $i->costo_unitario, (string) $i->costo_total])->all())
        ->toBe([
            [$this->p1->id, '20.000', '1.000', '3.354167', '67.083333'],
            [$this->p5->id, '4.000', '5.000', '14.354167', '57.416667'],
        ])
        ->and($this->p1->fresh()->costo_promedio)->toBe('3.354167')
        ->and($this->p5->fresh()->costo_promedio)->toBe('14.354167')
        ->and($envasado->consumos()->get()->map(fn ($c) => [$c->producto_id, (string) $c->cantidad_teorica, (string) $c->cantidad_real, (string) $c->costo_unitario])->all())
        ->toBe([
            [$this->botella->id, '20.000', '20.000', '1.200000'],
            [$this->tapa->id, '24.000', '25.000', '0.100000'],
            [$this->bidon->id, '4.000', '4.000', '4.000000'],
        ])
        ->and(DB::table('auditoria')->where('accion', 'envasado.registrado')->count())->toBe(1)
        ->and(DB::table('operaciones_inventario')->where('tipo', 'envasado')->count())->toBe(1);
});

test('P5: less used than needed, expired or missing lots, invalid units and short stock save nothing', function () {
    $base = [
        'sucursal_id' => $this->matriz->id, 'granel_id' => $this->granel->id, 'lote_id' => envLote($this->granel, 'G-1'),
        'unidades' => [$this->p1->id => '20'], 'granel_real' => '20', 'envases' => [$this->botella->id => '20', $this->tapa->id => '20'],
    ];

    expect(envErrores(fn () => envRegistrar($this->admin, ['granel_real' => '19.5'] + $base)))->toContain('Suavizante floral: lo usado no puede ser menor que lo necesario (20 L)')
        ->and(envErrores(fn () => envRegistrar($this->admin, ['envases' => [$this->botella->id => '20', $this->tapa->id => '19']] + $base)))->toContain('Tapa rosca: lo usado no puede ser menor')
        ->and(envErrores(fn () => envRegistrar($this->admin, ['envases' => [$this->botella->id => '20']] + $base)))->toContain('Tapa rosca: escribe lo que se usó')
        ->and(envErrores(fn () => envRegistrar($this->admin, ['unidades' => [$this->p1->id => '1.5']] + $base)))->toContain('unidades enteras')
        ->and(envErrores(fn () => envRegistrar($this->admin, ['unidades' => [$this->p1->id => '0']] + $base)))->toContain('al menos una presentación')
        ->and(envErrores(fn () => envRegistrar($this->admin, ['unidades' => [$this->agua->id => '5']] + $base)))->toContain('no es de Suavizante floral')
        ->and(envErrores(fn () => envRegistrar($this->admin, ['lote_id' => null] + $base)))->toContain('Elige el lote de Suavizante floral')
        ->and(envErrores(fn () => envRegistrar($this->admin, ['lote_id' => envLote($this->granel, 'G-1') + 1000] + $base)))->toContain('Elige el lote de Suavizante floral')
        ->and(envErrores(fn () => envRegistrar($this->admin, ['granel_id' => $this->agua->id] + $base)))->toContain('Elige el granel')
        ->and(envErrores(fn () => envRegistrar($this->admin, ['clave' => 'no-es-uuid'] + $base)))->toContain('clave válida');

    // El lote G-2 tiene 50 L y se necesitan 5 × 1 + 10 × 5 = 55: no alcanza y no se guarda nada.
    expect(envErrores(fn () => envRegistrar($this->admin, [
        'lote_id' => envLote($this->granel, 'G-2'), 'unidades' => [$this->p1->id => '5', $this->p5->id => '10'], 'granel_real' => '55',
        'envases' => [$this->botella->id => '5', $this->bidon->id => '10', $this->tapa->id => '15'],
    ] + $base)))->toContain('No hay stock suficiente');

    // Un lote vencido no se envasa.
    DB::table('lotes')->where('id', envLote($this->granel, 'G-1'))->update(['vencimiento' => '2020-01-31']);
    expect(envErrores(fn () => envRegistrar($this->admin, $base)))->toContain('El lote G-1 de Suavizante floral está vencido');

    // Un envase desactivado tampoco.
    DB::table('lotes')->where('id', envLote($this->granel, 'G-1'))->update(['vencimiento' => '2027-12-31']);
    $this->tapa->forceFill(['activo' => false])->save();
    expect(envErrores(fn () => envRegistrar($this->admin, $base)))->toContain('envases desactivados (Tapa rosca)')
        ->and(Envasado::query()->count())->toBe(0)
        ->and(DB::table('operaciones_inventario')->where('tipo', 'envasado')->count())->toBe(0)
        ->and(envStockLote($this->granel, 'G-1'))->toBe('100.000')
        ->and(envStockLote($this->granel, 'G-2'))->toBe('50.000')
        ->and(stockDe($this->matriz, $this->tapa))->toBe('100.000')
        ->and(stockDe($this->matriz, $this->p1))->toBe('0.000');
});

test('P5: a bulk product without lots packages by branch, with or without packaging materials', function () {
    $detergente = Producto::factory()->enUnidad($this->litro)->porMedida()->create(['nombre' => 'Detergente']);
    $sachet = Producto::factory()->enUnidad($this->litro)->create(['nombre' => 'Detergente sachet', 'contenido' => '0.5']);
    envPresentar($this->admin, $sachet, $detergente, '0.5', []);
    envComprar($this->admin, $this->matriz, [['producto_id' => $detergente->id, 'cantidad' => '30', 'costo' => '3']]);
    $detergente->forceFill(['costo_promedio' => '1.500000'])->save();

    $envasado = envRegistrar($this->admin, [
        'sucursal_id' => $this->matriz->id, 'granel_id' => $detergente->id, 'lote_id' => 999,
        'unidades' => [$sachet->id => '15'], 'granel_real' => '7.5',
    ]);

    // 15 × 0,5 L = 7,5 L × Bs 1,50 = Bs 11,25 → Bs 0,75 por sachet. Sin lotes: el lote enviado se ignora.
    expect($envasado->fresh())->lote_id->toBeNull()->granel_teorico->toBe('7.500')->costo_total->toBe('11.250000')
        ->and($envasado->consumos()->count())->toBe(0)
        ->and(stockDe($this->matriz, $detergente))->toBe('22.500')
        ->and(stockDe($this->matriz, $sachet))->toBe('15.000')
        ->and($sachet->fresh()->costo_promedio)->toBe('0.750000')
        ->and(DB::table('operaciones_inventario')->where('id', $envasado->operacion_id)->value('nota'))->toBe('Envasado de Detergente');
});

test('P5: another company, a seller or a company without Producción cannot package', function () {
    $envasado = envRegistrar($this->admin, [
        'sucursal_id' => $this->matriz->id, 'granel_id' => $this->granel->id, 'lote_id' => envLote($this->granel, 'G-1'),
        'unidades' => [$this->p1->id => '2'], 'granel_real' => '2', 'envases' => [$this->botella->id => '2', $this->tapa->id => '2'],
    ]);
    $vendedor = User::factory()->vendedor($this->matriz)->create();

    $this->actingAs($vendedor)->get(route('produccion.envasados'))->assertForbidden();
    $this->get(route('produccion.envasados.ver', $envasado))->assertForbidden();
    expect(fn () => envRegistrar($vendedor, ['sucursal_id' => $this->matriz->id, 'granel_id' => $this->granel->id, 'unidades' => [$this->p1->id => '1'], 'granel_real' => '1']))
        ->toThrow(AuthorizationException::class);

    [$otra, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    $sucursalB = Sucursal::factory()->create(['empresa_id' => $otra->id, 'nombre' => 'Sucursal B']);
    app(CambiarFuncionesEmpresa::class)->ejecutar($adminB, $otra, Rubro::General, [Capacidad::Produccion]);

    $this->get(route('produccion.envasados.ver', $envasado))->assertNotFound();
    Livewire::test(Envasados::class)->assertDontSee('ENV-00001');
    expect(envErrores(fn () => envRegistrar($adminB, [
        'sucursal_id' => $sucursalB->id, 'granel_id' => $this->granel->id, 'lote_id' => envLote($this->granel, 'G-1'),
        'unidades' => [$this->p1->id => '1'], 'granel_real' => '1', 'envases' => [$this->botella->id => '1', $this->tapa->id => '1'],
    ])))->toContain('Elige el granel');

    // El administrador A no puede usar una sucursal de B.
    $this->actingAs($this->admin);
    expect(envErrores(fn () => envRegistrar($this->admin, [
        'sucursal_id' => $sucursalB->id, 'granel_id' => $this->granel->id, 'lote_id' => envLote($this->granel, 'G-1'),
        'unidades' => [$this->p1->id => '1'], 'granel_real' => '1', 'envases' => [$this->botella->id => '1', $this->tapa->id => '1'],
    ])))->toContain('Selecciona una sucursal activa')
        ->and(Envasado::withoutGlobalScopes()->count())->toBe(1);

    // Sin la capacidad: pantallas 404, la acción se rechaza y Movimientos no ofrece el tipo.
    Livewire::withQueryParams([])->test(Movimientos::class)->assertSeeHtml('value="envasado"');
    app(CambiarFuncionesEmpresa::class)->ejecutar($this->admin, $this->empresa->fresh(), Rubro::General, [Capacidad::LotesVencimientos]);

    $this->get(route('produccion.envasados'))->assertNotFound();
    $this->get(route('produccion.envasados.crear'))->assertNotFound();
    $this->get(route('produccion.envasados.ver', $envasado))->assertNotFound();
    Livewire::withQueryParams([])->test(Movimientos::class)->assertDontSeeHtml('value="envasado"');
    expect(fn () => envRegistrar($this->admin, ['sucursal_id' => $this->matriz->id, 'granel_id' => $this->granel->id, 'unidades' => [$this->p1->id => '1'], 'granel_real' => '1']))
        ->toThrow(AuthorizationException::class);
});

test('P5: the screens register a packaging, show its detail and link it from the lot traceability', function () {
    Livewire::withQueryParams(['granel' => (string) $this->granel->id])->test(EnvasadoFormulario::class)
        ->assertSet('sucursal_id', (string) $this->matriz->id)
        // Por defecto, el lote vigente que vence antes.
        ->assertSet('lote_id', (string) envLote($this->granel, 'G-1'))
        ->assertSee('Suavizante floral botella')
        ->set('unidades.'.$this->p1->id, '20')
        ->set('unidades.'.$this->p5->id, '4')
        ->assertSet('granel_real', '40')
        ->assertSet('envases.'.$this->tapa->id, '24')
        ->set('envases.'.$this->tapa->id, '25')
        // Lo escrito a mano ya no sigue a lo necesario.
        ->set('unidades.'.$this->p1->id, '21')
        ->assertSet('granel_real', '41')
        ->assertSet('envases.'.$this->tapa->id, '25')
        ->assertSet('envases.'.$this->botella->id, '21')
        ->set('unidades.'.$this->p1->id, '20')
        ->set('granel_real', '41')
        ->set('lote_id', (string) envLote($this->granel, 'G-2'))
        ->call('guardar')
        ->assertRedirect(route('produccion.envasados.ver', Envasado::query()->sole()));

    // El aviso del guardado queda en la sesión de la prueba: se quita para que las pantallas muestren solo su tabla.
    session()->forget('aviso');
    $envasado = Envasado::query()->sole();
    expect($envasado)->lote_id->toBe(envLote($this->granel, 'G-2'))->granel_real->toBe('41.000')
        ->and(envStockLote($this->granel, 'G-2'))->toBe('9.000')
        ->and(envStockLote($this->p1, 'G-2'))->toBe('20.000');

    Livewire::withQueryParams([])->test(Envasados::class)->assertSee('ENV-00001')->assertSee('Suavizante floral bidón');
    Livewire::withQueryParams(['q' => 'ENV-1'])->test(Envasados::class)->assertSee('ENV-00001');
    Livewire::withQueryParams(['q' => 'Detergente'])->test(Envasados::class)->assertDontSee('ENV-00001');
    Livewire::test(EnvasadoDetalle::class, ['envasado' => $envasado])
        ->assertSee('Presentaciones que entraron')->assertSee('Bs 124,50')->assertSee('Tapa rosca');

    // Trazabilidad del lote G-2 de la presentación: lleva al envasado.
    Livewire::test(TrazabilidadLote::class, ['lote' => Lote::query()->findOrFail(envLote($this->p1, 'G-2'))])
        ->assertSee('Envasado ENV-00001')->assertSee(route('produccion.envasados.ver', $envasado));

    // El menú de Producción muestra Envasado.
    $this->get(route('produccion.envasados'))->assertOk()->assertSee(route('produccion.envasados.crear'));
});

test('P5: Nuevo envasado links a presentation without packaging materials and sends the stock errors to the red notice', function () {
    $recarga = Producto::factory()->enUnidad($this->litro)->create(['nombre' => 'Suavizante floral recarga', 'contenido' => '2', 'controla_vencimiento' => true]);
    envPresentar($this->admin, $recarga, $this->granel, '2', []);

    // 60 botellas: solo hay 50. No se guarda nada y el aviso rojo recibe todos los mensajes, también los de `lineas`,
    // que no es una propiedad del componente (Livewire no la devuelve en memo.errors).
    $prueba = Livewire::withQueryParams(['granel' => (string) $this->granel->id])->test(EnvasadoFormulario::class)
        ->assertSeeHtml('data-test="sin-materiales-'.$recarga->id.'"')
        ->assertSee('Sin materiales de envasado.')
        ->assertSeeHtml(route('productos.editar', $recarga->id).'#materiales-envasado')
        ->assertDontSeeHtml('data-test="sin-materiales-'.$this->p1->id.'"')
        ->set('unidades.'.$this->p1->id, '60')
        ->call('guardar')
        ->assertHasErrors('lineas')
        ->assertSee('No hay stock suficiente');

    $mensajes = $prueba->effects['erroresValidacion'] ?? [];
    expect($mensajes)->toContain('No hay stock suficiente; no se guardó ningún cambio.')
        ->and(implode(' ', $mensajes))->toContain('Botella 1 L')
        ->and(Envasado::query()->count())->toBe(0)
        ->and(stockDe($this->matriz, $this->botella))->toBe('50.000');

    // Sin una acción que falle no viaja el efecto: al corregir las unidades el aviso no se repite.
    $prueba->set('unidades.'.$this->p1->id, '10');
    expect($prueba->effects)->not->toHaveKey('erroresValidacion');
});
