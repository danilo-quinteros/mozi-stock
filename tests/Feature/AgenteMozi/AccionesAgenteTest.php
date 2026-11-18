<?php

use App\Domain\AgenteMozi\Acciones\CatalogoAcciones;
use App\Domain\AgenteMozi\DTOs\AccionPropuesta;
use App\Domain\Tenancy\Actions\CambiarFuncionesEmpresa;
use App\Enums\Capacidad;
use App\Enums\Rubro;
use App\Livewire\AgenteMozi\Panel;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

/*
 * Agente Mozi · acciones (IA-P3): las herramientas ofrecen enlaces de una lista cerrada, el modelo solo elige cuáles
 * por id y la pantalla arma la URL interna al mostrarlos, si el usuario todavía puede abrir esa pantalla. Gemini
 * simulado con Http::fake, nunca la API real.
 */

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
 * Respuestas simuladas de generateContent, cada una con una llamada a función.
 *
 * @param  list<array{0: string, 1: array<string, mixed>}>  $llamadas
 */
function accionesSimulaGemini(array $llamadas): void
{
    $secuencia = Http::sequence();

    foreach ($llamadas as $i => [$nombre, $argumentos]) {
        $secuencia->push([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [
                ['functionCall' => ['name' => $nombre, 'args' => $argumentos, 'id' => 'c'.$i], 'thoughtSignature' => 'firma-'.$i],
            ]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            'modelVersion' => 'gemini-3.5-flash-prueba',
        ]);
    }

    Http::fake(['gemini.test/*' => $secuencia]);
}

test('the catalog accepts only closed-list actions and builds relative links inside the site', function () {
    expect(CatalogoAcciones::desde(['tipo' => 'abrir_pedido', 'id' => 7, 'etiqueta' => "  Abrir   pedido\nPED-00007 "]))
        ->toEqual(new AccionPropuesta('abrir_pedido', 7, 'Abrir pedido PED-00007'))
        ->and(CatalogoAcciones::desde(['tipo' => 'nueva_orden', 'id' => null, 'etiqueta' => 'Nueva orden de producción']))
        ->toEqual(new AccionPropuesta('nueva_orden', null, 'Nueva orden de producción'))
        // Fuera de la lista, sin id cuando lo exige, con id cuando no lo usa, id no entero o etiqueta vacía: se descarta.
        ->and(CatalogoAcciones::desde(['tipo' => 'eliminar_empresa', 'id' => 1, 'etiqueta' => 'x']))->toBeNull()
        ->and(CatalogoAcciones::desde(['tipo' => 'abrir_pedido', 'id' => null, 'etiqueta' => 'x']))->toBeNull()
        ->and(CatalogoAcciones::desde(['tipo' => 'abrir_pedido', 'id' => '7', 'etiqueta' => 'x']))->toBeNull()
        ->and(CatalogoAcciones::desde(['tipo' => 'abrir_pedido', 'id' => -3, 'etiqueta' => 'x']))->toBeNull()
        ->and(CatalogoAcciones::desde(['tipo' => 'nueva_orden', 'id' => 5, 'etiqueta' => 'x']))->toBeNull()
        ->and(CatalogoAcciones::desde(['tipo' => 'nueva_orden', 'id' => null, 'etiqueta' => '   ']))->toBeNull()
        ->and(mb_strlen(CatalogoAcciones::crear('ver_producto', 1, str_repeat('a', 200))->etiqueta))->toBeLessThanOrEqual(83);

    $urls = [
        ['registrar_cobro', 12, '/creditos/cobrar?cliente=12'],
        ['ver_cuenta_pagar', 3, '/cuentas-por-pagar/3'],
        ['abrir_pedido', 9, '/pedidos/9'],
        ['nueva_orden', null, '/produccion/ordenes/nueva'],
        ['abrir_orden', 4, '/produccion/ordenes/4'],
        ['ingreso_materia_prima', null, '/compras/nueva?materia_prima=1'],
        ['ver_producto', 8, '/productos/8/editar'],
        ['nuevo_envasado', null, '/produccion/envasados/nuevo'],
        ['ver_lote', 5, '/inventario/lotes/5'],
    ];

    foreach ($urls as [$tipo, $id, $url]) {
        expect(CatalogoAcciones::url(new AccionPropuesta($tipo, $id, 'x')))->toBe($url);
    }
});

test('a message shows only the actions its reader can open today, at most four, and ignores tampered entries', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $vendedor = User::factory()->vendedor($sucursal)->create();
    $guardadas = [
        ['tipo' => 'registrar_cobro', 'id' => 4, 'etiqueta' => 'Registrar cobro · Ana'],
        ['tipo' => 'abrir_pedido', 'id' => 9, 'etiqueta' => 'Abrir pedido PED-00009'],
        ['tipo' => 'nueva_orden', 'id' => null, 'etiqueta' => 'Nueva orden de producción'],
        ['tipo' => 'abrir_pedido', 'url' => 'https://otro-sitio.test', 'etiqueta' => 'Enlace inyectado'],
    ];
    $vista = fn (User $usuario, array $acciones) => array_map(fn (array $a) => [$a['url'], $a['etiqueta']], CatalogoAcciones::paraVista($usuario, $acciones));

    // Sin Producción, «Nueva orden» no se muestra ni al administrador; el vendedor no ve cobros (finanzas).
    expect($vista($admin, $guardadas))->toBe([['/creditos/cobrar?cliente=4', 'Registrar cobro · Ana'], ['/pedidos/9', 'Abrir pedido PED-00009']])
        ->and($vista($vendedor, $guardadas))->toBe([['/pedidos/9', 'Abrir pedido PED-00009']])
        ->and($vista($admin, [['tipo' => 'javascript', 'id' => 1, 'etiqueta' => 'x'], 'texto suelto', ['etiqueta' => 'sin tipo']]))->toBe([])
        ->and(CatalogoAcciones::paraVista($admin, null))->toBe([]);

    $this->actingAs($admin);
    app(CambiarFuncionesEmpresa::class)->ejecutar($admin, $empresa, Rubro::General, [Capacidad::Produccion]);

    expect($vista($admin, $guardadas)[2] ?? null)->toBe(['/produccion/ordenes/nueva', 'Nueva orden de producción'])
        ->and($vista($vendedor, $guardadas))->toBe([['/pedidos/9', 'Abrir pedido PED-00009']])
        ->and(CatalogoAcciones::paraVista($admin, array_fill(0, 6, ['tipo' => 'nueva_orden', 'id' => null, 'etiqueta' => 'Nueva orden'])))->toHaveCount(4);
});

test('the model picks offered actions by id: only those are stored, it sees their labels, and the chat shows internal links', function () {
    // Otra empresa con su propio pedido pendiente: nunca se ofrece.
    [$otra, $otroAdmin] = motorConProductos();
    crearPedido($otroAdmin, Sucursal::query()->where('empresa_id', $otra->id)->firstOrFail(),
        [['producto_id' => Producto::query()->where('empresa_id', $otra->id)->value('id'), 'cantidad' => '1']]);
    auth()->logout();
    [$empresa, $admin] = motorConProductos();
    $sucursal = Sucursal::query()->where('empresa_id', $empresa->id)->firstOrFail();
    $arroz = Producto::query()->where('empresa_id', $empresa->id)->where('nombre', 'Arroz')->firstOrFail();
    $pedido = crearPedido($admin, $sucursal, [['producto_id' => $arroz->id, 'cantidad' => '2']]);

    accionesSimulaGemini([
        ['analyze_pending_orders', []],
        ['responder', ['message' => 'Tienes **1 pedido** pendiente.', 'artifacts' => [], 'actions' => ['a1', 'a1', 'a9', 'https://otro-sitio.test/x']]],
    ]);

    $respuesta = preguntarAMozi($admin, '¿Qué pedidos pendientes tengo?');
    $etiqueta = 'Abrir pedido '.$pedido->codigo().' · Consumidor final';

    expect($respuesta->acciones)->toBe([['tipo' => 'abrir_pedido', 'id' => $pedido->id, 'etiqueta' => $etiqueta]]);

    // El modelo ve el id y la etiqueta (nunca una URL) y el esquema de «responder» admite la elección.
    [$primera, $segunda] = Http::recorded()->map(fn (array $par) => $par[0])->values()->all();
    $cuerpo = fn ($peticion) => json_decode($peticion->body(), true, flags: JSON_THROW_ON_ERROR);
    $responder = collect($cuerpo($primera)['tools'][0]['functionDeclarations'])->firstWhere('name', 'responder');
    $resultados = $cuerpo($segunda)['contents'][2]['parts'][0]['functionResponse']['response']['datasets'];
    $ofrecidas = collect($resultados)->pluck('actions')->filter()->flatten(1)->all();

    expect($responder['parametersJsonSchema']['properties'])->toHaveKey('actions')
        ->and($ofrecidas)->toBe([['action' => 'a1', 'label' => $etiqueta]])
        ->and($segunda->body())->not->toContain('/pedidos/');

    Livewire::actingAs($admin)->test(Panel::class)
        ->call('abrir', $respuesta->conversacion_id)
        ->assertSeeHtml('href="/pedidos/'.$pedido->id.'"')
        ->assertSee($etiqueta)
        ->assertDontSee('otro-sitio.test');
});

test('an answer without chosen actions stores none and shows no links', function () {
    [$empresa, $admin] = motorConProductos();
    $sucursal = Sucursal::query()->where('empresa_id', $empresa->id)->firstOrFail();
    crearPedido($admin, $sucursal, [['producto_id' => Producto::query()->where('empresa_id', $empresa->id)->value('id'), 'cantidad' => '1']]);
    accionesSimulaGemini([
        ['analyze_pending_orders', []],
        ['responder', ['message' => 'Tienes un pedido pendiente.', 'artifacts' => []]],
    ]);

    $respuesta = preguntarAMozi($admin, '¿Qué pedidos pendientes tengo?');

    expect($respuesta->acciones)->toBeNull();
    Livewire::actingAs($admin)->test(Panel::class)->call('abrir', $respuesta->conversacion_id)
        ->assertDontSeeHtml('agente-acciones-msg');
});
