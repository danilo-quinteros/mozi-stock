<?php

use App\Domain\AgenteMozi\Demo\VistaCoberturaDemo;
use App\Enums\TipoArtefacto;
use App\Models\AgenteDataset;
use App\Models\AgenteMensaje;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * Agente Mozi · Fase 2: orquestador con proveedor de IA (Gemini simulado con Http::fake, nunca la
 * API real). Herramientas con datos reales, validadas y autorizadas, firma de razonamiento reenviada,
 * límites, cuotas, fallas sin perder la pregunta y protocolo del Lienzo (docs/AGENTE_MOZI_IA.md §§ 4, 5, 9 y 12).
 */

const PREGUNTA_IA = '¿Qué productos se van a quedar sin stock esta semana?';

beforeEach(function () {
    config([
        'agente_mozi.motor' => 'gemini',
        'agente_mozi.proveedores.gemini.api_key' => 'clave-de-prueba',
        'agente_mozi.proveedores.gemini.url' => 'https://gemini.test/v1beta',
        'agente_mozi.proveedores.gemini.modelo' => 'gemini-3.5-flash',
    ]);
    Sleep::fake();
});

/**
 * Respuesta simulada de generateContent con una llamada a función firmada.
 *
 * @param  array<string, mixed>  $argumentos
 * @return array<string, mixed>
 */
function geminiLlama(string $nombre, array $argumentos, string $id = 'c1'): array
{
    return [
        'candidates' => [['content' => ['role' => 'model', 'parts' => [
            ['functionCall' => ['name' => $nombre, 'args' => $argumentos, 'id' => $id], 'thoughtSignature' => 'firma-'.$id],
        ]], 'finishReason' => 'STOP']],
        'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 20, 'thoughtsTokenCount' => 5],
        'modelVersion' => 'gemini-3.5-flash-prueba',
        'responseId' => 'resp-'.$id,
    ];
}

/**
 * @param  list<array<string, mixed>>  $artefactos
 * @return array<string, mixed>
 */
function geminiResponde(string $mensaje, array $artefactos = [], string $id = 'c9'): array
{
    return geminiLlama('responder', ['message' => $mensaje, 'artifacts' => $artefactos], $id);
}

/**
 * @param  list<array<string, mixed>|int>  $respuestas  cuerpos 200 o códigos de error
 */
function geminiSimula(array $respuestas): void
{
    $secuencia = Http::sequence();

    foreach ($respuestas as $respuesta) {
        is_int($respuesta) ? $secuencia->push(['error' => ['code' => $respuesta, 'status' => 'UNAVAILABLE']], $respuesta) : $secuencia->push($respuesta);
    }

    Http::fake(['gemini.test/*' => $secuencia]);
}

/**
 * @return list<Request>
 */
function geminiPeticiones(): array
{
    return Http::recorded()->map(fn (array $par) => $par[0])->values()->all();
}

/**
 * Cuerpo JSON tal como viajó (data() conserva los stdClass que el adaptador usa para «{}»).
 *
 * @return array<string, mixed>
 */
function geminiCuerpo(Request $peticion): array
{
    return json_decode($peticion->body(), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @return list<string>
 */
function geminiHerramientasOfrecidas(Request $peticion): array
{
    return array_column(geminiCuerpo($peticion)['tools'][0]['functionDeclarations'], 'name');
}

test('a Gemini turn runs the requested tools on real data, replays the signed model turn and stores a validated answer with usage', function () {
    [, $admin] = motorConProductos(['nombre' => 'Ferretería Secreta']);
    geminiSimula([
        geminiLlama('query_business_data', ['source' => 'productos', 'measures' => ['conteo']], 'c1'),
        geminiLlama('query_business_data', ['source' => 'productos', 'fields' => ['nombre', 'stock'], 'sort' => ['field' => 'stock', 'direction' => 'asc']], 'c2'),
        geminiResponde('Hay **4 productos** activos.', [
            ['type' => 'kpi', 'title' => 'Productos', 'dataset' => 'd1', 'field' => 'conteo', 'format' => 'integer'],
            ['type' => 'table', 'title' => 'Stock', 'dataset' => 'd2', 'columns' => [
                ['field' => 'nombre', 'label' => 'Producto', 'format' => 'text'],
                ['field' => 'stock', 'label' => 'Stock', 'format' => 'number'],
            ]],
            ['type' => 'chart', 'title' => 'Stock por producto', 'dataset' => 'd2', 'chart_type' => 'bar', 'x_field' => 'nombre',
                'series' => [['field' => 'stock', 'label' => 'Unidades']], 'orientation' => 'horizontal'],
        ]),
    ]);

    $respuesta = preguntarAMozi($admin, PREGUNTA_IA);
    $primerDataset = AgenteDataset::query()->orderBy('id')->first();

    expect($respuesta->estado)->toBe(AgenteMensaje::COMPLETADO)
        ->and($respuesta->proveedor)->toBe('gemini')
        ->and($respuesta->modelo)->toBe('gemini-3.5-flash-prueba')
        ->and($respuesta->tokens_entrada)->toBe(300)
        ->and($respuesta->tokens_salida)->toBe(75)
        ->and($respuesta->herramientas_usadas)->toBe(2)
        ->and($respuesta->latencia_ms)->toBeInt()
        ->and($respuesta->texto)->toBe('Hay **4 productos** activos.')
        ->and($respuesta->artefactos->pluck('tipo')->all())->toBe([TipoArtefacto::Kpi, TipoArtefacto::Tabla, TipoArtefacto::Grafico])
        ->and(AgenteDataset::query()->count())->toBe(2)
        ->and($primerDataset->meta)->toMatchArray(['quality' => 'actual', 'source' => 'mozistock.productos'])
        ->and($primerDataset->meta)->not->toHaveKey('synthetic');

    [$primera, $segunda, $tercera] = geminiPeticiones();

    // La clave viaja solo en la cabecera; el cuerpo no lleva la clave ni el nombre de la empresa.
    expect($primera->url())->toBe('https://gemini.test/v1beta/models/gemini-3.5-flash:generateContent')
        ->and($primera->header('x-goog-api-key'))->toBe(['clave-de-prueba'])
        ->and($primera->body())->not->toContain('clave-de-prueba')
        ->and($tercera->body())->not->toContain('Ferretería Secreta')
        ->and(geminiCuerpo($primera)['toolConfig']['functionCallingConfig'])->toBe(['mode' => 'ANY'])
        ->and(geminiHerramientasOfrecidas($primera))->toBe(['query_business_data', 'analyze_receivables', 'get_payables_schedule', 'forecast_cash_flow',
            'review_cash_shifts', 'analyze_operating_expenses', 'get_branch_comparison', 'analyze_pending_orders', 'analyze_returns', 'responder']);

    // El turno del modelo vuelve idéntico (con su firma) y los resultados llevan datos reales, no sintéticos.
    $contenidos = geminiCuerpo($segunda)['contents'];
    $stock = geminiCuerpo($tercera)['contents'][4]['parts'][0]['functionResponse']['response']['datasets'][0];
    expect($contenidos[1]['parts'][0]['thoughtSignature'])->toBe('firma-c1')
        ->and($contenidos[2]['parts'][0]['functionResponse']['id'])->toBe('c1')
        ->and($contenidos[2]['parts'][0]['functionResponse']['response']['datasets'][0])->toMatchArray(['dataset' => 'd1', 'synthetic' => false, 'rows' => [['conteo' => 4]]])
        ->and($stock)->toMatchArray(['dataset' => 'd2', 'quality' => 'actual', 'total_rows' => 4, 'total_matches' => 4, 'truncated' => false])
        ->and($stock['rows'][0])->toBe(['nombre' => 'Leche', 'stock' => '3.000']);
});

test('a question without its own use case is answered from real data: product names with the vowel o', function () {
    [, $admin] = motorConProductos();
    geminiSimula([
        geminiLlama('query_business_data', ['source' => 'productos', 'fields' => ['nombre'], 'filters' => [['field' => 'nombre', 'op' => 'contains', 'value' => 'o']]]),
        geminiResponde('Tienen la letra «o»: Arroz, Jabón y Pollo.'),
    ]);

    $respuesta = preguntarAMozi($admin, '¿Qué nombre de producto tiene la vocal o?');
    $resultado = geminiCuerpo(geminiPeticiones()[1])['contents'][2]['parts'][0]['functionResponse']['response']['datasets'][0];

    expect(array_column($resultado['rows'], 'nombre'))->toBe(['Arroz', 'Jabón', 'Pollo'])
        ->and($resultado['description'])->toContain('filtros: nombre contains o')
        ->and($respuesta->estado)->toBe(AgenteMensaje::COMPLETADO);
});

test('a seller is never offered tools that expose costs, debts or other branches, and cannot run them by name', function () {
    [$empresa] = empresaConAdministrador();
    $vendedor = User::factory()->vendedor(Sucursal::factory()->create(['empresa_id' => $empresa->id]))->create();
    geminiSimula([
        geminiLlama('analyze_receivables', [], 'c1'),
        geminiLlama('query_business_data', ['source' => 'cuentas_por_cobrar'], 'c2'),
        geminiResponde('Tu rol no tiene permiso para ver las deudas de los clientes.'),
    ]);

    $respuesta = preguntarAMozi($vendedor, '¿Quién me debe más?');
    [$primera, , $tercera] = geminiPeticiones();
    $contenidos = geminiCuerpo($tercera)['contents'];

    expect(geminiHerramientasOfrecidas($primera))->toBe(['query_business_data', 'review_cash_shifts', 'analyze_pending_orders', 'responder'])
        ->and($contenidos[2]['parts'][0]['functionResponse']['response']['error'])->toContain('no tiene permiso')
        ->and($contenidos[4]['parts'][0]['functionResponse']['response']['error'])->toContain('Argumentos inválidos')
        ->and($respuesta->estado)->toBe(AgenteMensaje::COMPLETADO)
        ->and($respuesta->artefactos)->toHaveCount(0)
        ->and(AgenteDataset::query()->count())->toBe(0)
        ->and(geminiCuerpo($primera)['systemInstruction']['parts'][0]['text'])->toContain('Rol del usuario: vendedor');
});

test('tool arguments outside the schema, like empresa_id, or out of range are rejected and nothing runs', function () {
    [, $admin] = motorConProductos();
    geminiSimula([
        geminiLlama('query_business_data', ['source' => 'productos', 'empresa_id' => 999], 'c1'),
        geminiLlama('query_business_data', ['source' => 'productos', 'limit' => 900], 'c2'),
        geminiResponde('No pude consultar.', [['type' => 'kpi', 'title' => 'Productos', 'dataset' => 'd1', 'field' => 'conteo']]),
    ]);

    $respuesta = preguntarAMozi($admin, PREGUNTA_IA);
    $peticiones = geminiPeticiones();

    expect(geminiCuerpo($peticiones[1])['contents'][2]['parts'][0]['functionResponse']['response']['error'])->toContain('Argumentos no permitidos: empresa_id')
        ->and(geminiCuerpo($peticiones[2])['contents'][4]['parts'][0]['functionResponse']['response']['error'])->toContain('Argumentos inválidos')
        ->and($respuesta->artefactos)->toHaveCount(0)
        ->and(AgenteDataset::query()->count())->toBe(0);
});

test('the model gets at most the configured rows of a real query and is told how many matched', function () {
    config(['agente_mozi.ia.filas_al_modelo' => 1]);
    [, $admin] = motorConProductos();
    geminiSimula([
        geminiLlama('query_business_data', ['source' => 'productos', 'fields' => ['nombre'], 'limit' => 2]),
        geminiResponde('Listo.'),
    ]);

    preguntarAMozi($admin, 'Lista los productos');
    $resultado = geminiCuerpo(geminiPeticiones()[1])['contents'][2]['parts'][0]['functionResponse']['response']['datasets'][0];

    expect($resultado)->toMatchArray(['total_rows' => 2, 'total_matches' => 4, 'truncated' => true])
        ->and($resultado['rows'])->toHaveCount(1)
        ->and($resultado['description'])->toContain('2 de 4 coincidencias (recortado');
});

test('when the round limit is reached the provider may only call responder', function () {
    config(['agente_mozi.ia.rondas_herramientas' => 1]);
    [, $admin] = motorConProductos();
    geminiSimula([geminiLlama('query_business_data', ['source' => 'productos']), geminiResponde('Listo.')]);

    preguntarAMozi($admin, PREGUNTA_IA);
    [$primera, $segunda] = geminiPeticiones();

    expect(geminiCuerpo($primera)['toolConfig']['functionCallingConfig'])->not->toHaveKey('allowedFunctionNames')
        ->and(geminiCuerpo($segunda)['toolConfig']['functionCallingConfig']['allowedFunctionNames'])->toBe(['responder']);
});

test('invalid canvas items from the model are discarded and a KPI over many rows is never guessed', function () {
    [, $admin] = motorConProductos();
    geminiSimula([
        geminiLlama('query_business_data', ['source' => 'productos', 'fields' => ['nombre', 'stock']]),
        geminiResponde('Resumen.', [
            ['type' => 'kpi', 'title' => 'Stock', 'dataset' => 'd1', 'field' => 'stock'],
            ['type' => 'chart', 'title' => 'Inventado', 'dataset' => 'd1', 'chart_type' => 'bar', 'x_field' => 'nombre', 'series' => [['field' => 'ventas_secretas', 'label' => 'X']]],
            ['type' => 'kpi', 'title' => 'Otro dataset', 'dataset' => 'd7', 'field' => 'conteo'],
            ['type' => 'html', 'title' => 'Tipo libre', 'dataset' => 'd1'],
            ['type' => 'insight', 'title' => 'Lectura', 'text' => 'El stock es el actual de la sucursal.'],
        ]),
    ]);

    $respuesta = preguntarAMozi($admin, PREGUNTA_IA);

    expect($respuesta->artefactos->pluck('tipo')->all())->toBe([TipoArtefacto::Hallazgo])
        ->and($respuesta->artefactos->first()->meta)->toMatchArray(['origin' => 'ia'])
        ->and($respuesta->artefactos->first()->meta)->not->toHaveKey('synthetic')
        ->and(AgenteDataset::query()->count())->toBe(0);
});

test('provider outages are retried and then stored as a failed answer that keeps the question', function () {
    [, $admin] = empresaConAdministrador();
    geminiSimula([503, 503, 503]);

    $respuesta = preguntarAMozi($admin, PREGUNTA_IA);

    expect($respuesta->estado)->toBe(AgenteMensaje::FALLIDO)
        ->and($respuesta->texto)->toContain('no respondió a tiempo')
        ->and($respuesta->proveedor)->toBe('gemini')
        ->and(AgenteMensaje::query()->where('rol', AgenteMensaje::ROL_USUARIO)->sole()->texto)->toBe(PREGUNTA_IA);
    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
});

test('an exhausted daily provider quota is not retried and is explained to the user', function () {
    [, $admin] = empresaConAdministrador();
    Http::fake(['gemini.test/*' => Http::response(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'details' => [
        ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '58771s'],
    ]]], 429)]);

    $respuesta = preguntarAMozi($admin, PREGUNTA_IA);

    expect($respuesta->estado)->toBe(AgenteMensaje::FALLIDO)
        ->and($respuesta->texto)->toContain('límite de uso por hoy');
    Http::assertSentCount(1);
});

test('the company daily quota stops the turn before calling the provider, per company', function () {
    config(['agente_mozi.cuotas.mensajes_diarios' => 1]);
    [, $adminA] = empresaConAdministrador();
    [, $adminB] = empresaConAdministrador();
    geminiSimula([geminiResponde('Hola.', id: 'a'), geminiResponde('Hola.', id: 'b')]);

    expect(preguntarAMozi($adminA, 'Hola')->estado)->toBe(AgenteMensaje::COMPLETADO);

    $bloqueada = preguntarAMozi($adminA, 'Hola otra vez');

    expect($bloqueada->estado)->toBe(AgenteMensaje::FALLIDO)
        ->and($bloqueada->texto)->toContain('límite diario')
        ->and(preguntarAMozi($adminB, 'Hola')->estado)->toBe(AgenteMensaje::COMPLETADO);
    Http::assertSentCount(2);
});

test('without an API key the turn fails clearly and nothing is sent', function () {
    config(['agente_mozi.proveedores.gemini.api_key' => null]);
    [, $admin] = empresaConAdministrador();
    Http::fake();

    $respuesta = preguntarAMozi($admin, PREGUNTA_IA);

    expect($respuesta->estado)->toBe(AgenteMensaje::FALLIDO)
        ->and($respuesta->texto)->toContain('no tiene un proveedor de IA configurado');
    Http::assertNothingSent();
});

test('follow-up questions send the previous messages of the same conversation as text', function () {
    [, $admin] = empresaConAdministrador();
    geminiSimula([geminiResponde('Respuesta uno.', id: 'a'), geminiResponde('Respuesta dos.', id: 'b')]);

    $primera = preguntarAMozi($admin, 'Pregunta uno');
    preguntarAMozi($admin, 'Pregunta dos', $primera->conversacion_id);

    $contenidos = geminiCuerpo(geminiPeticiones()[1])['contents'];

    expect($contenidos)->toHaveCount(3)
        ->and($contenidos[0])->toBe(['role' => 'user', 'parts' => [['text' => 'Pregunta uno']]])
        ->and($contenidos[1]['role'])->toBe('model')
        ->and($contenidos[1]['parts'][0]['text'])->toBe('Respuesta uno.')
        ->and($contenidos[2]['parts'][0]['text'])->toBe('Pregunta dos');
});

test('the model can only write sandbox views when the server allows it', function () {
    [, $admin] = motorConProductos();
    $vista = ['type' => 'sandbox_web', 'title' => 'Vista', 'dataset' => 'd1'] + VistaCoberturaDemo::spec();
    $consulta = ['source' => 'productos', 'fields' => ['nombre', 'stock']];
    geminiSimula([
        geminiLlama('query_business_data', $consulta, 'a'), geminiResponde('Vista.', [$vista], 'b'),
        geminiLlama('query_business_data', $consulta, 'c'), geminiResponde('Vista.', [$vista], 'd'),
    ]);

    $sinPermiso = preguntarAMozi($admin, 'Crea una visualización personalizada');
    $tiposOfrecidos = function (int $i): array {
        $declaraciones = array_column(geminiCuerpo(geminiPeticiones()[$i])['tools'][0]['functionDeclarations'], null, 'name');
        $variantes = $declaraciones['responder']['parametersJsonSchema']['properties']['artifacts']['items']['anyOf'];

        return array_merge(...array_column(array_column(array_column($variantes, 'properties'), 'type'), 'enum'));
    };

    expect($sinPermiso->artefactos)->toHaveCount(0)
        ->and($tiposOfrecidos(0))->not->toContain('sandbox_web');

    config(['agente_mozi.sandbox_ia' => true]);
    $conPermiso = preguntarAMozi($admin, 'Crea una visualización personalizada');

    expect($conPermiso->artefactos->pluck('tipo')->all())->toBe([TipoArtefacto::SandboxWeb])
        ->and($conPermiso->artefactos->first()->meta['origin'])->toBe('ia')
        ->and($tiposOfrecidos(2))->toContain('sandbox_web');
});
