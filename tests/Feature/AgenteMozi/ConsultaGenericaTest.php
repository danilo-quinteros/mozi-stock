<?php

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\Datos\CatalogoDatos;
use App\Domain\AgenteMozi\Datos\ConsultaDatos;
use App\Domain\AgenteMozi\DTOs\DatasetPropuesto;
use App\Domain\AgenteMozi\Herramientas\ConsultarDatos;
use App\Domain\AgenteMozi\Herramientas\RegistroHerramientas;
use App\Domain\AgenteMozi\Ia\DTOs\LlamadaHerramienta;
use App\Domain\AgenteMozi\Ia\ProtocoloRespuesta;
use App\Domain\AgenteMozi\Lienzo\ValidadorLienzo;
use App\Domain\Reportes\Consultas\VentasPeriodo;
use App\Domain\Reportes\Periodo;
use App\Models\Sucursal;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
 * Agente Mozi · consulta genérica query_business_data (docs/AGENTE_MOZI_IA.md § 5.1): el modelo
 * elige fuente, campos, filtros y agrupación; Laravel valida contra el catálogo cerrado y ejecuta
 * con la empresa y, para el vendedor, la sucursal de la sesión. Lunes 2 de noviembre de 2026 en La Paz.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'America/La_Paz'));
    [$this->empresa, $this->admin] = empresaConAdministrador();
    $this->centro = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Centro']);
    $this->norte = Sucursal::factory()->create(['empresa_id' => $this->empresa->id, 'nombre' => 'Norte']);
    $this->arroz = productoDe($this->empresa, ['nombre' => 'Arroz', 'precio_consumidor' => '10.00']);
    $this->jabon = productoDe($this->empresa, ['nombre' => 'Jabón', 'precio_consumidor' => '7.50']);
    $this->pollo = productoDe($this->empresa, ['nombre' => 'Pollo', 'precio_consumidor' => '25.00']);
    $this->leche = productoDe($this->empresa, ['nombre' => 'Leche', 'precio_consumidor' => '6.00']);
    productoDe($this->empresa, ['nombre' => 'Tomate', 'activo' => false]);
    $this->vendedor = User::factory()->vendedor($this->centro)->create();
    $this->actingAs($this->admin);

    foreach ([$this->centro, $this->norte] as $sucursal) {
        foreach ([$this->arroz, $this->jabon, $this->pollo, $this->leche] as $producto) {
            cargarStock($this->admin, $sucursal, $producto, '50');
        }
    }
});

/**
 * Venta al contado confirmada en la hora indicada (La Paz).
 */
function ventaGenerica(User $actor, Sucursal $sucursal, array $lineas, string $cuando): void
{
    test()->travelTo(CarbonImmutable::parse($cuando, 'America/La_Paz'));
    confirmarVenta($actor, crearPedido($actor, $sucursal, $lineas), ['metodo' => 'efectivo']);
    test()->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'America/La_Paz'));
}

/**
 * @param  array<string, mixed>  $argumentos
 * @return array<string, mixed>
 */
function consultaGenerica(User $usuario, array $argumentos): array
{
    test()->actingAs($usuario);

    return (new ConsultaDatos(new CatalogoDatos(ContextoAgente::desde($usuario))))->ejecutar($argumentos);
}

/**
 * Ejecuta la herramienta como lo hace el orquestador: registro, permisos y validación.
 *
 * @param  array<string, mixed>  $argumentos
 * @return array<string, mixed>
 */
function herramientaGenerica(User $usuario, array $argumentos): array
{
    test()->actingAs($usuario);
    $registro = app(RegistroHerramientas::class);

    return $registro->ejecutar(new LlamadaHerramienta('c1', 'query_business_data', $argumentos), $registro->disponibles($usuario), $usuario, ContextoAgente::desde($usuario));
}

test('every source compiles in MySQL: listing all its fields and grouping by each field with all its measures', function () {
    ventaGenerica($this->admin, $this->centro, [['producto_id' => $this->arroz->id, 'cantidad' => '2']], '2026-11-01 09:00');
    $catalogo = CatalogoDatos::paraDescribir(true);

    foreach (CatalogoDatos::FUENTES as $nombre) {
        $fuente = $catalogo->fuente($nombre);
        $campos = array_map(fn ($c) => $c->nombre, $fuente->visibles(true, false));
        $medidas = array_map(fn ($c) => $c->nombre, $fuente->visibles(true, true));

        foreach (array_chunk($campos, 20) as $grupo) {
            $resultado = consultaGenerica($this->admin, ['source' => $nombre, 'fields' => $grupo, 'limit' => 5]);
            expect(array_column($resultado['columnas'], 'field'))->toBe($grupo);
            app(ValidadorLienzo::class)->dataset(new DatasetPropuesto('d1', $resultado['columnas'], $resultado['filas'], []));
        }

        foreach ($campos as $campo) {
            foreach (array_chunk($medidas, 6) as $lote) {
                $resultado = consultaGenerica($this->admin, ['source' => $nombre, 'group_by' => [$campo], 'measures' => $lote, 'limit' => 5]);
                expect(array_column($resultado['columnas'], 'field'))->toBe([$campo, ...$lote]);
            }
        }

        expect(consultaGenerica($this->admin, ['source' => $nombre, 'measures' => ['conteo']])['filas'])->toHaveCount(1);
    }
});

test('the model can find product names with the vowel o, without accents mattering and without inactive products', function () {
    $resultado = herramientaGenerica($this->admin, ['source' => 'productos', 'fields' => ['nombre', 'precio_consumidor'],
        'filters' => [['field' => 'nombre', 'op' => 'contains', 'value' => 'o']]]);
    $dataset = $resultado['datasets'][0]['dataset'];

    expect(array_column($dataset->filas, 'nombre'))->toBe(['Arroz', 'Jabón', 'Pollo'])
        ->and($dataset->filas[1]['precio_consumidor'])->toBe('7.50')
        ->and($dataset->meta)->toMatchArray(['quality' => 'actual', 'total_matches' => 3, 'truncated' => false, 'scope' => 'Todas las sucursales']);

    $conInactivos = consultaGenerica($this->admin, ['source' => 'productos', 'fields' => ['nombre', 'estado'],
        'filters' => [['field' => 'nombre', 'op' => 'contains', 'value' => 'O'], ['field' => 'estado', 'op' => 'in', 'values' => ['activo', 'inactivo']]]]);

    expect(array_column($conInactivos['filas'], 'nombre'))->toBe(['Arroz', 'Jabón', 'Pollo', 'Tomate']);
});

test('a truncated result tells the model how many rows matched in total', function () {
    $resultado = herramientaGenerica($this->admin, ['source' => 'productos', 'fields' => ['nombre'], 'limit' => 2,
        'sort' => ['field' => 'precio_consumidor', 'direction' => 'desc']]);
    $dataset = $resultado['datasets'][0]['dataset'];
    $paraModelo = app(ProtocoloRespuesta::class)->paraModelo('d1', $dataset, $resultado['datasets'][0]['descripcion']);

    expect(array_column($dataset->filas, 'nombre'))->toBe(['Pollo', 'Arroz'])
        ->and($paraModelo)->toMatchArray(['total_rows' => 2, 'total_matches' => 4, 'truncated' => true])
        ->and($paraModelo['description'])->toContain('2 de 4 coincidencias (recortado');
});

test('which branch sold more: grouping sales by branch matches Reportes and measures can be filtered', function () {
    ventaGenerica($this->admin, $this->centro, [['producto_id' => $this->pollo->id, 'cantidad' => '4']], '2026-11-01 09:00');   // 100
    ventaGenerica($this->admin, $this->centro, [['producto_id' => $this->arroz->id, 'cantidad' => '1']], '2026-11-01 18:00');   // 10
    ventaGenerica($this->admin, $this->norte, [['producto_id' => $this->jabon->id, 'cantidad' => '3']], '2026-11-01 12:00');    // 22.50

    $porSucursal = consultaGenerica($this->admin, ['source' => 'ventas', 'group_by' => ['sucursal'], 'measures' => ['valor_vendido', 'conteo', 'ticket_promedio'],
        'date_from' => '2026-11-01', 'date_to' => '2026-11-02']);

    expect($porSucursal['filas'])->toBe([
        ['sucursal' => 'Centro', 'valor_vendido' => '110.00', 'conteo' => 2, 'ticket_promedio' => '55.00'],
        ['sucursal' => 'Norte', 'valor_vendido' => '22.50', 'conteo' => 1, 'ticket_promedio' => '22.50'],
    ])->and($porSucursal['periodo'])->toBe(['from' => '2026-11-01', 'to' => '2026-11-02'])
        ->and(VentasPeriodo::totales($this->empresa->id, Periodo::rango('2026-11-01', '2026-11-02'))['valor_vendido'])->toBe('132.50');

    $grandes = consultaGenerica($this->admin, ['source' => 'ventas', 'group_by' => ['sucursal'], 'measures' => ['valor_vendido'],
        'filters' => [['field' => 'valor_vendido', 'op' => 'gt', 'value' => '50']]]);

    expect($grandes['filas'])->toBe([['sucursal' => 'Centro', 'valor_vendido' => '110.00']])->and($grandes['total'])->toBe(1);
});

test('time fields use La Paz days and hours, and weekdays sort Monday first', function () {
    // Domingo 1 a las 22:00 en La Paz = lunes 2 a las 02:00 UTC.
    ventaGenerica($this->admin, $this->centro, [['producto_id' => $this->arroz->id, 'cantidad' => '1']], '2026-11-01 22:00');
    ventaGenerica($this->admin, $this->centro, [['producto_id' => $this->arroz->id, 'cantidad' => '2']], '2026-11-02 08:15');

    expect(consultaGenerica($this->admin, ['source' => 'ventas', 'fields' => ['fecha', 'hora', 'dia_semana', 'semana', 'mes', 'total'], 'sort' => ['field' => 'fecha', 'direction' => 'asc']])['filas'])->toBe([
        ['fecha' => '2026-11-01', 'hora' => 22, 'dia_semana' => 'domingo', 'semana' => '2026-10-26', 'mes' => '2026-11', 'total' => '10.00'],
        ['fecha' => '2026-11-02', 'hora' => 8, 'dia_semana' => 'lunes', 'semana' => '2026-11-02', 'mes' => '2026-11', 'total' => '20.00'],
    ])->and(consultaGenerica($this->admin, ['source' => 'ventas', 'group_by' => ['dia_semana'], 'measures' => ['valor_vendido']])['filas'])->toBe([
        ['dia_semana' => 'lunes', 'valor_vendido' => '20.00'],
        ['dia_semana' => 'domingo', 'valor_vendido' => '10.00'],
    ])->and(consultaGenerica($this->admin, ['source' => 'ventas', 'measures' => ['conteo'], 'date_from' => '2026-11-01', 'date_to' => '2026-11-01'])['filas'])
        ->toBe([['conteo' => 1]]);
});

test('a seller stays in their branch, never sees financial sources or fields, and cannot pass a branch', function () {
    ventaGenerica($this->admin, $this->centro, [['producto_id' => $this->pollo->id, 'cantidad' => '1']], '2026-11-01 09:00');
    ventaGenerica($this->admin, $this->norte, [['producto_id' => $this->pollo->id, 'cantidad' => '2']], '2026-11-01 10:00');

    $ventas = herramientaGenerica($this->vendedor, ['source' => 'ventas', 'fields' => ['sucursal', 'total']]);
    $stock = herramientaGenerica($this->vendedor, ['source' => 'productos', 'fields' => ['nombre', 'stock'], 'filters' => [['field' => 'nombre', 'op' => 'equals', 'value' => 'pollo']]]);
    $herramienta = app()->makeWith(ConsultarDatos::class, ['administrador' => false]);

    expect($ventas['datasets'][0]['dataset']->filas)->toBe([['sucursal' => 'Centro', 'total' => '25.00']])
        ->and($ventas['datasets'][0]['dataset']->meta['scope'])->toBe('Sucursal Centro')
        ->and($stock['datasets'][0]['dataset']->filas)->toBe([['nombre' => 'Pollo', 'stock' => '49.000']])
        ->and(herramientaGenerica($this->vendedor, ['source' => 'dinero'])['error'])->toContain('no está disponible para el rol')
        ->and(herramientaGenerica($this->vendedor, ['source' => 'ventas', 'fields' => ['cliente', 'saldo']])['error'])->toContain('saldo no está disponible')
        ->and(herramientaGenerica($this->vendedor, ['source' => 'ventas', 'measures' => ['total_cobrado']])['error'])->toContain('total_cobrado no está disponible')
        ->and(herramientaGenerica($this->vendedor, ['source' => 'ventas', 'sucursal_id' => $this->norte->id])['error'])->toContain('Argumentos no permitidos: sucursal_id')
        ->and($herramienta->parametros()['properties']['source']['enum'])->toBe(CatalogoDatos::FUENTES_VENDEDOR)
        ->and($herramienta->descripcion())->not->toContain('dinero:')->not->toContain('saldo')->not->toContain('cobrado:money');
});

test('company A never sees company B data, whatever the filters', function () {
    auth()->logout();
    [$otra, $adminOtra] = empresaConAdministrador();
    productoDe($otra, ['nombre' => 'Arroz de la otra empresa']);

    expect(array_column(consultaGenerica($this->admin, ['source' => 'productos', 'fields' => ['nombre'],
        'filters' => [['field' => 'nombre', 'op' => 'starts_with', 'value' => 'Arroz']]])['filas'], 'nombre'))->toBe(['Arroz'])
        ->and(array_column(consultaGenerica($adminOtra, ['source' => 'productos', 'fields' => ['nombre']])['filas'], 'nombre'))->toBe(['Arroz de la otra empresa'])
        ->and(consultaGenerica($adminOtra, ['source' => 'stock', 'measures' => ['conteo']])['filas'])->toBe([['conteo' => 0]]);
});

test('invalid fields, operators and values come back to the model as clear errors', function () {
    expect(herramientaGenerica($this->admin, ['source' => 'ventas', 'fields' => ['ganancia']])['error'])->toContain('ganancia no es un campo de ventas. Campos: venta, fecha')
        ->and(herramientaGenerica($this->admin, ['source' => 'ventas', 'measures' => ['total']])['error'])->toContain('total no es una medida de ventas')
        ->and(herramientaGenerica($this->admin, ['source' => 'ventas', 'filters' => [['field' => 'total', 'op' => 'contains', 'value' => '1']]])['error'])->toContain('El operador contains no sirve para total')
        ->and(herramientaGenerica($this->admin, ['source' => 'ventas', 'filters' => [['field' => 'total', 'op' => 'gt', 'value' => '1.000,50']]])['error'])->toContain('total es numérico')
        ->and(herramientaGenerica($this->admin, ['source' => 'ventas', 'filters' => [['field' => 'valor_vendido', 'op' => 'gt', 'value' => '1']]])['error'])->toContain('solo se puede filtrar en una agregación')
        ->and(herramientaGenerica($this->admin, ['source' => 'productos', 'date_from' => '2026-11-01'])['error'])->toContain('no tiene fecha principal')
        ->and(herramientaGenerica($this->admin, ['source' => 'ventas', 'fields' => ['total'], 'group_by' => ['sucursal']])['error'])->toContain('fields solo sirve para listar')
        ->and(herramientaGenerica($this->admin, ['source' => 'ventas', 'limit' => 900])['error'])->toContain('Argumentos inválidos');
});
