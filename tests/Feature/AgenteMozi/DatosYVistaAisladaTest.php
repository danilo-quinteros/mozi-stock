<?php

use App\Domain\AgenteMozi\Lienzo\DocumentoSandbox;
use App\Domain\AgenteMozi\Lienzo\ProtocoloLienzo;
use App\Enums\TipoArtefacto;
use App\Models\AgenteArtefacto;
use App\Models\AgenteDataset;
use App\Models\Sucursal;
use App\Models\User;

/*
 * Agente Mozi · Fase 1: los datasets del Lienzo y la vista aislada sandbox_web se sirven solo a su
 * dueño, dentro de su empresa, y la vista lleva la CSP que la deja sin red ni acceso al origen.
 */

const PREGUNTA_VISTA = 'Crea una visualización personalizada de la cobertura de stock';

test('the dataset endpoint returns the typed contract only to its owner', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $vendedor = User::factory()->vendedor($sucursal)->create();
    [, $otraEmpresa] = empresaConAdministrador();

    $respuesta = preguntarAMozi($admin, '¿Qué productos se van a quedar sin stock esta semana?');
    $dataset = AgenteDataset::query()->where('mensaje_id', $respuesta->id)->whereJsonLength('filas', 8)->firstOrFail();

    $this->actingAs($admin)->getJson(route('agente-mozi.dataset', $dataset->ref))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertJsonPath('dataset_ref', $dataset->ref)
        ->assertJsonPath('schema_version', '1.0')
        ->assertJsonPath('row_count', 8)
        ->assertJsonPath('meta.quality', 'demo')
        ->assertJsonPath('meta.synthetic', true)
        ->assertJsonPath('columns.0.field', 'producto')
        // Los decimales viajan como texto, nunca como float.
        ->assertJsonPath('rows.1.cobertura_dias', '3.0')
        ->assertJsonPath('rows.6.cobertura_dias', null);

    expect($this->actingAs($admin)->get(route('agente-mozi.dataset', $dataset->ref))->headers->get('Cache-Control'))->toContain('no-store');

    $this->actingAs($vendedor)->getJson(route('agente-mozi.dataset', $dataset->ref))->assertNotFound();
    $this->actingAs($otraEmpresa)->getJson(route('agente-mozi.dataset', $dataset->ref))->assertNotFound();
    $this->actingAs($admin)->getJson(route('agente-mozi.dataset', 'ds_corto'))->assertNotFound();
    $this->actingAs($admin)->getJson(route('agente-mozi.dataset', $dataset->id))->assertNotFound();
});

test('expired datasets answer 410 and the CSV export follows the same rules', function () {
    [, $admin] = empresaConAdministrador();
    [, $otraEmpresa] = empresaConAdministrador();

    $respuesta = preguntarAMozi($admin, '¿Qué productos se van a quedar sin stock esta semana?');
    $dataset = AgenteDataset::query()->where('mensaje_id', $respuesta->id)->whereJsonLength('filas', 8)->firstOrFail();

    $csv = $this->actingAs($admin)->get(route('agente-mozi.dataset.csv', $dataset->ref));
    $csv->assertOk()->assertDownload('agente-mozi-'.$dataset->id.'.csv');
    expect($csv->streamedContent())->toContain('Producto', 'Aceite 900 ml');

    $this->actingAs($otraEmpresa)->get(route('agente-mozi.dataset.csv', $dataset->ref))->assertNotFound();

    $dataset->forceFill(['expira_en' => now()->subMinute()])->save();
    $this->actingAs($admin)->getJson(route('agente-mozi.dataset', $dataset->ref))->assertStatus(410);
    $this->actingAs($admin)->get(route('agente-mozi.dataset.csv', $dataset->ref))->assertStatus(410);
});

test('the isolated view is served with a sandbox CSP and never to other users or companies', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $vendedor = User::factory()->vendedor($sucursal)->create();
    [, $otraEmpresa] = empresaConAdministrador();

    $respuesta = preguntarAMozi($admin, PREGUNTA_VISTA);
    $vista = $respuesta->artefactos->firstOrFail(fn (AgenteArtefacto $a) => $a->tipo === TipoArtefacto::SandboxWeb);

    expect($vista->meta['origin'])->toBe('demo');

    $this->actingAs($admin)->get(route('agente-mozi.sandbox', $vista))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', DocumentoSandbox::CSP)
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertSee('window.mozi = Object.freeze', false)
        ->assertSee('http-equiv="Content-Security-Policy"', false);

    expect(DocumentoSandbox::CSP)->toStartWith('sandbox allow-scripts;')
        ->not->toContain('allow-same-origin')
        ->toContain("default-src 'none'", "connect-src 'none'", "form-action 'none'", "frame-ancestors 'self'");

    $this->actingAs($vendedor)->get(route('agente-mozi.sandbox', $vista))->assertNotFound();
    $this->actingAs($otraEmpresa)->get(route('agente-mozi.sandbox', $vista))->assertNotFound();

    // Solo los artefactos sandbox_web tienen vista; y el interruptor la apaga por completo.
    $tabla = AgenteArtefacto::query()->where('tipo', TipoArtefacto::Tabla)->first()
        ?? preguntarAMozi($admin, '¿Qué productos se van a quedar sin stock esta semana?')->artefactos->firstOrFail(fn (AgenteArtefacto $a) => $a->tipo === TipoArtefacto::Tabla);
    $this->actingAs($admin)->get(route('agente-mozi.sandbox', $tabla))->assertNotFound();

    config(['agente_mozi.sandbox_web' => false]);
    $this->actingAs($admin)->get(route('agente-mozi.sandbox', $vista))->assertNotFound();
});

test('the canvas protocol never exposes sandbox code to the main page', function () {
    [, $admin] = empresaConAdministrador();

    $respuesta = preguntarAMozi($admin, PREGUNTA_VISTA);
    $analisis = ProtocoloLienzo::analisis([$respuesta->load('artefactos.dataset')]);
    $vista = collect($analisis[0]['artifacts'])->firstWhere('type', 'sandbox_web');

    expect($vista['spec'])->toBe([])
        ->and($vista['view_url'])->toBe(route('agente-mozi.sandbox', $vista['id']))
        ->and($vista['dataset_url'])->toStartWith(route('agente-mozi'))
        ->and(json_encode($analisis))->not->toContain('mozi.onData');
});

test('content can never close the tags that contain it in the isolated document', function () {
    $artefacto = new AgenteArtefacto;
    $artefacto->tipo = TipoArtefacto::SandboxWeb;
    $artefacto->titulo = '<script>alert(1)</script>';
    $artefacto->spec = [
        'html' => '<div>Hola</div>',
        'css' => 'body{color:red}</style><script>alert(2)</script>',
        'js' => 'var a = "</script><script>alert(3)</script>"; <!-- x',
    ];

    $documento = app(DocumentoSandbox::class)->construir($artefacto);

    expect($documento)->not->toContain('<script>alert(1)</script>')
        ->not->toContain('</style><script>alert(2)')
        ->not->toContain('</script><script>alert(3)')
        ->not->toContain('<!-- x')
        ->toContain('<\/script><script>alert(3)<\/script>')
        // Solo cierran la hoja base y la del artefacto; dentro de <style> un «</script>» es texto inerte.
        ->and(substr_count($documento, '</style>'))->toBe(2);
});

test('superadmins cannot read datasets or isolated views', function () {
    [, $admin] = empresaConAdministrador();
    $superadmin = User::factory()->superadmin()->create();

    $respuesta = preguntarAMozi($admin, PREGUNTA_VISTA);
    $dataset = AgenteDataset::query()->where('mensaje_id', $respuesta->id)->firstOrFail();
    $vista = $respuesta->artefactos->firstOrFail(fn (AgenteArtefacto $a) => $a->tipo === TipoArtefacto::SandboxWeb);

    $this->actingAs($superadmin)->getJson(route('agente-mozi.dataset', $dataset->ref))->assertForbidden();
    // El enlace de modelo corre antes del middleware de empresa: el ámbito por empresa (que falla cerrado) ya responde 404.
    $this->actingAs($superadmin)->get(route('agente-mozi.sandbox', $vista->id))->assertNotFound();
});
