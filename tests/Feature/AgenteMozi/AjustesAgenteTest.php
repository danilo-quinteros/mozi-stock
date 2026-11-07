<?php

use App\Domain\AgenteMozi\Actions\GuardarAjustesAgente;
use App\Domain\AgenteMozi\Contratos\Respondedor;
use App\Domain\AgenteMozi\Demo\RespondedorDemo;
use App\Domain\AgenteMozi\Ia\Orquestador;
use App\Livewire\Admin\AgenteMozi;
use App\Models\AjusteGlobal;
use App\Models\Auditoria;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

/*
 * Superadministración › Agente Mozi (Fase 2): motor, modelo y cuotas solo para el superadmin, sin
 * mostrar nunca la clave; prueba de conexión simulada y uso agregado por empresa.
 */

const CLAVE_GEMINI_PRUEBA = 'clave-secreta-de-prueba-123';

beforeEach(function () {
    config([
        'agente_mozi.proveedores.gemini.api_key' => CLAVE_GEMINI_PRUEBA,
        'agente_mozi.proveedores.gemini.url' => 'https://gemini.test/v1beta',
    ]);
    Sleep::fake();
});

test('only the superadmin opens the agent settings, and the API key is never shown', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $vendedor = User::factory()->vendedor(Sucursal::factory()->create(['empresa_id' => $empresa->id]))->create();

    $this->actingAs(User::factory()->superadmin()->create())->get(route('admin.agente-mozi'))
        ->assertOk()
        ->assertSee('Google Gemini')
        ->assertSee('Configurada')
        ->assertDontSee(CLAVE_GEMINI_PRUEBA);

    $this->actingAs($admin)->get(route('admin.agente-mozi'))->assertForbidden();
    $this->actingAs($vendedor)->get(route('admin.agente-mozi'))->assertForbidden();
});

test('a provider without a server key cannot be activated', function () {
    config(['agente_mozi.proveedores.gemini.api_key' => null]);

    Livewire::actingAs(User::factory()->superadmin()->create())->test(AgenteMozi::class)
        ->assertSee('Sin configurar')
        ->set('motor', 'gemini')->set('modelo', 'gemini-3.1-flash-lite')
        ->call('guardar')
        ->assertHasErrors('motor');

    expect(AjusteGlobal::actual()->agente_motor)->toBeNull()
        ->and(Auditoria::query()->count())->toBe(0)
        ->and(app(Respondedor::class))->toBeInstanceOf(RespondedorDemo::class);
});

test('the superadmin activates Gemini with an enabled model and quotas, audited without secrets', function () {
    Livewire::actingAs(User::factory()->superadmin()->create())->test(AgenteMozi::class)
        ->set('motor', 'gemini')->set('modelo', 'modelo-inventado')
        ->call('guardar')
        ->assertHasErrors('modelo')
        ->set('modelo', 'gemini-3.1-flash-lite')->set('mensajesDiarios', 50)->set('tokensDiarios', 200000)
        ->call('guardar')
        ->assertHasNoErrors();

    $ajustes = AjusteGlobal::actual()->refresh();
    $auditoria = Auditoria::query()->sole();

    expect($ajustes->agente_motor)->toBe('gemini')
        ->and($ajustes->agente_modelo)->toBe('gemini-3.1-flash-lite')
        ->and($ajustes->agente_mensajes_diarios)->toBe(50)
        ->and($ajustes->agente_tokens_diarios)->toBe(200000)
        ->and($auditoria->accion)->toBe('agente_mozi.ajustes_actualizados')
        ->and($auditoria->empresa_id)->toBeNull()
        ->and($auditoria->despues)->toBe(['motor' => 'gemini', 'modelo' => 'gemini-3.1-flash-lite', 'mensajes_diarios' => 50, 'tokens_diarios' => 200000])
        ->and(json_encode($auditoria->toArray()))->not->toContain(CLAVE_GEMINI_PRUEBA)
        ->and(app(Respondedor::class))->toBeInstanceOf(Orquestador::class);
});

test('an administrator cannot change the agent settings even calling the action directly', function () {
    [, $admin] = empresaConAdministrador();

    Livewire::actingAs($admin)->test(AgenteMozi::class)->assertForbidden();

    expect(fn () => app(GuardarAjustesAgente::class)->ejecutar($admin, 'gemini', 'gemini-3.1-flash-lite', 10, 10000))
        ->toThrow(AuthorizationException::class);
});

test('the connection check reports the model version without exposing the key', function () {
    Http::fake(['gemini.test/*' => Http::response(['name' => 'models/gemini-3.1-flash-lite', 'version' => '3.1-prueba'])]);

    Livewire::actingAs(User::factory()->superadmin()->create())->test(AgenteMozi::class)
        ->set('motor', 'gemini')->set('modelo', 'gemini-3.1-flash-lite')
        ->call('probar', 'gemini')
        ->assertSee('Conexión correcta con gemini-3.1-flash-lite · 3.1-prueba')
        ->assertDontSee(CLAVE_GEMINI_PRUEBA);

    Http::assertSent(fn ($peticion) => $peticion->url() === 'https://gemini.test/v1beta/models/gemini-3.1-flash-lite'
        && $peticion->header('x-goog-api-key') === [CLAVE_GEMINI_PRUEBA]);
});

test('usage is aggregated per company and the chat tells users the AI in testing reads their real data', function () {
    config(['agente_mozi.motor' => 'gemini']);
    [, $admin] = empresaConAdministrador(['nombre' => 'Bazar Uso IA']);
    Http::fake(['gemini.test/*' => Http::response([
        'candidates' => [['content' => ['role' => 'model', 'parts' => [
            ['functionCall' => ['name' => 'responder', 'args' => ['message' => 'Hola.', 'artifacts' => []], 'id' => 'r1'], 'thoughtSignature' => 'firma'],
        ]], 'finishReason' => 'STOP']],
        'usageMetadata' => ['promptTokenCount' => 1000, 'candidatesTokenCount' => 234],
        'modelVersion' => 'gemini-3.5-flash',
    ])]);

    preguntarAMozi($admin, 'Hola');

    $this->get(route('agente-mozi'))->assertOk()
        ->assertSee('IA en pruebas: el asistente consulta los datos reales de tu empresa.')
        ->assertDontSee('datos sintéticos')
        ->assertSee('¿Qué sucursal vendió más este mes?')
        ->assertDontSee('¿Qué productos dejan más margen?');

    Livewire::actingAs(User::factory()->superadmin()->create())->test(AgenteMozi::class)
        ->assertSeeInOrder(['Bazar Uso IA', '1', '1', '0', '1.234']);
});
