<?php

use App\Domain\AgenteMozi\Acciones\CatalogoAcciones;
use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\Datos\CatalogoDatos;
use App\Domain\AgenteMozi\Datos\ConsultaDatos;
use App\Domain\AgenteMozi\DTOs\AccionPropuesta;
use App\Domain\AgenteMozi\DTOs\DatasetPropuesto;
use App\Domain\AgenteMozi\Herramientas\RegistroHerramientas;
use App\Domain\AgenteMozi\Ia\DTOs\LlamadaHerramienta;
use App\Domain\AgenteMozi\Ia\InstruccionesSistema;
use App\Domain\AgenteMozi\Lienzo\ValidadorLienzo;
use App\Domain\Catalogo\Actions\GuardarProducto;
use App\Domain\Compras\Actions\RegistrarCompra;
use App\Domain\Inventario\Consultas\ReporteVencimientos;
use App\Domain\Produccion\Actions\AprobarOrden;
use App\Domain\Produccion\Actions\EmitirOrden;
use App\Domain\Produccion\Actions\FinalizarOrden;
use App\Domain\Produccion\Actions\GuardarMateriaPrima;
use App\Domain\Produccion\Actions\GuardarReceta;
use App\Domain\Produccion\Actions\RegistrarEnvasado;
use App\Domain\Produccion\Consultas\DisponibilidadMaterias;
use App\Domain\Produccion\Consultas\ListadoMateriasPrimas;
use App\Domain\Produccion\Consultas\ReporteProduccion;
use App\Domain\Produccion\Presentaciones;
use App\Domain\Reportes\Periodo;
use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Domain\Ventas\Actions\RegistrarDevolucion;
use App\Enums\Capacidad;
use App\Enums\Rubro;
use App\Livewire\AgenteMozi\Panel;
use App\Models\Empresa;
use App\Models\OrdenProduccion;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\UnidadMedida;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Agente Mozi · etapas 7a y 7b de Producción (docs/AGENTE_MOZI_IA.md § 2.1, § 5.2 y § 11 Fase 7): las materias primas
 * no aparecen en productos ni en stock, las fuentes de producción y de lotes solo existen con la capacidad, y UC-19,
 * UC-20, UC-22 a UC-27 repiten las cifras del módulo. Miércoles 18 de noviembre de 2026, 10:00 en La Paz.
 * Receta del suavizante: 100 L = 95 L de agua + 2,5 kg de fragancia (con lote) + 3 botellas (unidades enteras).
 */

function paUnidad(Empresa $empresa, string $nombre, string $abreviatura, bool $decimales): UnidadMedida
{
    $unidad = new UnidadMedida(['nombre' => $nombre, 'abreviatura' => $abreviatura, 'admite_decimales' => $decimales, 'unidad_medida_sin' => 57]);
    $unidad->empresa_id = $empresa->id;
    $unidad->save();

    return $unidad;
}

/**
 * @param  list<array<string, string|int>>  $lineas
 */
function paComprar(User $admin, Sucursal $sucursal, array $lineas): void
{
    app(RegistrarCompra::class)->ejecutar($admin, [
        'sucursal_id' => $sucursal->id, 'proveedor_id' => Proveedor::query()->orderBy('id')->value('id'), 'referencia' => null,
        'lineas' => $lineas, 'metodo' => 'efectivo', 'efectivo' => null, 'electronico' => null, 'externo' => true, 'clave' => (string) Str::uuid(),
    ]);
}

function paEmitir(User $actor, Producto $producto, Sucursal $sucursal, string $cantidad, string $fecha = '2026-11-20', ?string $nota = null): OrdenProduccion
{
    return app(EmitirOrden::class)->emitir($actor, [
        'producto_id' => $producto->id, 'sucursal_id' => $sucursal->id, 'cantidad' => $cantidad,
        'fecha_programada' => $fecha, 'nota' => $nota, 'clave' => (string) Str::uuid(),
    ]);
}

/**
 * Aprueba y finaliza la orden; sin consumos, usa los teóricos.
 *
 * @param  array<int, string>  $consumos
 */
function paFinalizar(User $actor, OrdenProduccion $orden, string $obtenida, array $consumos = []): OrdenProduccion
{
    app(AprobarOrden::class)->ejecutar($actor, $orden);
    $teoricas = $orden->items()->get()->mapWithKeys(fn ($i): array => [$i->producto_id => (string) $i->cantidad_teorica])->all();

    return app(FinalizarOrden::class)->ejecutar($actor, $orden->fresh(), [
        'cantidad_obtenida' => $obtenida, 'consumos' => $consumos + $teoricas, 'vencimiento' => '2027-05-18',
    ]);
}

/**
 * @param  array<string, mixed>  $argumentos
 * @return array<string, mixed>
 */
function paConsulta(User $usuario, array $argumentos): array
{
    test()->actingAs($usuario);

    return (new ConsultaDatos(new CatalogoDatos(ContextoAgente::desde($usuario))))->ejecutar($argumentos);
}

/**
 * Ejecuta una herramienta como el orquestador (registro, permisos y validación).
 *
 * @param  array<string, mixed>  $argumentos
 * @return array<string, mixed>
 */
function paLlamar(User $usuario, string $nombre, array $argumentos = []): array
{
    test()->actingAs($usuario);
    $registro = app(RegistroHerramientas::class);

    return $registro->ejecutar(new LlamadaHerramienta('c1', $nombre, $argumentos), $registro->disponibles($usuario), $usuario, ContextoAgente::desde($usuario));
}

/**
 * Datasets de la herramienta por clave, ya validados por el Lienzo.
 *
 * @param  array<string, mixed>  $argumentos
 * @return array<string, list<array<string, mixed>>>
 */
function paHerramienta(User $usuario, string $nombre, array $argumentos = []): array
{
    $resultado = paLlamar($usuario, $nombre, $argumentos);
    expect($resultado['error'] ?? null)->toBeNull();
    $datasets = [];

    foreach ($resultado['datasets'] as $item) {
        $datasets[$item['dataset']->clave] = app(ValidadorLienzo::class)->dataset($item['dataset'])['filas'];
    }

    return $datasets;
}

/**
 * Fila cuyo campo tiene el valor indicado.
 *
 * @param  list<array<string, mixed>>  $filas
 * @return array<string, mixed>
 */
function paFila(array $filas, string $campo, mixed $valor): array
{
    foreach ($filas as $fila) {
        if (($fila[$campo] ?? null) === $valor) {
            return $fila;
        }
    }

    throw new RuntimeException("Sin fila con {$campo} = {$valor}");
}

/**
 * Presentaciones del suavizante, envasado real y ventas (UC-25 y UC-26). Botella 1 L = 1 L + 1 botella + 1 tapa
 * (Bs 15); bidón 5 L = 5 L + 1 bidón + 1 tapa (Bs 55); galón 4 L = 4 L + 1 tapa (Bs 45), nunca envasado (costo 0).
 * Orden de 50 L → 48 L (lote OP-0001); se envasan 5 botellas y 2 bidones (15 L). Venta 1: 2 botellas + 1 bidón con
 * descuento global de Bs 8,50 (netos 27,00 y 49,50); venta 2: 3 L a granel a Bs 12; se devuelve 1 botella (Bs 13,50).
 * Queda en Casa Matriz: granel 30 L, botellas 4, bidones 1; envases: 3 botellas, 2 bidones, 3 tapas.
 *
 * @return array{p1: Producto, p5: Producto, galon: Producto, orden: OrdenProduccion}
 */
function paPresentaciones(object $t): array
{
    $t->actingAs($t->admin);
    $materia = fn (string $nombre): Producto => app(GuardarMateriaPrima::class)->crear($t->admin, ['nombre' => $nombre, 'unidad_medida_id' => $t->und->id, 'controla_vencimiento' => false]);
    $bidon = $materia('Bidón 5 L');
    $tapa = $materia('Tapa rosca');
    $presentar = function (string $nombre, string $contenido, string $precio, string $porUnidad, array $envases) use ($t): Producto {
        $ficha = Producto::factory()->enUnidad($t->litro)->create(['nombre' => $nombre, 'contenido' => $contenido, 'precio_consumidor' => $precio, 'controla_vencimiento' => true]);

        return app(GuardarProducto::class)->actualizar($t->admin, $ficha->fresh(), [
            'nombre' => $ficha->nombre, 'contenido' => $ficha->contenido, 'tipo_venta' => $ficha->tipo_venta, 'unidad_medida_id' => $ficha->unidad_medida_id,
            'precio_consumidor' => $precio, 'stock_minimo' => '0', 'clase' => $ficha->clase, 'controla_stock' => true, 'controla_vencimiento' => true,
            'presentacion' => ['granel_id' => (string) $t->suavizante->id, 'granel_por_unidad' => $porUnidad, 'envases' => $envases],
        ]);
    };
    $p1 = $presentar('Suavizante floral botella', '1', '15.00', '1', [['producto_id' => $t->botella->id, 'cantidad' => '1'], ['producto_id' => $tapa->id, 'cantidad' => '1']]);
    $p5 = $presentar('Suavizante floral bidón', '5', '55.00', '5', [['producto_id' => $bidon->id, 'cantidad' => '1'], ['producto_id' => $tapa->id, 'cantidad' => '1']]);
    $galon = $presentar('Suavizante floral galón', '4', '45.00', '4', [['producto_id' => $tapa->id, 'cantidad' => '1']]);
    paComprar($t->admin, $t->matriz, [['producto_id' => $bidon->id, 'cantidad' => '4', 'costo' => '4'], ['producto_id' => $tapa->id, 'cantidad' => '10', 'costo' => '0.10']]);
    $orden = paFinalizar($t->admin, paEmitir($t->admin, $t->suavizante, $t->matriz, '50'), '48');
    $t->actingAs($t->admin);
    app(RegistrarEnvasado::class)->ejecutar($t->admin, [
        'sucursal_id' => $t->matriz->id, 'granel_id' => $t->suavizante->id, 'lote_id' => DB::table('lotes')->where('producto_id', $t->suavizante->id)->value('id'),
        'unidades' => [$p1->id => '5', $p5->id => '2'], 'granel_real' => '15', 'envases' => [$t->botella->id => '5', $bidon->id => '2', $tapa->id => '7'],
        'nota' => null, 'clave' => (string) Str::uuid(),
    ]);
    $venta = confirmarVenta($t->admin, crearPedido($t->admin, $t->matriz, [['producto_id' => $p1->id, 'cantidad' => '2'], ['producto_id' => $p5->id, 'cantidad' => '1']],
        ['descuento_global_tipo' => 'bs', 'descuento_global_valor' => '8.50']), ['metodo' => 'efectivo']);
    confirmarVenta($t->admin, crearPedido($t->admin, $t->matriz, [['producto_id' => $t->suavizante->id, 'cantidad' => '3']]), ['metodo' => 'efectivo']);
    app(RegistrarDevolucion::class)->ejecutar($t->admin, $venta->refresh(), [
        'lineas' => [$venta->items()->where('producto_id', $p1->id)->value('id') => ['cantidad' => '1', 'reingresa' => true]], 'motivo' => 'Envase fallado',
        'metodo' => 'efectivo', 'efectivo' => null, 'electronico' => null, 'externo' => true, 'clave' => (string) Str::uuid(),
    ]);

    return ['p1' => $p1->fresh(), 'p5' => $p5->fresh(), 'galon' => $galon->fresh(), 'orden' => $orden];
}

/**
 * Historial del detergente (UC-21 y UC-22 con plan), creado el 1/10 con receta 10 L = 9,5 L de agua + 5 botellas y
 * una presentación de 2 L. Ventana de demanda del 22/10 al 18/11. Casa Matriz compra 12 L y 4 botellas de 2 L el 1/10;
 * vende 4 L + 2 botellas el 25/10, 6 L el 5/11 y 1 botella el 10/11 (quedan 2 L y 1 botella, siempre con stock).
 * Norte compra 2 botellas el 8/11 y las vende el 10/11: 3 días con stock y 25 sin. Hoy, en Casa Matriz: emitida de 2 L
 * (20/11) y aprobada de 1 L (consume 0,95 L de agua y 1 botella).
 *
 * @return array{detergente: Producto, botella: Producto}
 */
function paHistorial(object $t): array
{
    $ir = function (string $fecha) use ($t): void {
        $t->travelTo(CarbonImmutable::parse("{$fecha} 10:00", 'America/La_Paz'));
        $t->actingAs($t->admin);
    };
    $vender = fn (Sucursal $sucursal, array $lineas) => confirmarVenta($t->admin, crearPedido($t->admin, $sucursal, $lineas), ['metodo' => 'efectivo']);
    $ir('2026-10-01');
    $detergente = Producto::factory()->enUnidad($t->litro)->porMedida()->create(['nombre' => 'Detergente', 'precio_consumidor' => '9.00']);
    app(GuardarReceta::class)->ejecutar($t->admin, ['producto_id' => $detergente->id, 'cantidad_base' => '10', 'nota' => null, 'version' => null,
        'lineas' => [['producto_id' => $t->agua->id, 'cantidad' => '9.5'], ['producto_id' => $t->botella->id, 'cantidad' => '5']]]);
    $ficha = Producto::factory()->enUnidad($t->litro)->create(['nombre' => 'Detergente botella', 'contenido' => '2', 'precio_consumidor' => '20.00']);
    $botella = app(GuardarProducto::class)->actualizar($t->admin, $ficha->fresh(), [
        'nombre' => $ficha->nombre, 'contenido' => $ficha->contenido, 'tipo_venta' => $ficha->tipo_venta, 'unidad_medida_id' => $ficha->unidad_medida_id,
        'precio_consumidor' => '20.00', 'stock_minimo' => '0', 'clase' => $ficha->clase, 'controla_stock' => true, 'controla_vencimiento' => false,
        'presentacion' => ['granel_id' => (string) $detergente->id, 'granel_por_unidad' => '2', 'envases' => [['producto_id' => $t->botella->id, 'cantidad' => '1']]],
    ]);
    paComprar($t->admin, $t->matriz, [['producto_id' => $detergente->id, 'cantidad' => '12', 'costo' => '3'], ['producto_id' => $botella->id, 'cantidad' => '4', 'costo' => '7']]);
    $ir('2026-10-25');
    $vender($t->matriz, [['producto_id' => $detergente->id, 'cantidad' => '4'], ['producto_id' => $botella->id, 'cantidad' => '2']]);
    $ir('2026-11-05');
    $vender($t->matriz, [['producto_id' => $detergente->id, 'cantidad' => '6']]);
    $ir('2026-11-08');
    paComprar($t->admin, $t->norte, [['producto_id' => $botella->id, 'cantidad' => '2', 'costo' => '7']]);
    $ir('2026-11-10');
    $vender($t->matriz, [['producto_id' => $botella->id, 'cantidad' => '1']]);
    $vender($t->norte, [['producto_id' => $botella->id, 'cantidad' => '2']]);
    $ir('2026-11-18');
    paEmitir($t->admin, $detergente, $t->matriz, '2');
    app(AprobarOrden::class)->ejecutar($t->admin, paEmitir($t->admin, $detergente, $t->matriz, '1'));
    $t->actingAs($t->admin);

    return ['detergente' => $detergente->fresh(), 'botella' => $botella->fresh()];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00', 'America/La_Paz'));
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->matriz = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Casa Matriz']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Norte']);
    $this->litro = paUnidad($this->empresa, 'Litro', 'L', true);
    $this->kg = paUnidad($this->empresa, 'Kilogramo', 'kg', true);
    $this->und = paUnidad($this->empresa, 'Unidad', 'und.', false);
    $this->suavizante = Producto::factory()->enUnidad($this->litro)->porMedida()
        ->create(['nombre' => 'Suavizante floral', 'controla_vencimiento' => true, 'precio_consumidor' => '12.00']);
    $this->jabon = productoDe($this->empresa, ['nombre' => 'Jabón de reventa', 'precio_consumidor' => '7.50']);
    $proveedor = new Proveedor(['nombre' => 'Químicos del Oriente']);
    $proveedor->empresa_id = $this->empresa->id;
    $proveedor->save();
    $this->vendedor = User::factory()->vendedor($this->matriz)->create(['name' => 'Ana Rojas']);
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
    paComprar($this->admin, $this->matriz, [
        ['producto_id' => $this->fragancia->id, 'cantidad' => '2', 'costo' => '40', 'lote' => 'F-1', 'vencimiento' => '31/03/2028'],
        ['producto_id' => $this->fragancia->id, 'cantidad' => '3', 'costo' => '50', 'lote' => 'F-2', 'vencimiento' => '31/12/2028'],
        ['producto_id' => $this->agua->id, 'cantidad' => '200', 'costo' => '0.50'],
        ['producto_id' => $this->botella->id, 'cantidad' => '10', 'costo' => '1.20'],
        ['producto_id' => $this->jabon->id, 'cantidad' => '20', 'costo' => '5'],
    ]);
});

test('7a: productos and stock never list raw materials, for the admin or the seller', function () {
    $nombres = array_column(paConsulta($this->admin, ['source' => 'productos', 'fields' => ['nombre']])['filas'], 'nombre');
    $stock = array_column(paConsulta($this->vendedor, ['source' => 'stock', 'fields' => ['producto']])['filas'], 'producto');
    $sinStock = paConsulta($this->admin, ['source' => 'stock', 'measures' => ['sin_stock', 'productos']])['filas'];

    // Sin stock: el suavizante en las dos sucursales y el jabón en Norte; las materias primas no cuentan.
    expect($nombres)->toBe(['Jabón de reventa', 'Suavizante floral'])
        ->and($stock)->toBe(['Jabón de reventa', 'Suavizante floral'])
        ->and($sinStock)->toBe([['sin_stock' => 3, 'productos' => 2]]);
});

test('7a: production sources and tools exist only for the admin with the capability', function () {
    [$otra, $adminOtra] = empresaConAdministrador();
    $this->actingAs($adminOtra);
    Sucursal::factory()->create(['empresa_id' => $otra->id, 'nombre' => 'Única']);
    $nuevas = ['estimate_production_capacity', 'review_production_orders', 'analyze_production_yield', 'review_expiring_lots', 'trace_lot'];

    $this->actingAs($this->admin);
    expect(array_keys(app(RegistroHerramientas::class)->disponibles($this->admin)))->toContain(...$nuevas)
        ->and(CatalogoDatos::paraDescribir(true, ContextoAgente::capacidadesDe($this->admin))->fuentesPermitidas())
        ->toBe([...CatalogoDatos::FUENTES, ...CatalogoDatos::FUENTES_PRODUCCION, 'lotes']);

    // El vendedor: sin herramientas ni fuentes de producción; ve lotes (su sucursal, sin costos ni materias primas).
    $this->actingAs($this->vendedor);
    expect(array_keys(app(RegistroHerramientas::class)->disponibles($this->vendedor)))->toBe(['query_business_data', 'review_cash_shifts', 'analyze_pending_orders'])
        ->and(paLlamar($this->vendedor, 'query_business_data', ['source' => 'materias_primas'])['error'])->toContain('Producción activa')
        ->and(paLlamar($this->vendedor, 'estimate_production_capacity')['error'])->toContain('no tiene permiso')
        ->and(paLlamar($this->vendedor, 'trace_lot', ['lote' => 'F-1'])['error'])->toContain('no tiene permiso')
        ->and(paConsulta($this->vendedor, ['source' => 'lotes', 'fields' => ['producto']])['filas'])->toBe([]);

    // Otra empresa sin la función: ni fuentes ni herramientas, aunque las llame por nombre.
    $this->actingAs($adminOtra);
    expect(array_intersect(array_keys(app(RegistroHerramientas::class)->disponibles($adminOtra)), $nuevas))->toBe([])
        ->and(paLlamar($adminOtra, 'query_business_data', ['source' => 'recetas'])['error'])->toContain('Producción activa')
        ->and(paLlamar($adminOtra, 'query_business_data', ['source' => 'lotes'])['error'])->toContain('Lotes y vencimientos')
        ->and(paLlamar($adminOtra, 'review_production_orders')['error'])->toContain('no tiene permiso');
});

test('7a: every production and lot source compiles in MySQL, listing and grouping by each field', function () {
    paFinalizar($this->admin, paEmitir($this->admin, $this->suavizante, $this->matriz, '50'), '48');
    paEmitir($this->admin, $this->suavizante, $this->norte, '10');
    $this->actingAs($this->admin);
    $catalogo = CatalogoDatos::paraDescribir(true, ContextoAgente::capacidadesDe($this->admin));

    foreach ([...CatalogoDatos::FUENTES_PRODUCCION, 'lotes', 'productos', 'productos_vendidos', 'productos_comprados', 'movimientos_inventario'] as $nombre) {
        $fuente = $catalogo->fuente($nombre);
        $campos = array_map(fn ($c) => $c->nombre, $fuente->visibles(true, false));
        $medidas = array_map(fn ($c) => $c->nombre, $fuente->visibles(true, true));

        foreach (array_chunk($campos, 20) as $grupo) {
            $resultado = paConsulta($this->admin, ['source' => $nombre, 'fields' => $grupo, 'limit' => 5]);
            expect(array_column($resultado['columnas'], 'field'))->toBe($grupo);
            app(ValidadorLienzo::class)->dataset(new DatasetPropuesto('d1', $resultado['columnas'], $resultado['filas'], []));
        }

        foreach ($campos as $campo) {
            foreach (array_chunk($medidas, 6) as $lote) {
                $resultado = paConsulta($this->admin, ['source' => $nombre, 'group_by' => [$campo], 'measures' => $lote, 'limit' => 5]);
                expect(array_column($resultado['columnas'], 'field'))->toBe([$campo, ...$lote]);
            }
        }

        expect(paConsulta($this->admin, ['source' => $nombre, 'measures' => ['conteo']])['filas'])->toHaveCount(1);
    }

    $origen = paConsulta($this->admin, ['source' => 'productos', 'fields' => ['nombre', 'origen', 'costo_materiales']])['filas'];
    expect($origen)->toBe([
        ['nombre' => 'Jabón de reventa', 'origen' => 'reventa', 'costo_materiales' => null],
        // (47,5 × 0,50 + 1,25 × 46 + 2 × 1,20) / 48 = 1,742708…
        ['nombre' => 'Suavizante floral', 'origen' => 'fabricado', 'costo_materiales' => '1.74'],
    ]);
});

test('7a: produccion and consumos_produccion add up exactly like Reportes de producción; emitted orders are not production', function () {
    paFinalizar($this->admin, paEmitir($this->admin, $this->suavizante, $this->matriz, '50'), '48', [$this->fragancia->id => '1']);
    paFinalizar($this->admin, paEmitir($this->admin, $this->suavizante, $this->matriz, '10'), '10');
    paEmitir($this->admin, $this->suavizante, $this->matriz, '30');
    $periodo = Periodo::rango('2026-11-01', '2026-11-30');
    $reporte = ReporteProduccion::resumen($this->empresa->id, $periodo);
    $porProducto = ReporteProduccion::porProducto($this->empresa->id, $periodo)->get()->first();
    $rango = ['date_from' => '2026-11-01', 'date_to' => '2026-11-30'];

    $produccion = paConsulta($this->admin, ['source' => 'produccion', 'measures' => ['conteo', 'cantidad_planificada', 'cantidad_obtenida', 'rendimiento_total', 'costo_produccion', 'con_merma'], ...$rango])['filas'][0];
    $ordenes = paConsulta($this->admin, ['source' => 'ordenes_produccion', 'measures' => ['conteo', 'emitidas', 'finalizadas'], ...$rango])['filas'][0];
    $consumos = paConsulta($this->admin, ['source' => 'consumos_produccion', 'group_by' => ['materia_prima'], 'measures' => ['teorico_total', 'usado_total', 'costo_total'], ...$rango])['filas'];
    $materias = ReporteProduccion::materias($this->empresa->id, $periodo)->get()->keyBy('nombre');

    expect($produccion)->toBe([
        'conteo' => $reporte['ordenes'], 'cantidad_planificada' => '60.000', 'cantidad_obtenida' => '58.000',
        'rendimiento_total' => ReporteProduccion::rendimiento((string) $porProducto->planificada, (string) $porProducto->obtenida),
        'costo_produccion' => $reporte['costo'], 'con_merma' => $reporte['con_merma'],
    ])->and($ordenes)->toBe(['conteo' => 3, 'emitidas' => 1, 'finalizadas' => 2])
        ->and($consumos)->toHaveCount(3);

    foreach ($consumos as $fila) {
        $m = $materias[$fila['materia_prima']];
        expect($fila['usado_total'])->toBe(ConsultaDatos::valor($m->usada, 'number'))
            ->and($fila['teorico_total'])->toBe(ConsultaDatos::valor($m->teorica, 'number'))
            ->and($fila['costo_total'])->toBe(ConsultaDatos::valor($m->costo, 'money'));
    }
});

test('UC-19: the limiting raw material, expired lots do not count and shortages match the order form', function () {
    paEmitir($this->admin, $this->suavizante, $this->matriz, '100');
    $r = paHerramienta($this->admin, 'estimate_production_capacity', ['producto' => 'suavizante']);
    $matriz = paFila($r['capacidad'], 'sucursal', 'Casa Matriz');

    // Agua 200 → 210,526 L; fragancia 5 kg → 200 L; botellas 10 → 333,333 L. Con la orden emitida de 100 L: fragancia 2,5 → 100 L.
    expect($matriz)->toMatchArray(['producto' => 'Suavizante floral', 'maximo' => '200.000', 'limita' => 'Fragancia floral',
        'maximo_tras_emitidas' => '100.000', 'estado' => 'se puede producir'])
        ->and(paFila($r['capacidad'], 'sucursal', 'Norte'))->toMatchArray(['maximo' => '0.000', 'estado' => 'sin materia prima suficiente'])
        ->and(paFila(array_values(array_filter($r['lineas'], fn ($l) => $l['sucursal'] === 'Casa Matriz')), 'materia_prima', 'Fragancia floral'))
        ->toMatchArray(['utilizable' => '5.000', 'pedido_emitidas' => '2.500', 'alcanza_para' => '200.000', 'limita' => 'sí']);

    // El lote F-1 vence: no cuenta (M3). Para 300 L faltan agua y fragancia como en el aviso de Nueva orden.
    DB::table('lotes')->where('producto_id', $this->fragancia->id)->where('numero', 'F-1')->update(['vencimiento' => '2026-11-10']);
    $r = paHerramienta($this->admin, 'estimate_production_capacity', ['producto' => 'Suavizante floral', 'cantidad' => '300', 'sucursal' => 'casa matriz']);
    $teoricas = [$this->agua->id => '285.000', $this->fragancia->id => '7.500', $this->botella->id => '9.000'];
    $aviso = app(DisponibilidadMaterias::class)->faltantes($this->empresa->id, $this->matriz->id, $teoricas);
    $lineas = collect($r['lineas'])->keyBy('materia_prima');

    expect($r['capacidad'])->toHaveCount(1)
        ->and($r['capacidad'][0])->toMatchArray(['maximo' => '120.000', 'limita' => 'Fragancia floral', 'cantidad_pedida' => '300.000',
            'alcanza_pedido' => 'no', 'lineas_con_faltante' => 2])
        ->and($lineas['Fragancia floral'])->toMatchArray(['existencia' => '5.000', 'vencido' => '2.000', 'utilizable' => '3.000', 'necesario' => '7.500', 'faltante' => '4.500'])
        ->and($lineas['Agua desmineralizada']['faltante'])->toBe('85.000')
        ->and($lineas['Botella 1 L']['faltante'])->toBe('0.000')
        ->and($aviso[$this->fragancia->id]['falta'])->toBe('4.500')
        ->and($aviso[$this->agua->id]['falta'])->toBe('85.000')
        ->and(isset($aviso[$this->botella->id]))->toBeFalse()
        ->and(paLlamar($this->admin, 'estimate_production_capacity', ['producto' => 'Agua'])['error'])->toContain('No encontré')
        ->and(paLlamar($this->admin, 'estimate_production_capacity', ['cantidad' => '10'])['error'])->toContain('requiere producto');
});

test('UC-20: two emitted orders that fit separately but not together are flagged in order; an approved one does not ask again', function () {
    $atrasada = paEmitir($this->admin, $this->suavizante, $this->norte, '10', '2026-11-10');
    $a = paEmitir($this->admin, $this->suavizante, $this->matriz, '150', '2026-11-19');
    $b = paEmitir($this->admin, $this->suavizante, $this->matriz, '100', '2026-11-20');
    paEmitir($this->admin, $this->suavizante, $this->matriz, '10', '2026-12-15');
    $r = paHerramienta($this->admin, 'review_production_orders');

    // A: agua 142,5 + fragancia 3,75 + 5 botellas; B: 95 + 2,5 + 3. Juntas: agua 237,5 > 200 y fragancia 6,25 > 5.
    expect(array_column($r['ordenes'], 'orden'))->toBe([$atrasada->codigo(), $a->codigo(), $b->codigo()])
        ->and($r['ordenes'][0])->toMatchArray(['dias_atraso' => 8, 'alcanza_sola' => 'no', 'alcanza_en_orden' => 'no'])
        ->and($r['ordenes'][1])->toMatchArray(['alcanza_sola' => 'sí', 'alcanza_en_orden' => 'sí'])
        ->and($r['ordenes'][2])->toMatchArray(['alcanza_sola' => 'sí', 'alcanza_en_orden' => 'no', 'falta' => 'Agua desmineralizada, Fragancia floral'])
        ->and(paFila(array_values(array_filter($r['faltantes'], fn ($f) => $f['sucursal'] === 'Casa Matriz')), 'materia_prima', 'Agua desmineralizada'))
        ->toMatchArray(['requerido' => '237.500', 'utilizable' => '200.000', 'faltante' => '37.500', 'primera_orden_sin_stock' => $b->codigo()])
        ->and($r['resumen'][0])->toMatchArray(['emitidas' => 3, 'aprobadas' => 0, 'atrasadas' => 1, 'emitidas_sin_materia_prima' => 2]);

    // Aprobada A: ya descontó (142,5 × 0,50 + 3,75 × 46 + 5 × 1,20 = 249,75) y B ya no alcanza ni sola.
    app(AprobarOrden::class)->ejecutar($this->admin, $a);
    $r = paHerramienta($this->admin, 'review_production_orders');

    expect(paFila($r['ordenes'], 'orden', $a->codigo()))->toMatchArray(['estado' => 'aprobada', 'alcanza_sola' => null, 'dias_desde_aprobacion' => 0, 'valor_materia_descontada' => '249.75'])
        ->and(paFila($r['ordenes'], 'orden', $b->codigo()))->toMatchArray(['alcanza_sola' => 'no', 'alcanza_en_orden' => 'no'])
        ->and($r['resumen'][0])->toMatchArray(['emitidas' => 2, 'aprobadas' => 1, 'valor_descontado_aprobadas' => '249.75']);
});

test('UC-23: yield totals match Reportes de producción and the out-of-pattern order comes with its note', function () {
    paFinalizar($this->admin, paEmitir($this->admin, $this->suavizante, $this->matriz, '40'), '40');
    paFinalizar($this->admin, paEmitir($this->admin, $this->suavizante, $this->matriz, '40'), '39.2');
    $tercera = paFinalizar($this->admin, paEmitir($this->admin, $this->suavizante, $this->matriz, '40', nota: 'Se derramó un balde'), '36', [$this->agua->id => '40']);
    $r = paHerramienta($this->admin, 'analyze_production_yield');
    $reporte = ReporteProduccion::resumen($this->empresa->id, Periodo::rango('2026-11-01', '2026-11-18'));

    // Rendimiento del producto 115,2 / 120 = 96 %; la tercera rinde 90 % (−6 puntos > 5): fuera de patrón.
    expect($r['resumen'][0])->toMatchArray(['ordenes' => $reporte['ordenes'], 'rendimiento' => '96.0', 'ordenes_con_merma' => $reporte['con_merma'],
        'ordenes_fuera_de_patron' => 1, 'costo_materiales' => $reporte['costo'], 'costo_usado_de_mas' => '1.00', 'confianza' => 'normal'])
        ->and($r['ordenes'][0])->toMatchArray(['orden' => $tercera->codigo(), 'rendimiento' => '90.0', 'rendimiento_producto' => '96.0',
            'diferencia_puntos' => '-6.000', 'fuera_de_patron' => 'sí', 'nota' => 'Se derramó un balde'])
        ->and(paFila($r['ordenes'], 'rendimiento', '98.0')['fuera_de_patron'])->toBe('no')
        ->and(paFila($r['desvios'], 'materia_prima', 'Agua desmineralizada'))->toMatchArray(['teorico' => '114.000', 'usado' => '116.000', 'diferencia' => '2.000', 'costo_diferencia' => '1.00'])
        ->and($r['por_mes'])->toBe([['mes' => '2026-11', 'ordenes' => 3, 'planificado' => '120.000', 'obtenido' => '115.200', 'rendimiento' => '96.0', 'costo_total' => $reporte['costo']]]);
});

test('UC-27: expiring lots match Vencimientos and trace_lot follows a produced lot back to its raw material lot', function () {
    DB::table('lotes')->where('producto_id', $this->fragancia->id)->where('numero', 'F-1')->update(['vencimiento' => '2026-12-01']);
    $r = paHerramienta($this->admin, 'review_expiring_lots', ['clase' => 'materia_prima']);

    expect($r['lotes'])->toHaveCount(ReporteVencimientos::consulta($this->empresa->id, ['estado' => '30'])->count())
        ->and($r['lotes'][0])->toMatchArray(['producto' => 'Fragancia floral', 'clase' => 'materia prima', 'lote' => 'F-1', 'dias_para_vencer' => 13,
            'estado' => 'vence en 30 días', 'sucursal' => 'Casa Matriz', 'cantidad' => '2.000', 'valor_costo' => '92.00'])
        ->and($r['usos'][0])->toMatchArray(['materia_prima' => 'Fragancia floral', 'lote' => 'F-1', 'producto' => 'Suavizante floral', 'alcanza_para' => '80.000'])
        ->and($r['resumen'][0])->toMatchArray(['lotes_vencidos' => 0, 'lotes_por_vencer' => 1, 'valor_por_vencer' => '92.00']);

    $orden = paFinalizar($this->admin, paEmitir($this->admin, $this->suavizante, $this->matriz, '50'), '48');
    $t = paHerramienta($this->admin, 'trace_lot', ['lote' => 'op-1']);

    expect($t['lotes'])->toHaveCount(1)
        ->and($t['lotes'][0])->toMatchArray(['producto' => 'Suavizante floral', 'lote' => $orden->codigo(), 'stock_actual' => '48.000'])
        ->and(array_column($t['movimientos'], 'documento'))->toContain($orden->codigo())
        ->and($t['origen'])->toHaveCount(1)
        ->and($t['origen'][0])->toMatchArray(['orden' => $orden->codigo(), 'materia_prima' => 'Fragancia floral', 'lote_materia' => 'F-1', 'cantidad_consumida' => '1.250'])
        ->and(paLlamar($this->admin, 'trace_lot', ['lote' => 'X-999'])['error'])->toContain('No hay ningún lote');
});

test('7a: company A never sees company B production data or lots, even with the same lot number', function () {
    [$otra, $adminOtra] = empresaConAdministrador();
    $this->actingAs($adminOtra);
    $sucursalOtra = Sucursal::factory()->create(['empresa_id' => $otra->id, 'nombre' => 'Única']);
    app(CambiarFuncionesEmpresa::class)->ejecutar($adminOtra, $otra, Rubro::General, [Capacidad::Produccion]);
    $kg = paUnidad($otra, 'Kilogramo', 'kg', true);
    $esencia = app(GuardarMateriaPrima::class)->crear($adminOtra, ['nombre' => 'Esencia B', 'unidad_medida_id' => $kg->id, 'controla_vencimiento' => true]);
    $proveedor = new Proveedor(['nombre' => 'Proveedor B']);
    $proveedor->empresa_id = $otra->id;
    $proveedor->save();
    app(RegistrarCompra::class)->ejecutar($adminOtra, [
        'sucursal_id' => $sucursalOtra->id, 'proveedor_id' => $proveedor->id, 'referencia' => null, 'metodo' => 'efectivo', 'efectivo' => null,
        'electronico' => null, 'externo' => true, 'clave' => (string) Str::uuid(),
        'lineas' => [['producto_id' => $esencia->id, 'cantidad' => '1', 'costo' => '10', 'lote' => 'F-1', 'vencimiento' => '31/12/2026']],
    ]);

    expect(array_column(paConsulta($adminOtra, ['source' => 'materias_primas', 'fields' => ['materia_prima']])['filas'], 'materia_prima'))->toBe(['Esencia B'])
        ->and(paConsulta($adminOtra, ['source' => 'recetas', 'measures' => ['conteo']])['filas'][0]['conteo'])->toBe(0)
        ->and(array_column(paHerramienta($adminOtra, 'trace_lot', ['lote' => 'F-1'])['lotes'], 'producto'))->toBe(['Esencia B'])
        ->and(array_column(paHerramienta($this->admin, 'trace_lot', ['lote' => 'F-1'])['lotes'], 'producto'))->toBe(['Fragancia floral'])
        ->and(paLlamar($adminOtra, 'estimate_production_capacity', ['producto' => 'Suavizante floral'])['error'])->toContain('No encontré');
});

test('7a: the system instructions list only the available tools and add the production rules only with the capability', function () {
    $texto = function (User $usuario): string {
        $this->actingAs($usuario);

        return app(InstruccionesSistema::class)->texto(ContextoAgente::desde($usuario), false, array_keys(app(RegistroHerramientas::class)->disponibles($usuario)));
    };
    [, $adminOtra] = empresaConAdministrador();

    expect($texto($this->admin))->toContain('estimate_production_capacity (', 'trace_lot (', 'Producción (función activa', 'costo de materiales', 'Lotes y vencimientos (función activa')
        ->and($texto($this->vendedor))->not->toContain('estimate_production_capacity', 'analyze_receivables', 'Producción (función activa')
        ->and($texto($this->vendedor))->toContain('review_cash_shifts (sus turnos y su caja)', 'Lotes y vencimientos (función activa')
        ->and($texto($adminOtra))->toContain('analyze_receivables (')->not->toContain('estimate_production_capacity', 'Producción (función activa', 'Lotes y vencimientos (función activa')
        ->and(Panel::sugerenciasProduccion(true)[4])->toBe(Panel::SUGERENCIA_VENCIMIENTOS)
        ->and(Panel::sugerenciasProduccion(false))->toBe(Panel::SUGERENCIAS_IA_PRODUCCION);
});

test('7b: the cost, purchase, margin and packaging tools exist only for the admin with the capability', function () {
    $nuevas = ['suggest_raw_material_purchase', 'analyze_production_cost', 'analyze_manufactured_margin', 'plan_packaging'];
    [, $adminOtra] = empresaConAdministrador();

    $this->actingAs($this->admin);
    expect(array_keys(app(RegistroHerramientas::class)->disponibles($this->admin)))->toContain(...$nuevas)
        ->and(app(InstruccionesSistema::class)->texto(ContextoAgente::desde($this->admin), false, $nuevas))
        ->toContain('suggest_raw_material_purchase (', 'plan_packaging (', 'margen sobre materiales', 'no se suman');

    foreach ($nuevas as $nombre) {
        expect(paLlamar($this->vendedor, $nombre, $nombre === 'analyze_production_cost' ? ['producto' => 'Suavizante floral'] : [])['error'])->toContain('no tiene permiso');
    }

    $this->actingAs($adminOtra);
    expect(array_intersect(array_keys(app(RegistroHerramientas::class)->disponibles($adminOtra)), $nuevas))->toBe([])
        ->and(paLlamar($adminOtra, 'plan_packaging')['error'])->toContain('no tiene permiso');
});

test('UC-22: without orders it repeats Sugerencia de compra; with emitted orders it buys need + minimum − usable, priced at the last purchase', function () {
    DB::table('productos')->where('id', $this->agua->id)->update(['stock_minimo' => '50', 'stock_maximo' => '280']);
    DB::table('productos')->where('id', $this->botella->id)->update(['stock_minimo' => '12', 'stock_maximo' => '30']);
    $r = paHerramienta($this->admin, 'suggest_raw_material_purchase');
    $sucursal = fn (array $filas, string $nombre) => array_column(array_values(array_filter($filas, fn ($f) => $f['sucursal'] === $nombre)), 'sugerido_minimo_maximo', 'materia_prima');

    // Sin órdenes: lo mismo que Producción › Sugerencia de compra en cada sucursal, y nada «para las órdenes».
    foreach ([$this->matriz, $this->norte] as $s) {
        $pantalla = ListadoMateriasPrimas::consulta($this->empresa->id, $s->id, '', 'sugerencia')->get()
            ->mapWithKeys(fn (Producto $m) => [$m->nombre => ListadoMateriasPrimas::sugerido((string) $m->stock, (string) $m->minimo, $m->stock_maximo === null ? null : (string) $m->stock_maximo)])->all();
        expect($sucursal($r['compra'], $s->nombre))->toBe($pantalla);
    }

    expect($sucursal($r['compra'], 'Casa Matriz'))->toBe(['Botella 1 L' => '20.000'])
        ->and($sucursal($r['compra'], 'Norte'))->toBe(['Agua desmineralizada' => '280.000', 'Botella 1 L' => '30.000'])
        ->and(array_unique(array_column($r['compra'], 'a_comprar')))->toBe(['0.000'])
        // 20 × 1,20 + 280 × 0,50 + 30 × 1,20 = 200
        ->and($r['resumen'][0])->toMatchArray(['ordenes_emitidas' => 0, 'materias_a_comprar' => 0, 'presupuesto_estimado' => '0.00', 'materias_bajo_minimo' => 3, 'presupuesto_sugerencia' => '200.00']);

    // Emitidas: A 150 L (19/11) y B 100 L (20/11) en Casa Matriz, una atrasada de 10 L en Norte y una fuera del horizonte.
    paEmitir($this->admin, $this->suavizante, $this->matriz, '150', '2026-11-19');
    paEmitir($this->admin, $this->suavizante, $this->matriz, '100', '2026-11-20');
    paEmitir($this->admin, $this->suavizante, $this->norte, '10', '2026-11-10');
    paEmitir($this->admin, $this->suavizante, $this->matriz, '500', '2026-12-15');
    DB::table('lotes')->where('producto_id', $this->fragancia->id)->where('numero', 'F-1')->update(['vencimiento' => '2026-11-10']);
    $r = paHerramienta($this->admin, 'suggest_raw_material_purchase');
    $fila = fn (string $s, string $m) => paFila(array_values(array_filter($r['compra'], fn ($f) => $f['sucursal'] === $s)), 'materia_prima', $m);

    // Casa Matriz: agua 237,5 + 50 − 200 = 87,5 (200 + 87,5 > 280: supera el máximo); fragancia 6,25 − (5 − 2 vencidos) = 3,25
    // a Bs 50 (la última compra); botellas 5 + 3 + 12 − 10 = 10. Norte: agua 9,5 + 50; fragancia 0,25; botellas 1 + 12.
    expect($fila('Casa Matriz', 'Agua desmineralizada'))->toMatchArray(['necesidad' => '237.500', 'ordenes' => 2, 'utilizable' => '200.000', 'minimo' => '50.000',
        'a_comprar' => '87.500', 'supera_maximo' => 'sí', 'ultimo_proveedor' => 'Químicos del Oriente', 'fecha_ultima_compra' => '2026-11-18',
        'costo_estimado' => '0.50', 'origen_costo' => 'última compra', 'presupuesto' => '43.75'])
        ->and($fila('Casa Matriz', 'Fragancia floral'))->toMatchArray(['necesidad' => '6.250', 'existencia' => '5.000', 'vencido' => '2.000', 'utilizable' => '3.000',
            'a_comprar' => '3.250', 'costo_estimado' => '50.00', 'presupuesto' => '162.50'])
        ->and($fila('Casa Matriz', 'Botella 1 L'))->toMatchArray(['necesidad' => '8.000', 'a_comprar' => '10.000', 'supera_maximo' => 'no', 'sugerido_minimo_maximo' => '20.000', 'presupuesto' => '12.00'])
        ->and($fila('Norte', 'Agua desmineralizada'))->toMatchArray(['necesidad' => '9.500', 'a_comprar' => '59.500', 'presupuesto' => '29.75'])
        ->and($fila('Norte', 'Fragancia floral'))->toMatchArray(['a_comprar' => '0.250', 'presupuesto' => '12.50'])
        ->and($fila('Norte', 'Botella 1 L'))->toMatchArray(['a_comprar' => '13.000', 'presupuesto' => '15.60'])
        // 43,75 + 162,50 + 12 + 29,75 + 12,50 + 15,60 = 276,10
        ->and($r['resumen'][0])->toMatchArray(['ordenes_emitidas' => 3, 'materias_a_comprar' => 6, 'presupuesto_estimado' => '276.10', 'compras_sobre_maximo' => 1, 'materias_sin_costo' => 0])
        ->and($r['por_proveedor'])->toBe([['proveedor' => 'Químicos del Oriente', 'materias' => 6, 'presupuesto' => '276.10']])
        ->and(paLlamar($this->admin, 'suggest_raw_material_purchase', ['horizon_days' => 90])['error'])->not->toBeNull();

    // Con un horizonte de 30 días entra también la orden del 15/12.
    expect(paHerramienta($this->admin, 'suggest_raw_material_purchase', ['horizon_days' => 30])['resumen'][0]['ordenes_emitidas'])->toBe(4);
});

test('UC-24: unit cost per order matches its detail, the change splits into price and quantity, and a simulation changes nothing', function () {
    // 1.ª mitad (5/11): 40 L → 40 L con lo teórico. 2.ª mitad (18/11): la fragancia sube a Bs 52 y la orden de 40 L rinde 38 L con 40 L de agua.
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'America/La_Paz'));
    $primera = paFinalizar($this->admin, paEmitir($this->admin, $this->suavizante, $this->matriz, '40'), '40');
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00', 'America/La_Paz'));
    $this->actingAs($this->admin);
    paComprar($this->admin, $this->matriz, [['producto_id' => $this->fragancia->id, 'cantidad' => '3', 'costo' => '60', 'lote' => 'F-3', 'vencimiento' => '31/12/2028']]);
    $segunda = paFinalizar($this->admin, paEmitir($this->admin, $this->suavizante, $this->matriz, '40'), '38', [$this->agua->id => '40']);
    $rango = ['producto' => 'suavizante', 'date_from' => '2026-11-01', 'date_to' => '2026-11-18'];
    $r = paHerramienta($this->admin, 'analyze_production_cost', $rango);
    $fragancia = paFila($r['materias'], 'materia_prima', 'Fragancia floral');

    // Costos: 38 × 0,50 + 46 + 2 × 1,20 = 67,40 y 40 × 0,50 + 52 + 2,40 = 74,40 (74,40 / 38 = 1,957895). Receta hoy: (47,5 + 2,5 × 52 + 3,6) / 100 = 1,811.
    expect(DB::table('productos')->where('id', $this->fragancia->id)->value('costo_promedio'))->toBe('52.000000')
        ->and(array_column($r['ordenes'], 'orden'))->toBe([$primera->codigo(), $segunda->codigo()])
        ->and(array_column($r['ordenes'], 'costo_unitario_exacto'))->toBe([(string) $primera->fresh()->costo_unitario, (string) $segunda->fresh()->costo_unitario])
        ->and($r['ordenes'][1])->toMatchArray(['costo_total' => '74.40', 'costo_unitario' => '1.96', 'costo_unitario_exacto' => '1.957895'])
        ->and($r['resumen'][0])->toMatchArray(['ordenes' => 2, 'producido' => '78.000', 'costo_total' => '141.80', 'costo_unitario_periodo' => '1.82',
            'ultimo_costo_unitario_exacto' => '1.957895', 'costo_receta_hoy' => '1.81', 'costo_receta_hoy_exacto' => '1.811000',
            'diferencia_receta_vs_ultimo' => '-0.15', 'materias_sin_costo' => 0, 'costo_receta_simulado' => null])
        // Fragancia por litro: 1/40 a Bs 46 = 1,15 → 1/38 a Bs 52 = 1,37. Precio (52 − 46) × 0,026315 = 0,16; cantidad 0,001315 × 46 = 0,06.
        ->and($fragancia)->toMatchArray(['peso_pct' => '69.1', 'cantidad_por_unidad_1' => '0.025', 'cantidad_por_unidad_2' => '0.026', 'costo_unitario_1' => '46.00',
            'costo_unitario_2' => '52.00', 'costo_por_unidad_1' => '1.15', 'costo_por_unidad_2' => '1.37', 'cambio_por_unidad' => '0.22', 'efecto_precio' => '0.16', 'efecto_cantidad' => '0.06'])
        ->and(paFila($r['materias'], 'materia_prima', 'Agua desmineralizada'))->toMatchArray(['efecto_precio' => '0.00', 'efecto_cantidad' => '0.05', 'cambio_por_unidad' => '0.05'])
        ->and($r['compras'][0])->toMatchArray(['materia_prima' => 'Fragancia floral', 'cantidad' => '3.000', 'costo_unitario' => '60.00', 'costo_promedio_actual' => '52.000000'])
        ->and($r['compras'])->toHaveCount(5);

    // Simulación: fragancia +10 % → 1,30 × 1,1 = 1,43; receta 1,941 (+7,2 %). No cambia el costo promedio.
    $s = paHerramienta($this->admin, 'analyze_production_cost', $rango + ['simular' => [['materia_prima' => 'fragancia', 'variacion_pct' => '10']]]);

    expect($s['resumen'][0])->toMatchArray(['costo_receta_hoy_exacto' => '1.811000', 'costo_receta_simulado' => '1.94', 'variacion_simulada_pct' => '7.2'])
        ->and(paFila($s['receta'], 'materia_prima', 'Fragancia floral'))->toMatchArray(['costo_promedio' => '52.000000', 'costo_por_unidad' => '1.30', 'variacion_pct' => '10.0', 'costo_por_unidad_simulado' => '1.43'])
        ->and(DB::table('productos')->where('id', $this->fragancia->id)->value('costo_promedio'))->toBe('52.000000')
        ->and(paLlamar($this->admin, 'analyze_production_cost', $rango + ['simular' => [['materia_prima' => 'Glicerina', 'variacion_pct' => '10']]])['error'])->toContain('no está en la receta')
        ->and(paLlamar($this->admin, 'analyze_production_cost', ['producto' => 'Jabón de reventa'])['error'])->toContain('No encontré')
        ->and(paLlamar($this->admin, 'analyze_production_cost')['error'])->not->toBeNull();
});

test('UC-25: margin over materials with list and net price, global discount and returns like Reportes › Ventas, per bulk unit', function () {
    ['p1' => $p1, 'p5' => $p5, 'galon' => $galon] = paPresentaciones($this);
    $r = paHerramienta($this->admin, 'analyze_manufactured_margin');

    // Costos: granel 83,65 / 48 = 1,742708; botella 1,742708 + 1,20 + 0,10 = 3,042708; bidón 5 × 1,742708 + 4 + 0,10 = 12,81354.
    expect((string) $p1->costo_promedio)->toBe('3.042708')
        ->and(array_column($r['margenes'], 'producto'))->not->toContain('Jabón de reventa')
        ->and(paFila($r['margenes'], 'producto', 'Suavizante floral'))->toMatchArray(['origen' => 'fabricado', 'granel' => 'Suavizante floral', 'granel_por_unidad' => '1.000',
            'precio_lista' => '12.00', 'costo_materiales' => '1.74', 'margen_lista' => '10.26', 'margen_lista_pct' => '85.5', 'unidades_netas' => '3.000',
            'venta_neta' => '36.00', 'precio_neto' => '12.00', 'margen_periodo' => '30.77'])
        // Venta de 2 botellas por Bs 27,00 netos (descuento global repartido) y 1 devuelta por Bs 13,50.
        ->and(paFila($r['margenes'], 'producto', $p1->nombre))->toMatchArray(['origen' => 'presentación', 'costo_materiales' => '3.04',
            'margen_lista' => '11.96', 'unidades_netas' => '1.000', 'venta_neta' => '13.50', 'precio_neto' => '13.50', 'margen_neto' => '10.46', 'margen_neto_por_unidad_granel' => '10.46'])
        // Bidón: Bs 55 / 5 L = 11 por litro; neto 49,50 (55 − 5,50 de descuento).
        ->and(paFila($r['margenes'], 'producto', $p5->nombre))->toMatchArray(['granel_por_unidad' => '5.000', 'precio_lista' => '55.00',
            'costo_materiales' => '12.81', 'margen_lista' => '42.19', 'precio_lista_por_unidad_granel' => '11.00', 'margen_lista_por_unidad_granel' => '8.44',
            'venta_neta' => '49.50', 'precio_neto' => '49.50', 'margen_neto' => '36.69', 'margen_neto_por_unidad_granel' => '7.34', 'estado' => 'calculado (estimado)'])
        ->and(paFila($r['margenes'], 'producto', $galon->nombre))->toMatchArray(['costo_materiales' => null, 'margen_lista' => null,
            'estado' => 'no calculable: costo 0 (todavía sin producir ni envasar)'])
        ->and($r['resumen'][0])->toMatchArray(['productos' => 4, 'con_ventas' => 3, 'no_calculables' => 1, 'venta_neta' => '99.00']);
});

test('UC-26: packaging maxima use the same rounding as Nuevo envasado, coverage uses 28 days of net sales and expired bulk does not count', function () {
    ['p1' => $p1, 'p5' => $p5, 'galon' => $galon, 'orden' => $orden] = paPresentaciones($this);
    $r = paHerramienta($this->admin, 'plan_packaging', ['sucursal' => 'Casa Matriz']);
    $bidon = paFila($r['presentaciones'], 'presentacion', $p5->nombre);
    $composicion = Presentaciones::composicion($this->empresa->id, $this->suavizante->id, [$p5->id]);

    // Granel 30 L, botellas 3, bidones 2, tapas 3. Bidón: min(30 / 5, 2, 3) = 2 (limita el bidón); cobertura 1 × 28 / 1 = 28 días.
    expect(array_column($r['presentaciones'], 'presentacion'))->toBe([$p5->nombre, $p1->nombre, $galon->nombre])
        ->and($r['graneles'])->toBe([['granel' => 'Suavizante floral', 'sucursal' => 'Casa Matriz', 'unidad' => 'Litro', 'existencia' => '30.000', 'vencido' => '0.000',
            'utilizable' => '30.000', 'presentaciones' => 3, 'lote_sugerido' => $orden->codigo(), 'vencimiento_lote' => '2027-05-18', 'cantidad_lote' => '30.000']])
        ->and($bidon)->toMatchArray(['stock' => '1.000', 'venta_diaria' => '0.035', 'cobertura_dias' => '28.000', 'maximo_unidades' => '2.000', 'limita' => 'Bidón 5 L', 'granel_necesario' => '10.000'])
        ->and($bidon['granel_necesario'])->toBe(RegistrarEnvasado::teoricos($this->suavizante->id, [$p5->id => '2'], $composicion, [$this->suavizante->id => false])['granel'])
        // Botella: 4 en stock y 1 vendida neta (2 − 1 devuelta): 112 días; alcanza para 3 (botellas y tapas).
        ->and(paFila($r['presentaciones'], 'presentacion', $p1->nombre))->toMatchArray(['stock' => '4.000', 'cobertura_dias' => '112.000', 'maximo_unidades' => '3.000', 'limita' => 'Botella 1 L'])
        ->and(paFila($r['presentaciones'], 'presentacion', $galon->nombre))->toMatchArray(['cobertura_dias' => null, 'maximo_unidades' => '3.000', 'limita' => 'Tapa rosca',
            'granel_necesario' => '12.000', 'nota' => 'sin ventas netas en 28 días']);

    // El máximo se puede envasar de verdad: 3 botellas con lo necesario de la herramienta.
    $this->actingAs($this->admin);
    app(RegistrarEnvasado::class)->ejecutar($this->admin, [
        'sucursal_id' => $this->matriz->id, 'granel_id' => $this->suavizante->id, 'lote_id' => DB::table('lotes')->where('producto_id', $this->suavizante->id)->value('id'),
        'unidades' => [$p1->id => '3'], 'granel_real' => '3', 'envases' => [$this->botella->id => '3', DB::table('productos')->where('nombre', 'Tapa rosca')->value('id') => '3'],
        'nota' => null, 'clave' => (string) Str::uuid(),
    ]);

    // Lote del granel vencido: no se ofrece ni cuenta.
    DB::table('lotes')->where('producto_id', $this->suavizante->id)->update(['vencimiento' => '2026-11-10']);
    $r = paHerramienta($this->admin, 'plan_packaging', ['granel' => 'Suavizante floral', 'sucursal' => 'Casa Matriz']);

    expect($r['graneles'][0])->toMatchArray(['existencia' => '27.000', 'vencido' => '27.000', 'utilizable' => '0.000', 'lote_sugerido' => null])
        ->and(array_unique(array_column($r['presentaciones'], 'maximo_unidades')))->toBe(['0.000'])
        ->and(paFila($r['presentaciones'], 'presentacion', $galon->nombre)['limita'])->toBe('Suavizante floral')
        ->and(paLlamar($this->admin, 'plan_packaging', ['granel' => 'Jabón'])['error'])->toContain('No encontré');
});

test('7c: suggest_production_plan exists only for the admin with the capability and never mixes companies', function () {
    [$empresaB, $adminB] = empresaConAdministrador();

    $this->actingAs($this->admin);
    expect(array_keys(app(RegistroHerramientas::class)->disponibles($this->admin)))->toContain('suggest_production_plan')
        ->and(app(InstruccionesSistema::class)->texto(ContextoAgente::desde($this->admin), false, ['suggest_production_plan']))
        ->toContain('suggest_production_plan (', 'sugerido_orientativo', 'no ajustes su cifra')
        ->and(paLlamar($this->vendedor, 'suggest_production_plan')['error'])->toContain('no tiene permiso')
        ->and(paLlamar($this->admin, 'suggest_production_plan', ['horizon_days' => 5])['error'])->not->toBeNull();

    $this->actingAs($adminB);
    expect(array_keys(app(RegistroHerramientas::class)->disponibles($adminB)))->not->toContain('suggest_production_plan');

    // La empresa B con Producción no ve el suavizante de A ni por nombre.
    app(CambiarFuncionesEmpresa::class)->ejecutar($adminB, $empresaB, Rubro::General, [Capacidad::Produccion]);
    $adminB = $adminB->fresh();
    expect(paHerramienta($adminB, 'suggest_production_plan'))->toBe(['plan' => [], 'detalle' => []])
        ->and(paLlamar($adminB, 'suggest_production_plan', ['producto' => 'Suavizante floral'])['error'])->toContain('No encontré');
});

test('UC-21: demand counts only days with stock, presentations convert to bulk and open orders count in the position; never negative', function () {
    ['botella' => $botella] = paHistorial($this);
    $resultado = paLlamar($this->admin, 'suggest_production_plan');
    $r = paHerramienta($this->admin, 'suggest_production_plan');
    $detergente = paFila($r['plan'], 'producto', 'Detergente');

    // Demanda: 10 L ÷ 28 + 3 × 2 L ÷ 28 + 2 × 2 L ÷ 3 días con stock (Norte) = 0,357142 + 0,214285 + 1,333333 = 1,904760 L/día.
    // Necesidad 14 días: 5 + 3 + 18,666666 = 26,667. Posición: 2 L + 1 botella × 2 L + órdenes 2 + 1 = 7. Sugerido 19,667.
    expect($resultado['datasets'][0]['dataset']->meta)->toMatchArray(['quality' => 'estimated', 'period' => ['from' => '2026-10-22', 'to' => '2026-11-18']])
        ->and($detergente)->toMatchArray(['unidad' => 'Litro', 'historial_dias' => 28, 'vendido_granel' => '20.000', 'demanda_diaria' => '1.905',
            'stock_granel' => '2.000', 'stock_presentaciones' => '2.000', 'en_ordenes' => '3.000', 'ordenes' => 2, 'posicion' => '7.000', 'cobertura_dias' => '3.700',
            'necesidad' => '26.667', 'sugerido' => '19.667', 'sugerido_orientativo' => null, 'confianza' => 'baja',
            'sucursal_produccion' => 'Casa Matriz', 'origen_sucursal' => 'última orden del producto'])
        ->and($detergente['motivo'])->toContain("Norte · {$botella->nombre}: 25 d")
        ->and(array_map(fn (array $f) => [$f['sucursal'], $f['articulo'], $f['vendido_granel'], $f['dias_con_stock'], $f['dias_sin_stock'], $f['demanda_diaria'], $f['stock_granel']],
            array_values(array_filter($r['detalle'], fn (array $f) => $f['producto'] === 'Detergente'))))->toBe([
                ['Casa Matriz', 'Detergente', '10.000', 28, 0, '0.357', '2.000'],
                ['Casa Matriz', $botella->nombre, '6.000', 28, 0, '0.214', '2.000'],
                ['Norte', $botella->nombre, '4.000', 3, 25, '1.333', '0.000'],
            ])
        ->and(paFila($r['detalle'], 'sucursal', 'Norte')['nota'])->toBe('sin stock 25 días: la demanda usa los 3 días con stock')
        // El suavizante (creado hoy, sin stock ni ventas) no recibe una cifra inventada.
        ->and(paFila($r['plan'], 'producto', 'Suavizante floral'))->toMatchArray(['historial_dias' => 1, 'vendido_granel' => '0.000', 'demanda_diaria' => '0.000',
            'cobertura_dias' => null, 'necesidad' => null, 'sugerido' => null, 'sugerido_orientativo' => null, 'confianza' => 'sin datos',
            'motivo' => 'sin stock en toda la ventana: la demanda no se conoce', 'sucursal_produccion' => 'Casa Matriz', 'origen_sucursal' => 'última orden de la empresa']);

    // Con 7 días: 2,5 + 1,5 + 9,333333 = 13,333 − 7 = 6,333.
    expect(paHerramienta($this->admin, 'suggest_production_plan', ['producto' => 'detergente', 'horizon_days' => 7])['plan'][0])
        ->toMatchArray(['necesidad' => '13.333', 'sugerido' => '6.333']);

    // Otra emitida de 30 L: posición 37 sobre una necesidad de 26,667 → 0, nunca negativo.
    paEmitir($this->admin, Producto::query()->where('nombre', 'Detergente')->firstOrFail(), $this->norte, '30', '2026-11-25');
    expect(paHerramienta($this->admin, 'suggest_production_plan', ['producto' => 'Detergente'])['plan'][0])
        ->toMatchArray(['en_ordenes' => '33.000', 'posicion' => '37.000', 'cobertura_dias' => '19.400', 'sugerido' => '0.000', 'sucursal_produccion' => 'Norte']);
});

test('UC-21: under 14 days of history the figure is only orientative and UC-22 leaves it out', function () {
    ['p1' => $p1, 'p5' => $p5] = paPresentaciones($this);
    $resultado = paLlamar($this->admin, 'suggest_production_plan', ['producto' => 'Suavizante floral']);
    $r = paHerramienta($this->admin, 'suggest_production_plan', ['producto' => 'Suavizante floral']);

    // Todo pasó hoy (1 día con stock): 3 L + 1 botella neta × 1 L + 1 bidón × 5 L = 9 L/día; 14 días = 126 − (30 + 4 + 5) = 87, solo orientativo.
    expect($resultado['datasets'][0]['dataset']->meta['quality'])->toBe('insufficient')
        ->and($r['plan'][0])->toMatchArray(['historial_dias' => 1, 'vendido_granel' => '9.000', 'demanda_diaria' => '9.000', 'stock_granel' => '30.000',
            'stock_presentaciones' => '9.000', 'en_ordenes' => '0.000', 'posicion' => '39.000', 'cobertura_dias' => '4.300', 'necesidad' => '126.000',
            'sugerido' => null, 'sugerido_orientativo' => '87.000', 'confianza' => 'baja', 'motivo' => 'historial insuficiente: 1 día (mínimo 14); cifra solo orientativa'])
        // El galón no tuvo stock ni ventas: no aporta demanda.
        ->and(array_column($r['detalle'], 'vendido_neto', 'articulo'))->toBe(['Suavizante floral' => '3.000', $p1->nombre => '1.000', $p5->nombre => '1.000']);

    // La compra de materia prima con el plan no usa cifras orientativas.
    $compra = paHerramienta($this->admin, 'suggest_raw_material_purchase', ['incluir_plan' => true]);

    expect($compra['resumen'][0])->toMatchArray(['productos_en_plan' => 0])
        ->and(array_diff(array_column($compra['compra'], 'necesidad_plan'), ['0.000']))->toBe([]);
});

test('UC-22: incluir_plan adds the plan raw material, scaled like an order, at the production branch', function () {
    paHistorial($this);
    DB::table('productos')->where('id', $this->agua->id)->update(['stock_minimo' => '190']);
    $matriz = fn (array $r) => array_values(array_filter($r['compra'], fn (array $f) => $f['sucursal'] === 'Casa Matriz'));

    // Sin plan: la emitida de 2 L pide 1,9 L de agua y 1 botella, y alcanza (199,05 L y 9 botellas).
    $sin = paHerramienta($this->admin, 'suggest_raw_material_purchase', ['horizon_days' => 14]);
    expect($matriz($sin))->toBe([])
        ->and($sin['resumen'][0])->toMatchArray(['productos_en_plan' => null, 'materias_a_comprar' => 0]);

    // Con plan (19,667 L de detergente en Casa Matriz): agua 19,667 × 9,5 ÷ 10 = 18,684; botellas 9,8335 → 10 (enteras hacia arriba).
    // Agua: 1,9 + 18,684 + 190 − 199,05 = 11,534 (Bs 5,77); botellas: 1 + 10 − 9 = 2 (Bs 2,40).
    $con = paHerramienta($this->admin, 'suggest_raw_material_purchase', ['horizon_days' => 14, 'incluir_plan' => true]);
    expect(paFila($matriz($con), 'materia_prima', 'Agua desmineralizada'))->toMatchArray(['necesidad' => '1.900', 'necesidad_plan' => '18.684', 'ordenes' => 1,
        'utilizable' => '199.050', 'minimo' => '190.000', 'a_comprar' => '11.534', 'presupuesto' => '5.77'])
        ->and(paFila($matriz($con), 'materia_prima', 'Botella 1 L'))->toMatchArray(['necesidad' => '1.000', 'necesidad_plan' => '10.000', 'utilizable' => '9.000',
            'a_comprar' => '2.000', 'presupuesto' => '2.40'])
        ->and(array_unique(array_column(array_values(array_filter($con['compra'], fn (array $f) => $f['sucursal'] === 'Norte')), 'necesidad_plan')))->toBe(['0.000'])
        ->and($con['resumen'][0])->toMatchArray(['productos_en_plan' => 1, 'materias_a_comprar' => 2, 'presupuesto_estimado' => '8.17']);
});

/**
 * Acciones que ofrece una herramienta en uno de sus datasets: [tipo, id, etiqueta, url].
 *
 * @param  array<string, mixed>  $argumentos
 * @return list<array{0: string, 1: int|null, 2: string, 3: string}>
 */
function paAcciones(User $usuario, string $herramienta, string $dataset, array $argumentos = []): array
{
    $resultado = paLlamar($usuario, $herramienta, $argumentos);
    expect($resultado['error'] ?? null)->toBeNull();
    $item = collect($resultado['datasets'])->firstOrFail(fn (array $i) => $i['dataset']->clave === $dataset);

    return array_map(fn (AccionPropuesta $a) => [$a->tipo, $a->id, $a->etiqueta, CatalogoAcciones::url($a)], $item['acciones'] ?? []);
}

test('IA-P3: capacity, orders, purchase and plan offer links to existing screens, prioritized and without preloaded data', function () {
    $atrasada = paEmitir($this->admin, $this->suavizante, $this->norte, '10', '2026-11-10');
    $a = paEmitir($this->admin, $this->suavizante, $this->matriz, '150', '2026-11-19');
    $b = paEmitir($this->admin, $this->suavizante, $this->matriz, '100', '2026-11-20');
    $ordenes = paAcciones($this->admin, 'review_production_orders', 'ordenes');

    expect(paAcciones($this->admin, 'estimate_production_capacity', 'capacidad'))->toBe([['nueva_orden', null, 'Nueva orden de producción', '/produccion/ordenes/nueva']])
        // UC-20: primero las que no alcanzan en conjunto (la atrasada, luego B) y después A, que alcanza.
        ->and(array_column($ordenes, 1))->toBe([$atrasada->id, $b->id, $a->id])
        ->and($ordenes[0][0])->toBe('abrir_orden')
        ->and($ordenes[0][2])->toStartWith('Abrir orden '.$atrasada->codigo().' · Suavizante floral')
        ->and($ordenes[0][3])->toBe('/produccion/ordenes/'.$atrasada->id)
        // UC-22: hay materia prima que comprar para A y B; UC-21 sin ventas no sugiere producir, así que no ofrece «Nueva orden».
        ->and(paAcciones($this->admin, 'suggest_raw_material_purchase', 'compra'))->toBe([['ingreso_materia_prima', null, 'Ingreso de materia prima', '/compras/nueva?materia_prima=1']])
        ->and(paAcciones($this->admin, 'suggest_production_plan', 'plan'))->toBe([])
        // Ninguna acción de Producción se muestra al vendedor, aunque viniera guardada.
        ->and(CatalogoAcciones::paraVista($this->vendedor, array_map(fn (array $x) => ['tipo' => $x[0], 'id' => $x[1], 'etiqueta' => $x[2]], $ordenes)))->toBe([])
        ->and(CatalogoAcciones::paraVista($this->admin, [['tipo' => 'abrir_orden', 'id' => $a->id, 'etiqueta' => 'A']]))->toHaveCount(1);
});

test('IA-P3: margin, packaging and lot tools link to the product, Nuevo envasado and the lot screen', function () {
    ['p1' => $p1, 'p5' => $p5, 'galon' => $galon, 'orden' => $orden] = paPresentaciones($this);
    DB::table('lotes')->where('producto_id', $this->fragancia->id)->where('numero', 'F-1')->update(['vencimiento' => '2026-12-01']);
    $f1 = (int) DB::table('lotes')->where('producto_id', $this->fragancia->id)->where('numero', 'F-1')->value('id');
    $op = (int) DB::table('lotes')->where('producto_id', $this->suavizante->id)->where('numero', $orden->codigo())->value('id');
    $trazar = paAcciones($this->admin, 'trace_lot', 'lotes', ['lote' => $orden->codigo()]);

    // UC-25: de menor a mayor margen de lista (bidón 76,7 %, botella 79,7 %, granel 85,5 %) y el no calculable al final.
    expect(array_column(paAcciones($this->admin, 'analyze_manufactured_margin', 'margenes'), 1))->toBe([$p5->id, $p1->id, $this->suavizante->id, $galon->id])
        ->and(paAcciones($this->admin, 'analyze_manufactured_margin', 'margenes')[0][3])->toBe('/productos/'.$p5->id.'/editar')
        ->and(paAcciones($this->admin, 'plan_packaging', 'presentaciones'))->toBe([['nuevo_envasado', null, 'Nuevo envasado', '/produccion/envasados/nuevo']])
        ->and(paAcciones($this->admin, 'review_expiring_lots', 'lotes', ['clase' => 'materia_prima']))
        ->toBe([['ver_lote', $f1, 'Ver trazabilidad del lote F-1 · Fragancia floral', '/inventario/lotes/'.$f1]])
        // El lote OP lo comparten el granel y sus presentaciones: un enlace por lote, cada uno a su pantalla.
        ->and(array_column($trazar, 0))->each->toBe('ver_lote')
        ->and(array_column($trazar, 1))->toContain($op)
        ->and(array_column($trazar, 3))->toContain('/inventario/lotes/'.$op);
});

test('UC-21 contrast: estimates at a past cut-off with 28 and 14 days, compares with the real net sales and only on request', function () {
    paHistorial($this);
    $resultado = paLlamar($this->admin, 'suggest_production_plan', ['contrastar' => true]);
    $r = paHerramienta($this->admin, 'suggest_production_plan', ['contrastar' => true]);

    // Horizonte 14: corte 3/11; real del 4/11 al 17/11 (días completos). Hasta el corte, Casa Matriz vendió 4 L y 2 botellas
    // (25/10) con stock los 28 días (7/10–3/11) y los 14 (21/10–3/11): 28 días → 4 × 14 ÷ 28 + 4 × 14 ÷ 28 = 4; 14 días → 8.
    // Real: 6 L + 1 botella × 2 L (Casa Matriz) + 2 botellas × 2 L (Norte) = 12. Error: −66,7 % y −33,3 %.
    expect(array_keys($r))->toBe(['plan', 'detalle', 'contraste', 'contraste_resumen'])
        ->and(array_keys(paHerramienta($this->admin, 'suggest_production_plan')))->toBe(['plan', 'detalle'])
        ->and($resultado['datasets'][2]['dataset']->meta)->toMatchArray(['quality' => 'actual', 'period' => ['from' => '2026-11-04', 'to' => '2026-11-17']])
        ->and(paFila($r['contraste'], 'producto', 'Detergente'))->toMatchArray(['unidad' => 'Litro', 'historial_corte' => 28, 'estimado_28' => '4.000',
            'estimado_14' => '8.000', 'real' => '12.000', 'error_28_pct' => '-66.700', 'error_14_pct' => '-33.300', 'dias_sin_stock_despues' => 11,
            'nota' => 'hubo días sin stock después del corte: la venta real puede quedar por debajo de la demanda'])
        // El suavizante (creado hoy) no tenía ventas al corte: el método no habría estimado nada y no entra en el error.
        ->and(paFila($r['contraste'], 'producto', 'Suavizante floral'))->toMatchArray(['historial_corte' => 0, 'estimado_28' => null, 'estimado_14' => null,
            'real' => '0.000', 'error_28_pct' => null, 'dias_sin_stock_despues' => 0, 'nota' => 'sin ventas netas antes del corte: el método no habría estimado nada'])
        ->and($r['contraste_resumen'])->toBe([['corte' => '2026-11-03', 'real_desde' => '2026-11-04', 'real_hasta' => '2026-11-17', 'productos_comparados' => 1,
            'real_total' => '12.000', 'error_ponderado_28_pct' => '66.700', 'error_ponderado_14_pct' => '33.300']])
        // El plan no cambia por pedir el contraste.
        ->and($r['plan'])->toBe(paHerramienta($this->admin, 'suggest_production_plan')['plan']);

    // Horizonte 7: corte 10/11 y nada vendido del 11/11 al 17/11. 28 días: 10 L ÷ 28 + 3 botellas × 2 L ÷ 28 + 2 × 2 L ÷ 3 días
    // con stock (Norte) = (0,357142 + 0,214285 + 1,333333) × 7 = 13,333; 14 días (27/10–10/11): 6 × 7 ÷ 14 + 2 × 7 ÷ 14 + 4 × 7 ÷ 3 = 13,333.
    // Sin venta real no hay porcentaje (nunca divide por cero); Norte quedó sin stock los 7 días.
    $siete = paHerramienta($this->admin, 'suggest_production_plan', ['producto' => 'Detergente', 'horizon_days' => 7, 'contrastar' => true]);

    expect($siete['contraste'])->toHaveCount(1)
        ->and($siete['contraste'][0])->toMatchArray(['estimado_28' => '13.333', 'estimado_14' => '13.333', 'real' => '0.000', 'error_28_pct' => null,
            'error_14_pct' => null, 'dias_sin_stock_despues' => 7])
        ->and($siete['contraste_resumen'][0])->toMatchArray(['corte' => '2026-11-10', 'productos_comparados' => 1, 'real_total' => '0.000',
            'error_ponderado_28_pct' => null, 'error_ponderado_14_pct' => null]);

    // Otra empresa con Producción no ve nada de A en el contraste; el vendedor no tiene la herramienta.
    [$empresaB, $adminB] = empresaConAdministrador();
    $this->actingAs($adminB);
    app(CambiarFuncionesEmpresa::class)->ejecutar($adminB, $empresaB, Rubro::General, [Capacidad::Produccion]);
    $b = paHerramienta($adminB->fresh(), 'suggest_production_plan', ['contrastar' => true]);

    expect($b['contraste'])->toBe([])
        ->and($b['contraste_resumen'][0])->toMatchArray(['productos_comparados' => 0, 'real_total' => '0.000', 'error_ponderado_28_pct' => null])
        ->and(paLlamar($this->vendedor, 'suggest_production_plan', ['contrastar' => true])['error'])->toContain('no tiene permiso')
        ->and(paLlamar($this->admin, 'suggest_production_plan', ['contrastar' => 'quizás'])['error'])->not->toBeNull()
        ->and(app(InstruccionesSistema::class)->texto(ContextoAgente::desde($this->admin), false, ['suggest_production_plan']))->toContain('contrastar=true');
});
