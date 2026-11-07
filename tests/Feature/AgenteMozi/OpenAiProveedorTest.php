<?php

use App\Enums\TipoArtefacto;
use App\Livewire\Admin\AgenteMozi;
use App\Models\AgenteDataset;
use App\Models\AgenteMensaje;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

/*
 * Agente Mozi · adaptador de OpenAI (API Responses simulada con Http::fake, nunca la API real): el
 * mismo orquestador, herramientas y Lienzo que con Gemini; razonamiento cifrado reenviado sin guardar
 * estado en OpenAI (store=false), errores de cuota frente a límites por minuto y la clave fuera del
 * cuerpo y de los mensajes (docs/AGENTE_MOZI_IA.md § 4 y docs/AGENTE_MOZI_INTEGRACION.md).
 */

const CLAVE_OPENAI_PRUEBA = 'sk-prueba-secreta-123';

beforeEach(function () {
    config([
        'agente_mozi.motor' => 'openai',
        'agente_mozi.proveedores.openai.api_key' => CLAVE_OPENAI_PRUEBA,
        'agente_mozi.proveedores.openai.url' => 'https://openai.test/v1',
        'agente_mozi.proveedores.openai.modelo' => 'gpt-6.1-terra',
    ]);
    Sleep::fake();
});

/**
 * Respuesta simulada de /responses: un ítem de razonamiento cifrado y una llamada a función.
 *
 * @param  array<string, mixed>  $argumentos
 * @return array<string, mixed>
 */
function openaiLlama(string $nombre, array $argumentos, string $id = 'c1'): array
{
    return [
        'id' => 'resp_'.$id,
        'object' => 'response',
        'status' => 'completed',
        'model' => 'gpt-6.1-terra-prueba',
        'output' => [
            ['type' => 'reasoning', 'id' => 'rs_'.$id, 'summary' => [], 'encrypted_content' => 'cifrado-'.$id],
            ['type' => 'function_call', 'id' => 'fc_'.$id, 'call_id' => 'call_'.$id, 'name' => $nombre,
                'arguments' => json_encode((object) $argumentos, JSON_UNESCAPED_UNICODE), 'status' => 'completed'],
        ],
        'usage' => ['input_tokens' => 100, 'output_tokens' => 25, 'output_tokens_details' => ['reasoning_tokens' => 5], 'total_tokens' => 125],
    ];
}

/**
 * @param  list<array<string, mixed>>  $artefactos
 * @return array<string, mixed>
 */
function openaiResponde(string $mensaje, array $artefactos = [], string $id = 'c9'): array
{
    return openaiLlama('responder', ['message' => $mensaje, 'artifacts' => $artefactos], $id);
}

/**
 * @param  list<array<string, mixed>|array{0: int, 1: array<string, mixed>, 2?: array<string, string>}>  $respuestas  cuerpos 200 o [estado, cuerpo, cabeceras]
 */
function openaiSimula(array $respuestas): void
{
    $secuencia = Http::sequence();

    foreach ($respuestas as $respuesta) {
        array_is_list($respuesta) ? $secuencia->push($respuesta[1], $respuesta[0], $respuesta[2] ?? []) : $secuencia->push($respuesta);
    }

    Http::fake(['openai.test/*' => $secuencia]);
}

/**
 * @return list<array<string, mixed>>
 */
function openaiCuerpos(): array
{
    return Http::recorded()->map(fn (array $par) => json_decode($par[0]->body(), true, flags: JSON_THROW_ON_ERROR))->values()->all();
}

test('an OpenAI turn runs the tools on real data, replays the encrypted reasoning statelessly and stores the answer with usage', function () {
    [, $admin] = motorConProductos(['nombre' => 'Ferretería Secreta']);
    openaiSimula([
        openaiLlama('query_business_data', ['source' => 'productos', 'fields' => ['nombre', 'stock'], 'sort' => ['field' => 'stock', 'direction' => 'asc']], 'c1'),
        openaiResponde('Hay **4 productos** con stock.', [
            ['type' => 'table', 'title' => 'Stock', 'dataset' => 'd1', 'columns' => [
                ['field' => 'nombre', 'label' => 'Producto', 'format' => 'text'],
                ['field' => 'stock', 'label' => 'Stock', 'format' => 'number'],
            ]],
            ['type' => 'chart', 'title' => 'Stock por producto', 'dataset' => 'd1', 'chart_type' => 'bar', 'x_field' => 'nombre',
                'series' => [['field' => 'stock', 'label' => 'Unidades']], 'orientation' => 'horizontal'],
        ]),
    ]);

    $respuesta = preguntarAMozi($admin, '¿Qué productos tienen menos stock?');
    $peticiones = Http::recorded()->map(fn (array $par) => $par[0])->values();
    [$primera, $segunda] = openaiCuerpos();

    expect($respuesta->estado)->toBe(AgenteMensaje::COMPLETADO)
        ->and($respuesta->proveedor)->toBe('openai')
        ->and($respuesta->modelo)->toBe('gpt-6.1-terra-prueba')
        ->and($respuesta->tokens_entrada)->toBe(200)
        ->and($respuesta->tokens_salida)->toBe(50)
        ->and($respuesta->herramientas_usadas)->toBe(1)
        ->and($respuesta->texto)->toBe('Hay **4 productos** con stock.')
        ->and($respuesta->artefactos->pluck('tipo')->all())->toBe([TipoArtefacto::Tabla, TipoArtefacto::Grafico])
        ->and(AgenteDataset::query()->sole()->meta)->toMatchArray(['quality' => 'actual', 'source' => 'mozistock.productos']);

    // La clave viaja solo como Bearer; el cuerpo no la lleva ni el nombre de la empresa; OpenAI no guarda estado.
    expect($peticiones[0]->url())->toBe('https://openai.test/v1/responses')
        ->and($peticiones[0]->header('Authorization'))->toBe(['Bearer '.CLAVE_OPENAI_PRUEBA])
        ->and($peticiones[0]->body())->not->toContain(CLAVE_OPENAI_PRUEBA)
        ->and($peticiones[1]->body())->not->toContain('Ferretería Secreta')
        ->and($primera)->toMatchArray(['model' => 'gpt-6.1-terra', 'store' => false, 'include' => ['reasoning.encrypted_content'],
            'reasoning' => ['effort' => 'low'], 'tool_choice' => 'required', 'max_output_tokens' => 8192])
        ->and($primera)->not->toHaveKey('temperature')
        ->and($primera['instructions'])->toContain('Rol del usuario: administrador')
        ->and($primera['input'])->toBe([['role' => 'user', 'content' => '¿Qué productos tienen menos stock?']])
        ->and(array_column($primera['tools'], 'name'))->toBe(['query_business_data', 'analyze_receivables', 'get_payables_schedule', 'forecast_cash_flow',
            'review_cash_shifts', 'analyze_operating_expenses', 'get_branch_comparison', 'analyze_pending_orders', 'analyze_returns', 'responder'])
        ->and($primera['tools'][0])->toMatchArray(['type' => 'function', 'strict' => false])
        ->and($primera['tools'][0]['parameters']['type'])->toBe('object');

    // Segunda petición: historial completo, razonamiento cifrado y llamada idénticos, y el resultado con datos reales.
    [$usuario, $razonamiento, $llamada, $resultado] = $segunda['input'];
    $dataset = json_decode($resultado['output'], true)['datasets'][0];

    expect($segunda['input'])->toHaveCount(4)
        ->and($usuario['role'])->toBe('user')
        ->and($razonamiento)->toBe(['type' => 'reasoning', 'id' => 'rs_c1', 'summary' => [], 'encrypted_content' => 'cifrado-c1'])
        ->and($llamada)->toMatchArray(['type' => 'function_call', 'call_id' => 'call_c1', 'name' => 'query_business_data'])
        ->and($resultado)->toMatchArray(['type' => 'function_call_output', 'call_id' => 'call_c1'])
        ->and($dataset)->toMatchArray(['dataset' => 'd1', 'synthetic' => false, 'quality' => 'actual', 'total_rows' => 4])
        ->and($dataset['rows'][0])->toBe(['nombre' => 'Leche', 'stock' => '3.000']);
});

test('the same question produces the same canvas structure with Gemini and OpenAI', function () {
    config([
        'agente_mozi.proveedores.gemini.api_key' => 'clave-gemini',
        'agente_mozi.proveedores.gemini.url' => 'https://gemini.test/v1beta',
    ]);
    [, $admin] = motorConProductos();
    $consulta = ['source' => 'productos', 'fields' => ['nombre', 'stock'], 'filters' => [['field' => 'nombre', 'op' => 'contains', 'value' => 'o']]];
    $lienzo = [
        ['type' => 'table', 'title' => 'Productos', 'dataset' => 'd1', 'columns' => [['field' => 'nombre', 'label' => 'Producto', 'format' => 'text']]],
        ['type' => 'chart', 'title' => 'Stock', 'dataset' => 'd1', 'chart_type' => 'bar', 'x_field' => 'nombre', 'series' => [['field' => 'stock', 'label' => 'Unidades']]],
    ];
    $geminiLlamada = fn (string $nombre, array $argumentos, string $id) => [
        'candidates' => [['content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => $nombre, 'args' => $argumentos, 'id' => $id]]]], 'finishReason' => 'STOP']],
        'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
    ];
    Http::fake([
        'openai.test/*' => Http::sequence()->push(openaiLlama('query_business_data', $consulta, 'a'))->push(openaiResponde('Arroz, Jabón y Pollo.', $lienzo, 'b')),
        'gemini.test/*' => Http::sequence()->push($geminiLlamada('query_business_data', $consulta, 'a'))
            ->push($geminiLlamada('responder', ['message' => 'Arroz, Jabón y Pollo.', 'artifacts' => $lienzo], 'b')),
    ]);

    $conOpenAi = preguntarAMozi($admin, '¿Qué nombre de producto tiene la vocal o?');
    config(['agente_mozi.motor' => 'gemini']);
    $conGemini = preguntarAMozi($admin, '¿Qué nombre de producto tiene la vocal o?');

    expect($conOpenAi->proveedor)->toBe('openai')
        ->and($conGemini->proveedor)->toBe('gemini')
        ->and($conOpenAi->artefactos->pluck('tipo')->all())->toBe([TipoArtefacto::Tabla, TipoArtefacto::Grafico])
        ->and($conGemini->artefactos->pluck('tipo')->all())->toBe($conOpenAi->artefactos->pluck('tipo')->all())
        ->and($conGemini->artefactos->pluck('spec')->all())->toBe($conOpenAi->artefactos->pluck('spec')->all());
});

test('when the round limit is reached OpenAI is forced to call responder', function () {
    config(['agente_mozi.ia.rondas_herramientas' => 1]);
    [, $admin] = motorConProductos();
    openaiSimula([openaiLlama('query_business_data', ['source' => 'productos']), openaiResponde('Listo.')]);

    preguntarAMozi($admin, 'Cuántos productos hay');
    [$primera, $segunda] = openaiCuerpos();

    expect($primera['tool_choice'])->toBe('required')
        ->and($segunda['tool_choice'])->toBe(['type' => 'function', 'name' => 'responder']);
});

test('a seller gets the same restricted tools with OpenAI and a forbidden source is refused', function () {
    [$empresa] = empresaConAdministrador();
    $vendedor = User::factory()->vendedor(Sucursal::factory()->create(['empresa_id' => $empresa->id]))->create();
    openaiSimula([openaiLlama('query_business_data', ['source' => 'cuentas_por_cobrar'], 'c1'), openaiResponde('No tienes permiso.')]);

    preguntarAMozi($vendedor, '¿Quién me debe más?');
    [$primera, $segunda] = openaiCuerpos();

    expect(array_column($primera['tools'], 'name'))->toBe(['query_business_data', 'review_cash_shifts', 'analyze_pending_orders', 'responder'])
        ->and(json_decode($segunda['input'][3]['output'], true)['error'])->toContain('Argumentos inválidos')
        ->and(AgenteDataset::query()->count())->toBe(0);
});

test('a per-minute rate limit is retried honoring Retry-After, without copying the error message', function () {
    [, $admin] = empresaConAdministrador();
    openaiSimula([
        [429, ['error' => ['message' => 'Rate limit reached for sk-prue***123', 'type' => 'requests', 'code' => 'rate_limit_exceeded']], ['Retry-After' => '3']],
        openaiResponde('Hola.'),
    ]);

    $respuesta = preguntarAMozi($admin, 'Hola');

    expect($respuesta->estado)->toBe(AgenteMensaje::COMPLETADO)
        ->and($respuesta->texto)->toBe('Hola.');
    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);
    Sleep::assertSequence([Sleep::for(3000)->milliseconds()]);
});

test('an exhausted OpenAI balance or spend limit is not retried and is explained to the user', function (string $codigo) {
    [, $admin] = empresaConAdministrador();
    openaiSimula([[429, ['error' => ['message' => 'You exceeded your current quota', 'type' => 'insufficient_quota', 'code' => $codigo]]]]);

    $respuesta = preguntarAMozi($admin, 'Hola');

    expect($respuesta->estado)->toBe(AgenteMensaje::FALLIDO)
        ->and($respuesta->texto)->toContain('límite de uso por hoy');
    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
})->with(['insufficient_quota', 'credit_balance_exhausted', 'project_spend_limit_exceeded']);

test('an overloaded OpenAI is retried and then the answer fails keeping the question', function () {
    [, $admin] = empresaConAdministrador();
    $saturado = [503, ['error' => ['message' => 'The server is overloaded', 'type' => 'service_unavailable_error', 'code' => 'server_is_overloaded']]];
    openaiSimula([$saturado, $saturado, $saturado]);

    $respuesta = preguntarAMozi($admin, '¿Cuánto vendí hoy?');

    expect($respuesta->estado)->toBe(AgenteMensaje::FALLIDO)
        ->and($respuesta->texto)->toContain('no respondió a tiempo')
        ->and($respuesta->proveedor)->toBe('openai')
        ->and(AgenteMensaje::query()->where('rol', AgenteMensaje::ROL_USUARIO)->sole()->texto)->toBe('¿Cuánto vendí hoy?');
    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
});

test('an invalid key is not retried and never reaches the logs or the user', function () {
    [, $admin] = empresaConAdministrador();
    openaiSimula([[401, ['error' => ['message' => 'Incorrect API key provided: sk-prue***123.', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']]]]);

    $respuesta = preguntarAMozi($admin, 'Hola');

    expect($respuesta->estado)->toBe(AgenteMensaje::FALLIDO)
        ->and($respuesta->texto)->not->toContain('sk-prue');
    Http::assertSentCount(1);
});

test('a response cut by the output token limit without content fails cleanly', function () {
    [, $admin] = empresaConAdministrador();
    openaiSimula([['id' => 'resp_x', 'status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'output' => [],
        'usage' => ['input_tokens' => 50, 'output_tokens' => 8192]]]);

    $respuesta = preguntarAMozi($admin, 'Hola');

    expect($respuesta->estado)->toBe(AgenteMensaje::FALLIDO)
        ->and($respuesta->proveedor)->toBe('openai');
    Http::assertSentCount(1);
});

test('the superadmin sees OpenAI and the connection check never exposes the key', function () {
    Http::fake(['openai.test/*' => Http::response(['id' => 'gpt-6-luna', 'object' => 'model', 'created' => 1780000000, 'owned_by' => 'openai'])]);

    Livewire::actingAs(User::factory()->superadmin()->create())->test(AgenteMozi::class)
        ->assertSee('OpenAI')
        ->set('motor', 'openai')->set('modelo', 'gpt-6-luna')
        ->call('probar', 'openai')
        ->assertSee('Conexión correcta con gpt-6-luna')
        ->assertDontSee(CLAVE_OPENAI_PRUEBA);

    Http::assertSent(fn (Request $peticion) => $peticion->url() === 'https://openai.test/v1/models/gpt-6-luna'
        && $peticion->header('Authorization') === ['Bearer '.CLAVE_OPENAI_PRUEBA]);
});
