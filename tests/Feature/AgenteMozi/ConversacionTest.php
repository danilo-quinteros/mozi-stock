<?php

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\Contratos\Respondedor;
use App\Domain\AgenteMozi\DTOs\ArtefactoPropuesto;
use App\Domain\AgenteMozi\DTOs\DatasetPropuesto;
use App\Domain\AgenteMozi\DTOs\RespuestaAgente;
use App\Enums\TipoArtefacto;
use App\Livewire\AgenteMozi\Panel;
use App\Models\AgenteArtefacto;
use App\Models\AgenteConversacion;
use App\Models\AgenteDataset;
use App\Models\AgenteMensaje;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * Agente Mozi · Fase 1: persistencia básica de charlas, idempotencia del envío, demo sintética marcada
 * y aislamiento por usuario y empresa (docs/AGENTE_MOZI_IA.md §§ 7, 9 y 12).
 */

const PREGUNTA_STOCK = '¿Qué productos se van a quedar sin stock esta semana?';

test('sending a question stores the conversation, the answer, its datasets and its canvas items', function () {
    [, $admin] = empresaConAdministrador();

    $respuesta = preguntarAMozi($admin, PREGUNTA_STOCK);

    $conversacion = AgenteConversacion::query()->sole();
    expect($conversacion->usuario_id)->toBe($admin->id)
        ->and($conversacion->titulo)->toBe(PREGUNTA_STOCK)
        ->and($respuesta->rol)->toBe(AgenteMensaje::ROL_AGENTE)
        ->and($respuesta->estado)->toBe(AgenteMensaje::COMPLETADO)
        ->and($respuesta->proveedor)->toBe('demo')
        ->and($respuesta->texto)->toStartWith('**Modo demostración**')
        ->and(AgenteMensaje::query()->count())->toBe(2);

    $tipos = $respuesta->artefactos->map(fn (AgenteArtefacto $a) => $a->tipo)->all();
    expect($tipos)->toContain(TipoArtefacto::Kpi, TipoArtefacto::Grafico, TipoArtefacto::Tabla, TipoArtefacto::Hallazgo);

    // Toda cifra demo se marca como sintética: nunca se presenta como dato real de la empresa.
    $respuesta->artefactos->each(fn (AgenteArtefacto $a) => expect($a->meta['quality'])->toBe('demo')
        ->and($a->meta['synthetic'] ?? null)->toBeTrue());
    AgenteDataset::query()->get()->each(fn (AgenteDataset $d) => expect($d->usuario_id)->toBe($admin->id)
        ->and($d->ref)->toMatch('/^ds_[A-Za-z0-9]{32}$/')
        ->and($d->meta['synthetic'])->toBeTrue());
});

test('repeating a request with the same key returns the same answer without duplicating anything', function () {
    [, $admin] = empresaConAdministrador();
    $clave = (string) Str::uuid();

    $primera = preguntarAMozi($admin, PREGUNTA_STOCK, clave: $clave);
    $conteos = [AgenteMensaje::query()->count(), AgenteArtefacto::query()->count(), AgenteDataset::query()->count()];

    // Doble clic o reintento de red: misma clave, aunque llegue sin la conversación ya creada.
    $segunda = preguntarAMozi($admin, PREGUNTA_STOCK, clave: $clave);
    $tercera = preguntarAMozi($admin, PREGUNTA_STOCK, $primera->conversacion_id, $clave);

    expect($segunda->id)->toBe($primera->id)
        ->and($tercera->id)->toBe($primera->id)
        ->and([AgenteMensaje::query()->count(), AgenteArtefacto::query()->count(), AgenteDataset::query()->count()])->toBe($conteos)
        ->and(AgenteConversacion::query()->count())->toBe(1);
});

test('a key used by another user is never reused to reveal their answer', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $vendedor = User::factory()->vendedor($sucursal)->create();
    [, $otraEmpresa] = empresaConAdministrador();
    $clave = (string) Str::uuid();

    $original = preguntarAMozi($admin, PREGUNTA_STOCK, clave: $clave);

    // Mismo tenant, otro usuario: se rechaza sin devolver la respuesta ajena.
    expect(fn () => preguntarAMozi($vendedor, '¿Cuánto vendí?', clave: $clave))->toThrow(ValidationException::class);

    // Otro tenant: la clave no existe en su empresa y obtiene su propia respuesta.
    $ajena = preguntarAMozi($otraEmpresa, '¿Cuánto vendí?', clave: $clave);
    expect($ajena->id)->not->toBe($original->id)
        ->and($ajena->empresa_id)->toBe($otraEmpresa->empresa_id);
});

test('the screen sends questions, publishes them to the canvas and keeps the conversation locked', function () {
    [, $admin] = empresaConAdministrador();
    [, $otro] = empresaConAdministrador();
    $ajena = preguntarAMozi($otro, PREGUNTA_STOCK);

    $componente = Livewire::actingAs($admin)->test(Panel::class)
        ->call('enviar', PREGUNTA_STOCK, (string) Str::uuid())
        ->assertReturned(true)
        ->assertHasNoErrors()
        ->assertDispatched('agente-mozi-lienzo', fn (string $evento, array $datos) => $datos['foco'] !== null
            && $datos['analisis'][0]['demo'] === true
            && count($datos['analisis'][0]['artifacts']) > 0
            && collect($datos['analisis'][0]['artifacts'])->every(fn (array $a) => ! isset($a['spec']['js'])))
        ->assertSee('Productos en riesgo')
        ->assertSee('Ver gráfico')
        ->assertSee('Ver tabla');

    $propia = AgenteConversacion::query()->where('usuario_id', $admin->id)->sole();
    $componente->assertSet('conversacionId', $propia->id);

    // conversacionId es #[Locked]: el navegador no puede apuntar a otra charla.
    expect(fn () => $componente->set('conversacionId', $ajena->conversacion_id))->toThrow(CannotUpdateLockedPropertyException::class);
});

test('answers without canvas items do not move the canvas focus', function () {
    [, $admin] = empresaConAdministrador();

    Livewire::actingAs($admin)->test(Panel::class)
        ->call('enviar', 'Hola, ¿qué puedes hacer?', (string) Str::uuid())
        ->assertReturned(true)
        ->assertDispatched('agente-mozi-lienzo', fn (string $evento, array $datos) => $datos['foco'] === null)
        ->assertSee('Todavía estoy en modo demostración');

    expect(AgenteArtefacto::query()->count())->toBe(0);
});

test('empty, oversized or keyless messages are rejected and nothing is stored', function () {
    [, $admin] = empresaConAdministrador();
    $limite = (int) config('agente_mozi.limites.caracteres_mensaje');

    Livewire::actingAs($admin)->test(Panel::class)
        ->call('enviar', "   \u{0007} ", (string) Str::uuid())
        ->assertReturned(false)
        ->assertHasErrors('texto')
        ->call('enviar', str_repeat('a', $limite + 1), (string) Str::uuid())
        ->assertReturned(false)
        ->assertHasErrors('texto')
        ->call('enviar', PREGUNTA_STOCK, 'no-es-uuid')
        ->assertReturned(false)
        ->assertHasErrors('texto');

    expect(AgenteMensaje::query()->count())->toBe(0);
});

test('sending too many messages per minute is throttled per user', function () {
    [, $admin] = empresaConAdministrador();
    config(['agente_mozi.limites.mensajes_por_minuto' => 2]);
    RateLimiter::clear('agente-mozi:'.$admin->id);

    Livewire::actingAs($admin)->test(Panel::class)
        ->call('enviar', 'uno', (string) Str::uuid())->assertReturned(true)
        ->call('enviar', 'dos', (string) Str::uuid())->assertReturned(true)
        ->call('enviar', 'tres', (string) Str::uuid())->assertReturned(false)->assertHasErrors('texto');

    expect(AgenteMensaje::query()->where('rol', AgenteMensaje::ROL_USUARIO)->count())->toBe(2);
});

test('users reopen only their own conversations', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $vendedor = User::factory()->vendedor($sucursal)->create();
    [, $otraEmpresa] = empresaConAdministrador();

    $propia = preguntarAMozi($admin, PREGUNTA_STOCK);
    $deCompanero = preguntarAMozi($vendedor, '¿Cuánto vendí?');
    $deOtraEmpresa = preguntarAMozi($otraEmpresa, '¿Qué margen tengo?');

    $componente = Livewire::actingAs($admin)->test(Panel::class)
        ->assertSee(PREGUNTA_STOCK)
        ->assertDontSee('¿Cuánto vendí?')
        ->assertDontSee('¿Qué margen tengo?')
        ->call('abrir', $propia->conversacion_id)
        ->assertSet('conversacionId', $propia->conversacion_id)
        ->assertSee('Productos en riesgo');

    $componente->call('abrir', $deCompanero->conversacion_id)->assertForbidden();
    Livewire::actingAs($admin)->test(Panel::class)->call('abrir', $deOtraEmpresa->conversacion_id)->assertNotFound();

    // Continuar una charla ajena por la acción tampoco es posible.
    expect(fn () => preguntarAMozi($admin, 'sigo', $deCompanero->conversacion_id))->toThrow(AuthorizationException::class);
});

test('a failing responder keeps the question and stores a failed answer without inventing data', function () {
    [, $admin] = empresaConAdministrador();
    app()->instance(Respondedor::class, new class implements Respondedor
    {
        public function responder(ContextoAgente $contexto, string $pregunta, array $historial = [], ?Closure $avance = null): RespuestaAgente
        {
            throw new RuntimeException('proveedor caído');
        }
    });

    $respuesta = preguntarAMozi($admin, PREGUNTA_STOCK);

    expect($respuesta->estado)->toBe(AgenteMensaje::FALLIDO)
        ->and($respuesta->texto)->toContain('Tu pregunta quedó guardada')
        ->and(AgenteMensaje::query()->where('rol', AgenteMensaje::ROL_USUARIO)->sole()->texto)->toBe(PREGUNTA_STOCK)
        ->and(AgenteArtefacto::query()->count())->toBe(0)
        ->and(AgenteDataset::query()->count())->toBe(0);
});

test('invalid canvas items proposed by a responder are discarded, never stored', function () {
    [, $admin] = empresaConAdministrador();
    app()->instance(Respondedor::class, new class implements Respondedor
    {
        public function responder(ContextoAgente $contexto, string $pregunta, array $historial = [], ?Closure $avance = null): RespuestaAgente
        {
            $dataset = new DatasetPropuesto('d', [
                ['field' => 'producto', 'label' => 'Producto', 'type' => 'string'],
                ['field' => 'total', 'label' => 'Total', 'type' => 'money'],
            ], [['producto' => 'A', 'total' => 12.5]], ['quality' => 'actual']);

            return new RespuestaAgente('Texto', [
                new ArtefactoPropuesto(TipoArtefacto::Kpi, 'Total', 'd', ['field' => 'total']),
                new ArtefactoPropuesto(TipoArtefacto::Hallazgo, 'Nota', null, ['text' => 'Válido']),
            ], [$dataset], 'prueba', null);
        }
    });

    $respuesta = preguntarAMozi($admin, 'pregunta');

    // El dataset usa float (12.5) para dinero: se descarta junto con el KPI que dependía de él.
    expect($respuesta->artefactos->pluck('tipo')->all())->toBe([TipoArtefacto::Hallazgo])
        ->and(AgenteDataset::query()->count())->toBe(0);
});
