<?php

use App\Domain\Facturacion\Actions\GuardarDatosFiscales;
use App\Domain\Facturacion\Actions\GuardarTokenDelegado;
use App\Domain\Facturacion\ContribuyenteEmpresa;
use App\Domain\Facturacion\ErrorFacturacion;
use App\Http\Controllers\ImpersonacionController;
use App\Livewire\Facturacion\Asistente;
use App\Livewire\Facturacion\Panel;
use App\Models\Auditoria;
use App\Models\Scopes\EmpresaScope;
use App\Models\SiatConfiguracion;
use App\Models\SiatCuis;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Facturacion\SiatPruebas;

/*
 * Fase 2 SIAT: datos fiscales y token delegado por empresa. El token se guarda cifrado, solo se
 * muestra su sufijo y nunca llega al HTML, a la auditoría ni a los logs.
 */

const NIT_FISCAL = 1023456029;

beforeEach(function () {
    $this->transporte = SiatPruebas::configurar();
});

function configuracionDe(int $empresaId): ?SiatConfiguracion
{
    return SiatConfiguracion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresaId)->first();
}

test('el administrador guarda los datos fiscales y quedan auditados', function () {
    [$empresa, $admin] = empresaConAdministrador(['siat_habilitado' => true]);
    $this->transporte->responder(SiatPruebas::cuis());
    SiatPruebas::responderSincronizacion($this->transporte);

    Livewire::actingAs($admin)->test(Asistente::class, ['empresaId' => $empresa->id])
        ->set('nit', (string) NIT_FISCAL)
        ->set('razon_social', 'COMERCIAL MOZI SRL')
        ->set('token', SiatPruebas::token(NIT_FISCAL))
        ->call('guardarConexion')
        ->assertHasNoErrors();

    $configuracion = configuracionDe($empresa->id);
    expect($configuracion->nit)->toBe(NIT_FISCAL)
        ->and($configuracion->razon_social)->toBe('COMERCIAL MOZI SRL')
        ->and($configuracion->modo->value)->toBe('manual')
        ->and($configuracion->tieneToken())->toBeTrue()
        ->and(Auditoria::query()->where('accion', 'siat.datos_fiscales')->count())->toBe(1);
});

test('el modo por defecto es manual y el NIT solo admite dígitos', function () {
    [$empresa, $admin] = empresaConAdministrador(['siat_habilitado' => true]);

    Livewire::actingAs($admin)->test(Asistente::class, ['empresaId' => $empresa->id])
        ->assertSet('modo', 'manual')
        ->set('nit', '1023456-1A')
        ->set('razon_social', 'X')
        ->set('token', SiatPruebas::token(NIT_FISCAL))
        ->call('guardarConexion')
        ->assertHasErrors(['nit']);

    expect(configuracionDe($empresa->id))->toBeNull();
});

test('el token se guarda cifrado y solo se muestra su sufijo', function () {
    [$empresa, $admin] = empresaConAdministrador(['siat_habilitado' => true]);
    $token = SiatPruebas::token(NIT_FISCAL, firma: 'firmaSECRETA_q7Zx');

    $this->transporte->responder(SiatPruebas::cuis());
    SiatPruebas::responderSincronizacion($this->transporte);

    Livewire::actingAs($admin)->test(Asistente::class, ['empresaId' => $empresa->id])
        ->set('nit', (string) NIT_FISCAL)->set('razon_social', 'COMERCIAL MOZI SRL')
        ->set('token', "  {$token}\n")
        ->call('guardarConexion')
        ->assertHasNoErrors()
        ->assertSet('token', '')
        ->assertSee('…q7Zx')
        ->assertDontSee('firmaSECRETA');

    $guardado = DB::table('siat_configuraciones')->where('empresa_id', $empresa->id)->value('token');
    $configuracion = configuracionDe($empresa->id);
    $auditoria = Auditoria::query()->where('accion', 'siat.token_actualizado')->sole();

    expect($guardado)->not->toContain('firmaSECRETA')->not->toContain('codigoSistema')
        ->and($configuracion->tokenDescifrado())->toBe($token)
        ->and($configuracion->token_sufijo)->toBe('q7Zx')
        ->and($configuracion->token_vence_en?->isFuture())->toBeTrue()
        ->and($configuracion->token_actualizado_por)->toBe($admin->id)
        ->and($configuracion->toArray())->not->toHaveKey('token')
        ->and(json_encode([$auditoria->antes, $auditoria->despues]))->not->toContain('firmaSECRETA')->toContain('q7Zx');

    $this->actingAs($admin)->get(route('facturacion'))
        ->assertOk()->assertSee('…q7Zx')->assertDontSee('firmaSECRETA')->assertDontSee($token);
});

test('rechaza tokens que no corresponden a la empresa o al sistema', function (Closure $token, string $mensaje) {
    [$empresa, $admin] = empresaConAdministrador(['siat_habilitado' => true]);

    Livewire::actingAs($admin)->test(Asistente::class, ['empresaId' => $empresa->id])
        ->call('ir', 'token')
        ->set('nit', (string) NIT_FISCAL)->set('razon_social', 'COMERCIAL MOZI SRL')
        ->set('token', $token())
        ->call('guardarConexion')
        ->assertHasErrors(['token'])
        ->assertSee($mensaje)
        ->assertSet('token', '');

    // Ningún token inválido llega al SIN.
    expect(configuracionDe($empresa->id)?->tieneToken() ?? false)->toBeFalse()
        ->and($this->transporte->llamadas)->toBe([])
        ->and(Auditoria::query()->where('accion', 'siat.token_actualizado')->exists())->toBeFalse();
})->with([
    'de otro NIT' => [fn () => SiatPruebas::token(7777777011), 'no corresponde al NIT'],
    'de otro sistema' => [fn () => SiatPruebas::token(NIT_FISCAL, 'OTROSISTEMA99'), 'es de otro sistema'],
    'vencido' => [fn () => SiatPruebas::token(NIT_FISCAL, vence: now()->subDay()->getTimestamp()), 'ya venció'],
    'que no es un JWT' => [fn () => 'aaaa.bbbb.cccc', 'No parece un token delegado'],
    'vacío' => [fn () => '   ', 'Pega el token delegado'],
]);

test('sin NIT registrado no se acepta el token', function () {
    [$empresa, $admin] = empresaConAdministrador(['siat_habilitado' => true]);

    Livewire::actingAs($admin)->test(Asistente::class, ['empresaId' => $empresa->id])
        ->set('nit', '')
        ->set('token', SiatPruebas::token(NIT_FISCAL))
        ->call('guardarConexion')
        ->assertHasErrors(['nit']);

    // La acción también lo exige, aunque se la llame sin el asistente.
    expect(fn () => app(GuardarTokenDelegado::class)->ejecutar($admin, $empresa, SiatPruebas::token(NIT_FISCAL)))
        ->toThrow(ValidationException::class, 'Primero registra el NIT')
        ->and(configuracionDe($empresa->id))->toBeNull();
});

test('el NIT queda fijo después de obtener códigos del SIN o si el token es de otro NIT', function () {
    $datos = SiatPruebas::empresaFiscal(NIT_FISCAL);
    SiatPruebas::catalogosSincronizados($datos['empresa']->id);
    $componente = Livewire::actingAs($datos['admin'])->test(Asistente::class, ['empresaId' => $datos['empresa']->id]);

    // El token cargado es del NIT actual: cambiar a otro NIT lo dejaría inválido.
    $componente->set('nit', '7777777011')->call('guardarConexion')->assertHasErrors(['nit']);

    $cuis = new SiatCuis;
    $cuis->empresa_id = $datos['empresa']->id;
    $cuis->siat_punto_venta_id = $datos['punto']->id;
    $cuis->ambiente = 2;
    $cuis->codigo_sistema = SiatPruebas::SISTEMA;
    $cuis->codigo = 'C2FD8E5A';
    $cuis->vence_en = Carbon::now()->addYear();
    $cuis->save();

    $componente->set('nit', '7777777011')->call('guardarConexion')->assertHasErrors(['nit']);
    $this->transporte->responder(SiatPruebas::cuis());
    $componente->set('nit', (string) NIT_FISCAL)->set('razon_social', 'NUEVA RAZON')->call('guardarConexion')->assertHasNoErrors();

    expect(configuracionDe($datos['empresa']->id)->nit)->toBe(NIT_FISCAL)
        ->and(configuracionDe($datos['empresa']->id)->razon_social)->toBe('NUEVA RAZON');
});

test('el formulario de datos fiscales ya no incluye metodo de pago electronico ni unidad de medida', function () {
    $datos = SiatPruebas::empresaFiscal();

    $componente = Livewire::actingAs($datos['admin'])->test(Asistente::class, ['empresaId' => $datos['empresa']->id]);
    $componente->assertDontSeeHtml('id="metodo_pago_electronico"')
        ->assertDontSeeHtml('id="unidad_medida"');
});

test('cada empresa tiene su propio token y nadie ve los datos fiscales de otra', function () {
    $a = SiatPruebas::empresaFiscal(NIT_FISCAL, ['nombre' => 'Empresa A'], 'firmaA_aaaa');
    $b = SiatPruebas::empresaFiscal(1029384756, ['nombre' => 'Empresa B'], 'firmaB_bbbb');

    $this->actingAs($a['admin'])->get(route('facturacion'))->assertOk()->assertSee('…aaaa')->assertDontSee('…bbbb')->assertDontSee('1029384756');

    // El ID de otra empresa no concede acceso, ni montando el componente ni en la ruta del panel global.
    Livewire::actingAs($a['admin'])->test(Asistente::class, ['empresaId' => $b['empresa']->id])->assertForbidden();
    $this->actingAs($a['admin'])->get(route('admin.empresas.facturacion', $b['empresa']))->assertForbidden();

    $contribuyentes = app(ContribuyenteEmpresa::class);
    expect($contribuyentes->para($a['empresa'])->token->sufijo())->toBe('…aaaa')
        ->and($contribuyentes->para($b['empresa'])->nit)->toBe(1029384756);
});

test('solo el administrador de una empresa habilitada o el superadmin entran a facturación', function () {
    [$deshabilitada, $adminSinSiat] = empresaConAdministrador();
    $datos = SiatPruebas::empresaFiscal();
    $vendedor = User::factory()->vendedor($datos['sucursal'])->create();
    $superadmin = User::factory()->superadmin()->create();

    $this->actingAs($adminSinSiat)->get(route('facturacion'))->assertForbidden();
    $this->actingAs($adminSinSiat)->get(route('configuracion'))->assertOk()->assertSee('No habilitada')->assertDontSee(route('facturacion'));
    $this->actingAs($vendedor)->get(route('facturacion'))->assertForbidden();
    $this->actingAs($datos['admin'])->get(route('facturacion'))->assertOk()->assertSee('Datos fiscales')->assertSee(route('facturacion'));
    $this->actingAs($superadmin)->get(route('admin.empresas.facturacion', $datos['empresa']))->assertOk()->assertSee($datos['empresa']->nombre);
    $this->actingAs($superadmin)->get(route('admin.empresas.facturacion', $deshabilitada))->assertOk()->assertSee('Facturación no habilitada');
});

test('el superadmin también carga los datos fiscales de una empresa', function () {
    [$empresa] = empresaConAdministrador(['siat_habilitado' => true]);
    $superadmin = User::factory()->superadmin()->create();

    $this->transporte->responder(SiatPruebas::cuis());
    SiatPruebas::responderSincronizacion($this->transporte);

    Livewire::actingAs($superadmin)->test(Asistente::class, ['empresaId' => $empresa->id])
        ->set('nit', (string) NIT_FISCAL)->set('razon_social', 'COMERCIAL MOZI SRL')
        ->set('token', SiatPruebas::token(NIT_FISCAL))->call('guardarConexion')
        ->assertHasNoErrors();

    expect(configuracionDe($empresa->id)->token_actualizado_por)->toBe($superadmin->id)
        ->and(Auditoria::query()->where('accion', 'siat.token_actualizado')->value('empresa_id'))->toBe($empresa->id);
});

test('durante «Entrar como» se ven los datos pero no se cambian', function () {
    $datos = SiatPruebas::empresaFiscal();
    $superadmin = User::factory()->superadmin()->create();

    $this->actingAs($superadmin)->post(route('impersonar', $datos['admin']))->assertRedirect(route('dashboard'));
    $this->get(route('facturacion'))->assertOk()->assertSee('Modo «Entrar como»')->assertDontSee('Guardar token')->assertDontSee('Guardar datos fiscales');

    // Las acciones vuelven a comprobarlo en el servidor, aunque se llamen sin la pantalla.
    request()->setLaravelSession(app('session.store'));
    session()->put(ImpersonacionController::SESION, $superadmin->id);

    expect(fn () => app(GuardarTokenDelegado::class)->ejecutar($datos['admin'], $datos['empresa'], SiatPruebas::token(NIT_FISCAL)))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(GuardarDatosFiscales::class)->ejecutar($datos['admin'], $datos['empresa'], ['nit' => NIT_FISCAL, 'razon_social' => 'X', 'modo' => 'manual']))
        ->toThrow(AuthorizationException::class);
});

test('sin habilitación, NIT o token vigente no se consulta al SIN', function () {
    $datos = SiatPruebas::empresaFiscal();
    $contribuyentes = app(ContribuyenteEmpresa::class);
    $configuracion = $datos['configuracion'];

    $configuracion->token_vence_en = Carbon::now()->subMinute();
    $configuracion->save();
    expect(fn () => $contribuyentes->para($datos['empresa']))->toThrow(ErrorFacturacion::class, 'venció');

    $configuracion->token_sufijo = null;
    $configuracion->save();
    expect(fn () => $contribuyentes->para($datos['empresa']))->toThrow(ErrorFacturacion::class, 'token');

    $datos['empresa']->forceFill(['siat_habilitado' => false])->save();
    expect(fn () => $contribuyentes->para($datos['empresa']->refresh()))->toThrow(ErrorFacturacion::class)
        ->and($this->transporte->llamadas)->toBe([]);
});

test('el panel conserva la sección pedida y la limita a las conocidas', function () {
    $datos = SiatPruebas::empresaFiscal();

    Livewire::actingAs($datos['admin'])->test(Panel::class)
        ->assertSet('empresaId', $datos['empresa']->id)
        ->set('seccion', 'catalogos')->assertSee('Catálogos del SIN')
        ->set('seccion', 'otra')->assertSet('seccion', 'datos');
});
