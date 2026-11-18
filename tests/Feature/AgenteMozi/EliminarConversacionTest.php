<?php

use App\Domain\AgenteMozi\Actions\EliminarConversacion;
use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\Contratos\Respondedor;
use App\Domain\AgenteMozi\DTOs\ArtefactoPropuesto;
use App\Domain\AgenteMozi\DTOs\DatasetPropuesto;
use App\Domain\AgenteMozi\DTOs\RespuestaAgente;
use App\Domain\AgenteMozi\Ia\MedidorUso;
use App\Enums\TipoArtefacto;
use App\Livewire\AgenteMozi\Panel;
use App\Models\AgenteArtefacto;
use App\Models\AgenteConversacion;
use App\Models\AgenteDataset;
use App\Models\AgenteMensaje;
use App\Models\Auditoria;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * Agente Mozi: el autor elimina una charla después de confirmarlo. Se borra el contenido (texto, datasets,
 * artefactos y sus descargas) pero se conservan los contadores de uso: la cuota diaria no se reinicia.
 */

test('the author deletes a conversation only after confirming: content, datasets and views are gone, usage is kept', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $vista = preguntarAMozi($admin, 'Crea una visualización personalizada de la cobertura de stock');
    $seguimiento = preguntarAMozi($admin, '¿Qué productos se van a quedar sin stock esta semana?', $vista->conversacion_id);
    $conversacionId = $vista->conversacion_id;

    $dataset = AgenteDataset::query()->where('conversacion_id', $conversacionId)->firstOrFail();
    $sandbox = AgenteArtefacto::query()->where('conversacion_id', $conversacionId)->where('tipo', TipoArtefacto::SandboxWeb)->firstOrFail();
    $usoAntes = app(MedidorUso::class)->hoy($empresa->id, 'demo');
    $conteos = ['mensajes' => 4, 'datasets' => AgenteDataset::query()->where('conversacion_id', $conversacionId)->count(),
        'artefactos' => AgenteArtefacto::query()->where('conversacion_id', $conversacionId)->count()];
    expect($usoAntes['mensajes'])->toBe(2)
        ->and($seguimiento->conversacion_id)->toBe($conversacionId);
    $this->actingAs($admin)->getJson(route('agente-mozi.dataset', $dataset->ref))->assertOk();
    // Las acciones llevan nombres de la empresa (clientes, productos): también se borran.
    $seguimiento->forceFill(['acciones' => [['tipo' => 'abrir_pedido', 'id' => 1, 'etiqueta' => 'Abrir pedido PED-00001 · Cliente secreto']]])->save();

    $componente = Livewire::actingAs($admin)->test(Panel::class)
        ->call('abrir', $conversacionId)
        ->assertSee('Crea una visualización personalizada')
        ->call('confirmarEliminar', $conversacionId)
        ->assertSet('eliminandoId', $conversacionId)
        ->assertSee('¿Eliminar esta charla?')
        ->assertSee('No se puede deshacer');

    // Hasta confirmar no se borra nada.
    expect(AgenteConversacion::query()->find($conversacionId)?->eliminada())->toBeFalse()
        ->and(AgenteDataset::query()->where('conversacion_id', $conversacionId)->exists())->toBeTrue();

    $componente->call('eliminar')
        ->assertSet('eliminandoId', null)
        ->assertSet('conversacionId', null)
        ->assertDispatched('agente-mozi-lienzo', fn (string $evento, array $datos) => $datos['analisis'] === [] && $datos['foco'] === null)
        ->assertDontSee('¿Eliminar esta charla?')
        // La pregunta sigue entre las sugerencias de bienvenida, pero la charla ya no está en el historial.
        ->assertSee('Todavía no tienes charlas.');

    $conversacion = AgenteConversacion::query()->findOrFail($conversacionId);
    $mensajes = AgenteMensaje::query()->where('conversacion_id', $conversacionId)->get();
    expect($conversacion->eliminada())->toBeTrue()
        ->and($conversacion->titulo)->toBe('')
        ->and($mensajes)->toHaveCount(4)
        ->and($mensajes->pluck('texto')->unique()->all())->toBe([''])
        ->and($mensajes->pluck('acciones')->filter()->all())->toBe([])
        ->and($mensajes->where('rol', AgenteMensaje::ROL_AGENTE)->pluck('proveedor')->unique()->all())->toBe(['demo'])
        ->and(AgenteDataset::query()->where('conversacion_id', $conversacionId)->count())->toBe(0)
        ->and(AgenteArtefacto::query()->where('conversacion_id', $conversacionId)->count())->toBe(0)
        // La cuota diaria se calcula con las filas de uso: eliminar no la reinicia.
        ->and(app(MedidorUso::class)->hoy($empresa->id, 'demo'))->toBe($usoAntes);

    $this->actingAs($admin)->getJson(route('agente-mozi.dataset', $dataset->ref))->assertNotFound();
    $this->actingAs($admin)->get(route('agente-mozi.dataset.csv', $dataset->ref))->assertNotFound();
    $this->actingAs($admin)->get(route('agente-mozi.sandbox', $sandbox->id))->assertNotFound();

    // Auditoría sin contenido de la charla.
    $auditoria = Auditoria::query()->where('accion', 'agente_mozi.conversacion_eliminada')->sole();
    expect($auditoria->entidad_id)->toBe($conversacionId)
        ->and($auditoria->usuario_id)->toBe($admin->id)
        ->and($auditoria->empresa_id)->toBe($empresa->id)
        ->and($auditoria->despues)->toBe($conteos)
        ->and($conteos['datasets'])->toBeGreaterThan(0)
        ->and(json_encode($auditoria->getAttributes()))->not->toContain('visualizaci');
});

test('cancelling the confirmation keeps the conversation, and the pending one cannot be changed from the browser', function () {
    [, $admin] = empresaConAdministrador();
    [, $otro] = empresaConAdministrador();
    $propia = preguntarAMozi($admin, '¿Cuántos clientes nuevos tuve?');
    $ajena = preguntarAMozi($otro, '¿Qué margen tengo?');

    $componente = Livewire::actingAs($admin)->test(Panel::class)
        ->call('confirmarEliminar', $propia->conversacion_id)
        ->call('cancelarEliminar')
        ->assertSet('eliminandoId', null)
        ->assertDontSee('¿Eliminar esta charla?')
        // Sin confirmación pendiente, eliminar() no hace nada.
        ->call('eliminar')
        ->assertSee('¿Cuántos clientes nuevos tuve?');

    // eliminandoId es #[Locked]: el navegador no puede cambiar qué charla se elimina.
    expect(fn () => $componente->set('eliminandoId', $ajena->conversacion_id))->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(AgenteConversacion::query()->whereNotNull('eliminada_en')->count())->toBe(0)
        ->and(AgenteMensaje::query()->where('conversacion_id', $propia->conversacion_id)->where('texto', '')->count())->toBe(0);
});

test('only the author can delete a conversation: never a coworker of the same company nor another company', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $vendedor = User::factory()->vendedor($sucursal)->create();
    [, $otraEmpresa] = empresaConAdministrador();
    $propia = preguntarAMozi($admin, '¿Cuántos clientes nuevos tuve?');

    Livewire::actingAs($vendedor)->test(Panel::class)->call('confirmarEliminar', $propia->conversacion_id)->assertForbidden();
    Livewire::actingAs($otraEmpresa)->test(Panel::class)->call('confirmarEliminar', $propia->conversacion_id)->assertNotFound();

    $this->actingAs($vendedor);
    expect(fn () => app(EliminarConversacion::class)->ejecutar($vendedor, $propia->conversacion_id))->toThrow(AuthorizationException::class);
    $this->actingAs($otraEmpresa);
    expect(fn () => app(EliminarConversacion::class)->ejecutar($otraEmpresa, $propia->conversacion_id))->toThrow(ModelNotFoundException::class);

    $this->actingAs($admin);
    expect(AgenteConversacion::query()->findOrFail($propia->conversacion_id)->eliminada())->toBeFalse()
        ->and(AgenteMensaje::query()->findOrFail($propia->id)->texto)->not->toBe('')
        ->and(Auditoria::query()->where('accion', 'agente_mozi.conversacion_eliminada')->count())->toBe(0);
});

test('a deleted conversation cannot be reopened, continued or replayed, and deleting it twice does nothing', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $vendedor = User::factory()->vendedor($sucursal)->create();
    $clave = (string) Str::uuid();
    $respuesta = preguntarAMozi($admin, '¿Cuántos clientes nuevos tuve?', clave: $clave);
    $conversacionId = $respuesta->conversacion_id;

    app(EliminarConversacion::class)->ejecutar($admin, $conversacionId);
    // Doble clic u otra pestaña: no es un error ni deja otra auditoría.
    app(EliminarConversacion::class)->ejecutar($admin, $conversacionId);
    expect(Auditoria::query()->where('accion', 'agente_mozi.conversacion_eliminada')->count())->toBe(1);

    // Continuarla o repetir el envío original no la revive ni devuelve su respuesta.
    expect(fn () => preguntarAMozi($admin, 'sigo', $conversacionId))
        ->toThrow(ValidationException::class, 'Esta charla fue eliminada. Inicia una nueva charla.');
    expect(fn () => preguntarAMozi($admin, '¿Cuántos clientes nuevos tuve?', clave: $clave))->toThrow(ValidationException::class);
    // A otro usuario no se le revela que existió: recibe la denegación de siempre.
    expect(fn () => preguntarAMozi($vendedor, 'sigo', $conversacionId))->toThrow(AuthorizationException::class);

    // Abrirla desde un historial viejo lleva a una charla nueva, sin error.
    Livewire::actingAs($admin)->test(Panel::class)
        ->call('abrir', $conversacionId)
        ->assertSet('conversacionId', null)
        ->assertHasNoErrors()
        ->call('confirmarEliminar', $conversacionId)
        ->assertForbidden();

    expect(AgenteMensaje::query()->where('conversacion_id', $conversacionId)->count())->toBe(2)
        ->and(AgenteMensaje::query()->where('conversacion_id', $conversacionId)->where('texto', '!=', '')->count())->toBe(0);
});

test('an answer that finishes after its conversation was deleted keeps only its usage', function () {
    [$empresa, $admin] = empresaConAdministrador();
    app()->instance(Respondedor::class, new class implements Respondedor
    {
        public function responder(ContextoAgente $contexto, string $pregunta, array $historial = [], ?Closure $avance = null): RespuestaAgente
        {
            // El autor elimina la charla desde otra pestaña mientras el motor responde.
            /** @var User $autor */
            $autor = auth()->user();
            app(EliminarConversacion::class)->ejecutar($autor, (int) AgenteConversacion::query()->latest('id')->value('id'));

            $dataset = new DatasetPropuesto('d', [['field' => 'producto', 'label' => 'Producto', 'type' => 'string']], [['producto' => 'A']], ['quality' => 'actual']);

            return new RespuestaAgente('Respuesta con datos', [new ArtefactoPropuesto(TipoArtefacto::Tabla, 'Tabla', 'd', ['columns' => [['field' => 'producto', 'label' => 'Producto']]])], [$dataset], 'demo');
        }
    });

    $respuesta = preguntarAMozi($admin, '¿Qué vendí?');

    expect($respuesta->texto)->toBe('')
        ->and($respuesta->estado)->toBe(AgenteMensaje::COMPLETADO)
        ->and($respuesta->proveedor)->toBe('demo')
        ->and(AgenteMensaje::query()->where('conversacion_id', $respuesta->conversacion_id)->where('texto', '!=', '')->count())->toBe(0)
        ->and(AgenteDataset::query()->count())->toBe(0)
        ->and(AgenteArtefacto::query()->count())->toBe(0)
        ->and(app(MedidorUso::class)->hoy($empresa->id, 'demo')['mensajes'])->toBe(1);
});
