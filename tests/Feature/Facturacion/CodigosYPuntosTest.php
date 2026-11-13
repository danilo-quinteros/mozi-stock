<?php

use App\Domain\Facturacion\CodigosSiat;
use App\Domain\Facturacion\ErrorFacturacion;
use App\Domain\Facturacion\SincronizarCatalogos;
use App\Jobs\Siat\RenovarCufdEmpresa;
use App\Jobs\Siat\SincronizarCatalogosEmpresa;
use App\Livewire\Admin\CatalogosSiat;
use App\Livewire\Admin\Empresas;
use App\Livewire\Facturacion\Asistente;
use App\Livewire\Facturacion\Catalogos;
use App\Livewire\Facturacion\Puntos;
use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Scopes\EmpresaScope;
use App\Models\SiatCatalogo;
use App\Models\SiatCufd;
use App\Models\SiatCuis;
use App\Models\SiatPuntoVenta;
use App\Models\SiatSincronizacion;
use App\Models\SiatSucursal;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use MoziStudios\Siat\ClienteSiat;
use MoziStudios\Siat\Configuracion\ConfiguracionSistema;
use MoziStudios\Siat\Excepciones\ErrorComunicacion;
use MoziStudios\Siat\Sincronizacion\Catalogo;
use Tests\Feature\Facturacion\SiatPruebas;

/*
 * Fase 2 SIAT: sucursales y puntos de venta del SIN, CUIS/CUFD guardados con su historial,
 * catálogos por empresa y los trabajos programados. Sin red (transporte falso).
 */

beforeEach(function () {
    $this->transporte = SiatPruebas::configurar();
});

/**
 * @template T of Model
 *
 * @param  class-string<T>  $modelo
 * @return Builder<T>
 */
function sinScope(string $modelo): Builder
{
    return $modelo::withoutGlobalScope(EmpresaScope::class);
}

function operaciones(array $llamadas): array
{
    return array_column($llamadas, 'operacion');
}

test('registrar una sucursal en el SIN crea su punto de venta 0 y queda auditado', function () {
    [$empresa, $admin] = empresaConAdministrador(['siat_habilitado' => true]);
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id, 'nombre' => 'Casa Matriz']);

    Livewire::actingAs($admin)->test(Puntos::class, ['empresaId' => $empresa->id])
        ->call('editarSucursal', $sucursal->id)
        ->assertSet('codigo', '0')
        ->set('municipio', 'Santa Cruz')
        ->call('guardarSucursal')
        ->assertHasNoErrors()
        ->assertSee('Sucursal 0 · Casa Matriz');

    $registro = sinScope(SiatSucursal::class)->sole();
    expect($registro->codigo)->toBe(0)
        ->and(sinScope(SiatPuntoVenta::class)->where('siat_sucursal_id', $registro->id)->pluck('codigo')->all())->toBe([0])
        ->and(Auditoria::query()->where('accion', 'siat.sucursal_registrada')->count())->toBe(1)
        ->and($this->transporte->llamadas)->toBe([]);
});

test('el código SIN no se repite en la empresa, no cambia con CUIS y no se registra una sucursal inactiva', function () {
    $datos = SiatPruebas::empresaFiscal();
    $otra = Sucursal::factory()->create(['empresa_id' => $datos['empresa']->id]);
    $inactiva = Sucursal::factory()->inactiva()->create(['empresa_id' => $datos['empresa']->id]);
    $componente = Livewire::actingAs($datos['admin'])->test(Puntos::class, ['empresaId' => $datos['empresa']->id]);

    $componente->call('editarSucursal', $otra->id)->set('codigo', '0')->set('municipio', 'Cotoca')->call('guardarSucursal')->assertHasErrors(['codigo']);
    $componente->call('editarSucursal', $inactiva->id)->set('codigo', '5')->set('municipio', 'Cotoca')->call('guardarSucursal')->assertHasErrors(['codigo']);

    $this->transporte->responder(SiatPruebas::cuis());
    $componente->call('obtenerCuis', $datos['punto']->id);
    $componente->call('editarSucursal', $datos['sucursal']->id)->set('codigo', '3')->call('guardarSucursal')->assertHasErrors(['codigo']);
    $componente->call('editarSucursal', $datos['sucursal']->id)->set('municipio', 'La Guardia')->call('guardarSucursal')->assertHasNoErrors();

    expect(sinScope(SiatSucursal::class)->count())->toBe(1)
        ->and($datos['siatSucursal']->refresh()->codigo)->toBe(0)
        ->and($datos['siatSucursal']->municipio)->toBe('La Guardia');
});

test('el CUIS se pide una vez, se guarda y se reutiliza mientras esté vigente', function () {
    $datos = SiatPruebas::empresaFiscal();
    $codigos = app(CodigosSiat::class);
    $this->transporte->responder(SiatPruebas::cuis('C2FD8E5A'));

    $primero = $codigos->cuis($datos['punto']);
    $segundo = $codigos->cuis($datos['punto']);

    expect($segundo->id)->toBe($primero->id)
        ->and($primero->codigo)->toBe('C2FD8E5A')
        ->and($primero->ambiente)->toBe(2)
        ->and($primero->codigo_sistema)->toBe(SiatPruebas::SISTEMA)
        ->and($primero->vence_en->utc()->format('Y-m-d H:i'))->toBe('2027-10-24 14:00')
        ->and(operaciones($this->transporte->llamadas))->toBe(['cuis'])
        ->and($this->transporte->llamadas[0]['parametros']['SolicitudCuis']['nit'])->toBe(1023456029)
        ->and($this->transporte->llamadas[0]['token'])->toBe('…q7Zx');
});

test('con un CUIS ya vigente en el SIN (mensaje 980) el código devuelto se guarda igual', function () {
    $datos = SiatPruebas::empresaFiscal();
    $this->transporte->responder(['RespuestaCuis' => [
        'codigo' => '5027661C', 'fechaVigencia' => '2027-10-24T15:55:05.000-04:00', 'transaccion' => false,
        'mensajesList' => [['codigo' => 980, 'descripcion' => 'EXISTE UN CUIS VIGENTE PARA LA SUCURSAL O PUNTO DE VENTA']],
    ]]);

    expect(app(CodigosSiat::class)->cuis($datos['punto'])->codigo)->toBe('5027661C')
        ->and(sinScope(SiatCuis::class)->count())->toBe(1);
});

test('un CUIS de otro ambiente o de otro código de sistema no se usa', function () {
    $datos = SiatPruebas::empresaFiscal();
    $this->transporte->responder(SiatPruebas::cuis('AAAA1111'));
    app(CodigosSiat::class)->cuis($datos['punto']);

    config(['siat.codigo_sistema' => 'OTRO7SISTEMA']);
    app()->forgetInstance(ConfiguracionSistema::class);
    app()->forgetInstance(ClienteSiat::class);
    app()->forgetInstance(CodigosSiat::class);

    $datos['configuracion']->token = SiatPruebas::token(1023456029, 'OTRO7SISTEMA');
    $datos['configuracion']->save();
    $this->transporte->responder(SiatPruebas::cuis('BBBB2222'));

    expect(app(CodigosSiat::class)->cuis($datos['punto'])->codigo)->toBe('BBBB2222')
        ->and(sinScope(SiatCuis::class)->orderBy('id')->pluck('codigo_sistema', 'codigo')->all())->toBe(['AAAA1111' => SiatPruebas::SISTEMA, 'BBBB2222' => 'OTRO7SISTEMA']);
});

test('el CUFD se guarda con historial y un doble pedido recibe el recién obtenido', function () {
    $this->freezeTime();
    $datos = SiatPruebas::empresaFiscal();
    $codigos = app(CodigosSiat::class);
    $this->transporte->responder(SiatPruebas::cuis())->responder(SiatPruebas::cufd('CONTROL1'));

    $primero = $codigos->renovarCufd($datos['punto']);
    $repetido = $codigos->renovarCufd($datos['punto']);

    expect($repetido->id)->toBe($primero->id)
        ->and(operaciones($this->transporte->llamadas))->toBe(['cuis', 'cufd']);

    $this->travel(5)->minutes();
    $this->transporte->responder(SiatPruebas::cufd('CONTROL2'));
    $nuevo = $codigos->renovarCufd($datos['punto']);

    expect($nuevo->codigo_control)->toBe('CONTROL2')
        ->and(sinScope(SiatCufd::class)->orderBy('id')->pluck('codigo_control')->all())->toBe(['CONTROL1', 'CONTROL2'])
        ->and($codigos->cufdVigente($datos['punto'])->id)->toBe($nuevo->id)
        ->and($nuevo->paraFactura()->codigoInterno)->toBe('C')
        ->and($nuevo->paraFactura()->direccion)->toBe('AV. BANZER 123');
});

test('la renovación programada solo pide CUFD cuando faltan menos de 2 horas', function () {
    $this->freezeTime();
    $datos = SiatPruebas::empresaFiscal();
    $codigos = app(CodigosSiat::class);
    $this->transporte->responder(SiatPruebas::cuis())->responder(SiatPruebas::cufd('CONTROL1'));
    $codigos->renovarCufd($datos['punto']);

    $this->travel(21)->hours();
    $codigos->renovarCufd($datos['punto'], soloSiHaceFalta: true);
    expect(sinScope(SiatCufd::class)->count())->toBe(1);

    $this->travel(2)->hours();
    $this->transporte->responder(SiatPruebas::cufd('CONTROL2'));
    $codigos->renovarCufd($datos['punto'], soloSiHaceFalta: true);
    expect(sinScope(SiatCufd::class)->count())->toBe(2);
});

test('un pedido simultáneo de CUFD no consulta dos veces al SIN', function () {
    $datos = SiatPruebas::empresaFiscal();
    // Otro proceso tiene el bloqueo del punto y no lo suelta en el tiempo de espera.
    Cache::shouldReceive('lock')->with("siat:cufd:{$datos['punto']->id}", 60)->andReturn(new class
    {
        public function block(int $segundos, callable $proceso): never
        {
            throw new LockTimeoutException;
        }
    });

    expect(fn () => app(CodigosSiat::class)->renovarCufd($datos['punto']))->toThrow(ErrorFacturacion::class, 'solicitud en curso')
        ->and($this->transporte->llamadas)->toBe([]);
});

test('desde la pantalla se obtienen CUIS y CUFD y los errores del SIN se muestran', function () {
    $datos = SiatPruebas::empresaFiscal();
    $this->transporte
        ->responder(SiatPruebas::cuis('C2FD8E5A'))
        ->responder(SiatPruebas::cufd('CONTROL1'))
        ->responder(new ErrorComunicacion('El SIN no respondió a tiempo.'));

    Livewire::actingAs($datos['admin'])->test(Puntos::class, ['empresaId' => $datos['empresa']->id])
        ->call('obtenerCuis', $datos['punto']->id)
        ->assertSee('C2FD8E5A')->assertSee('Sin CUFD')
        ->call('renovarCufd', $datos['punto']->id)
        ->assertSee('Renovar CUFD')->assertDontSee('Sin CUFD')
        ->call('renovarCufd', $datos['punto']->id)
        ->assertSet('errorSin', null);

    // El CUFD recién obtenido se reutiliza: el error preparado nunca se consumió.
    $this->travel(2)->minutes();
    Livewire::actingAs($datos['admin'])->test(Puntos::class, ['empresaId' => $datos['empresa']->id])
        ->call('renovarCufd', $datos['punto']->id)
        ->assertSet('errorSin', 'El SIN no respondió a tiempo.')
        ->assertSee('El SIN no respondió a tiempo.');
});

test('registrar un punto de venta usa el CUIS del punto 0 y el tipo del catálogo', function () {
    $datos = SiatPruebas::empresaFiscal();
    DB::table('siat_catalogos')->insert(['empresa_id' => $datos['empresa']->id, 'catalogo' => Catalogo::TiposPuntoVenta->name, 'codigo' => '5', 'descripcion' => 'PUNTO VENTA CAJEROS']);
    $this->transporte->responder(SiatPruebas::cuis('C2FD8E5A'))->responder(['RespuestaRegistroPuntoVenta' => ['codigoPuntoVenta' => 3, 'transaccion' => true]]);
    $componente = Livewire::actingAs($datos['admin'])->test(Puntos::class, ['empresaId' => $datos['empresa']->id]);

    $componente->call('nuevoPunto', $datos['siatSucursal']->id)
        ->set('tipo', '9')->set('nombre', 'Caja 2')->set('descripcion', 'Caja de la entrada')
        ->call('registrarPunto')->assertHasErrors(['tipo']);
    $componente->set('tipo', '5')->call('registrarPunto')->assertHasNoErrors()->assertSet('registrandoEnId', null);

    $punto = sinScope(SiatPuntoVenta::class)->where('codigo', 3)->sole();
    $solicitud = $this->transporte->llamadas[1]['parametros']['SolicitudRegistroPuntoVenta'];

    expect($punto->nombre)->toBe('Caja 2')
        ->and($punto->tipo)->toBe('PUNTO VENTA CAJEROS')
        ->and($solicitud['cuis'])->toBe('C2FD8E5A')
        ->and($solicitud['codigoTipoPuntoVenta'])->toBe(5)
        ->and(Auditoria::query()->where('accion', 'siat.punto_venta_registrado')->count())->toBe(1);
});

test('un doble envío del registro de punto de venta no registra dos puntos en el SIN', function () {
    $datos = SiatPruebas::empresaFiscal();
    DB::table('siat_catalogos')->insert(['empresa_id' => $datos['empresa']->id, 'catalogo' => Catalogo::TiposPuntoVenta->name, 'codigo' => '5', 'descripcion' => 'PUNTO VENTA CAJEROS']);
    $this->transporte->responder(SiatPruebas::cuis());
    $enCurso = Cache::lock("siat:registro-pv:{$datos['siatSucursal']->id}", 60);
    $enCurso->get();

    Livewire::actingAs($datos['admin'])->test(Puntos::class, ['empresaId' => $datos['empresa']->id])
        ->call('nuevoPunto', $datos['siatSucursal']->id)
        ->set('tipo', '5')->set('nombre', 'Caja 2')->set('descripcion', 'Caja')
        ->call('registrarPunto')
        ->assertSee('Ya hay un registro en curso');

    expect(operaciones($this->transporte->llamadas))->toBe(['cuis'])
        ->and(sinScope(SiatPuntoVenta::class)->count())->toBe(1);
});

test('traer del SIN agrega los puntos que faltan sin duplicar y cerrar los desactiva', function () {
    $datos = SiatPruebas::empresaFiscal();
    $lista = ['RespuestaConsultaPuntoVenta' => ['transaccion' => true, 'listaPuntosVentas' => [
        ['codigoPuntoVenta' => 0, 'nombrePuntoVenta' => 'MATRIZ', 'tipoPuntoVenta' => 'PUNTO VENTA FIJO'],
        ['codigoPuntoVenta' => 1, 'nombrePuntoVenta' => 'CAJA 1', 'tipoPuntoVenta' => 'PUNTO VENTA CAJEROS'],
    ]]];
    $this->transporte->responder(SiatPruebas::cuis())->responder($lista)->responder($lista)
        ->responder(['RespuestaCierrePuntoVenta' => ['codigoPuntoVenta' => 1, 'transaccion' => true]]);
    $componente = Livewire::actingAs($datos['admin'])->test(Puntos::class, ['empresaId' => $datos['empresa']->id]);

    $componente->call('importar', $datos['siatSucursal']->id)->assertSee('CAJA 1');
    $componente->call('importar', $datos['siatSucursal']->id)->assertSee('No hay puntos de venta nuevos');
    $caja = sinScope(SiatPuntoVenta::class)->where('codigo', 1)->sole();

    $componente->call('confirmarCierre', $datos['punto']->id)->call('cerrarEnSin')->assertSet('errorSin', 'El punto de venta 0 representa a la sucursal y no se cierra.');
    $componente->call('confirmarCierre', $caja->id)->call('cerrarEnSin')->assertSet('cerrandoId', null)->assertSee('Cerrado');

    expect(sinScope(SiatPuntoVenta::class)->orderBy('codigo')->pluck('activo', 'codigo')->all())->toBe([0 => true, 1 => false])
        ->and(operaciones($this->transporte->llamadas))->toBe(['cuis', 'consultaPuntoVenta', 'consultaPuntoVenta', 'cierrePuntoVenta'])
        ->and(Auditoria::query()->whereIn('accion', ['siat.puntos_venta_importados', 'siat.punto_venta_cerrado'])->count())->toBe(2);
});

test('los puntos, sucursales y códigos de otra empresa responden 404', function () {
    $a = SiatPruebas::empresaFiscal();
    $b = SiatPruebas::empresaFiscal(1029384756);
    $llamadas = [
        ['obtenerCuis', $b['punto']->id], ['renovarCufd', $b['punto']->id], ['confirmarCierre', $b['punto']->id],
        ['importar', $b['siatSucursal']->id], ['nuevoPunto', $b['siatSucursal']->id], ['editarSucursal', $b['sucursal']->id],
    ];

    foreach ($llamadas as [$metodo, $id]) {
        Livewire::actingAs($a['admin'])->test(Puntos::class, ['empresaId' => $a['empresa']->id])->call($metodo, $id)->assertNotFound();
    }

    expect($this->transporte->llamadas)->toBe([]);
});

test('el vendedor no ve ni opera la facturación', function () {
    $datos = SiatPruebas::empresaFiscal();
    $vendedor = User::factory()->vendedor($datos['sucursal'])->create();

    Livewire::actingAs($vendedor)->test(Puntos::class, ['empresaId' => $datos['empresa']->id])->assertForbidden();
    Livewire::actingAs($vendedor)->test(Catalogos::class, ['empresaId' => $datos['empresa']->id])->assertForbidden();
});

test('sincronizar reemplaza cada catálogo de la empresa y uno con error conserva su versión anterior', function () {
    $datos = SiatPruebas::empresaFiscal();
    $otra = SiatPruebas::empresaFiscal(1029384756);
    DB::table('siat_catalogos')->insert([
        ['empresa_id' => $datos['empresa']->id, 'catalogo' => Catalogo::TiposMetodoPago->name, 'codigo' => '99', 'descripcion' => 'VIEJO'],
        ['empresa_id' => $datos['empresa']->id, 'catalogo' => Catalogo::TiposPuntoVenta->name, 'codigo' => '1', 'descripcion' => 'ANTERIOR'],
        ['empresa_id' => $otra['empresa']->id, 'catalogo' => Catalogo::TiposMetodoPago->name, 'codigo' => '99', 'descripcion' => 'DE OTRA EMPRESA'],
    ]);
    $this->transporte->responder(SiatPruebas::cuis())
        ->responder(SiatPruebas::catalogo(Catalogo::TiposMetodoPago, [['1', 'EFECTIVO'], ['7', 'TRANSFERENCIA BANCARIA']]))
        ->responder(new ErrorComunicacion('El SIN no respondió a tiempo.'));

    $resultado = app(SincronizarCatalogos::class)->ejecutar($datos['empresa'], [Catalogo::TiposMetodoPago, Catalogo::TiposPuntoVenta]);
    $deEmpresa = fn (int $empresaId, Catalogo $catalogo) => sinScope(SiatCatalogo::class)->where('empresa_id', $empresaId)->where('catalogo', $catalogo->name)->orderBy('codigo')->pluck('descripcion', 'codigo')->all();
    $estado = sinScope(SiatSincronizacion::class)->where('empresa_id', $datos['empresa']->id)->get()->keyBy('catalogo');

    expect($resultado)->toBe(['correctos' => 1, 'fallidos' => ['Tipos de punto de venta']])
        ->and($deEmpresa($datos['empresa']->id, Catalogo::TiposMetodoPago))->toBe([1 => 'EFECTIVO', 7 => 'TRANSFERENCIA BANCARIA'])
        ->and($deEmpresa($datos['empresa']->id, Catalogo::TiposPuntoVenta))->toBe([1 => 'ANTERIOR'])
        ->and($deEmpresa($otra['empresa']->id, Catalogo::TiposMetodoPago))->toBe([99 => 'DE OTRA EMPRESA'])
        ->and($estado[Catalogo::TiposMetodoPago->name]->cantidad)->toBe(2)
        ->and($estado[Catalogo::TiposPuntoVenta->name]->error)->toBe('El SIN no respondió a tiempo.')
        ->and($this->transporte->llamadas[1]['parametros']['SolicitudSincronizacion']['cuis'])->toBe('C2FD8E5A');
});

test('la pantalla sincroniza los 17 catálogos y muestra sus registros', function () {
    $datos = SiatPruebas::empresaFiscal();
    $this->transporte->responder(SiatPruebas::cuis());

    foreach (Catalogo::cases() as $catalogo) {
        $this->transporte->responder(SiatPruebas::catalogo($catalogo, [['1', 'PRIMERO '.$catalogo->name], ['2', 'SEGUNDO']]));
    }

    Livewire::actingAs($datos['admin'])->test(Catalogos::class, ['empresaId' => $datos['empresa']->id])
        ->call('sincronizar')
        ->assertSet('errorSin', null)
        ->assertSee('Catálogos sincronizados: 17.')
        ->call('ver', Catalogo::Actividades->name)
        ->assertSee('PRIMERO Actividades')
        ->set('buscar', 'SEGUNDO')
        ->assertDontSee('PRIMERO Actividades')
        ->call('ver', 'NoExiste')
        ->assertSet('viendo', '');

    expect(sinScope(SiatSincronizacion::class)->whereNotNull('sincronizado_en')->count())->toBe(17);
});

test('una sincronización en curso rechaza la segunda', function () {
    $datos = SiatPruebas::empresaFiscal();
    Cache::lock("siat:catalogos:{$datos['empresa']->id}", 600)->get();

    expect(fn () => app(SincronizarCatalogos::class)->ejecutar($datos['empresa']))->toThrow(ErrorFacturacion::class, 'en curso')
        ->and($this->transporte->llamadas)->toBe([]);
});

test('el comando programado encola un trabajo solo por empresa que puede facturar', function () {
    Queue::fake();
    $lista = SiatPruebas::empresaFiscal();
    $vencida = SiatPruebas::empresaFiscal(1029384756)['configuracion'];
    $vencida->token_vence_en = now()->subDay();
    $vencida->save();
    SiatPruebas::empresaFiscal(1011121314, ['siat_habilitado' => false]);
    SiatPruebas::empresaFiscal(1015161718, ['activa' => false]);

    $this->artisan('mozistock:siat cufd')->expectsOutputToContain('Empresas en cola: 1.')->assertSuccessful();
    $this->artisan('mozistock:siat catalogos')->assertSuccessful();
    $this->artisan('mozistock:siat otra')->assertFailed();

    Queue::assertPushed(RenovarCufdEmpresa::class, 1);
    Queue::assertPushed(RenovarCufdEmpresa::class, fn (RenovarCufdEmpresa $job) => $job->empresaId === $lista['empresa']->id);
    Queue::assertPushed(SincronizarCatalogosEmpresa::class, 1);
    Queue::assertPushed(SincronizarCatalogosEmpresa::class, fn (SincronizarCatalogosEmpresa $job) => $job->empresaId === $lista['empresa']->id);
});

test('el trabajo renueva el CUFD de cada punto activo y registra fallos sin el token', function () {
    $datos = SiatPruebas::empresaFiscal();
    $cerrado = new SiatPuntoVenta;
    $cerrado->empresa_id = $datos['empresa']->id;
    $cerrado->siat_sucursal_id = $datos['siatSucursal']->id;
    $cerrado->codigo = 4;
    $cerrado->nombre = 'Caja cerrada';
    $cerrado->activo = false;
    $cerrado->save();
    $this->transporte->responder(SiatPruebas::cuis())->responder(SiatPruebas::cufd('CONTROL1'));

    (new RenovarCufdEmpresa($datos['empresa']->id))->handle(app(CodigosSiat::class));

    expect(sinScope(SiatCufd::class)->pluck('siat_punto_venta_id')->all())->toBe([$datos['punto']->id]);

    Log::spy();
    $this->travel(23)->hours();
    $this->transporte->responder(new ErrorComunicacion('El SIN no respondió a tiempo.'));
    (new RenovarCufdEmpresa($datos['empresa']->id))->handle(app(CodigosSiat::class));

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $mensaje, array $contexto) => $contexto['empresa_id'] === $datos['empresa']->id
        && ! str_contains(json_encode($contexto), 'SECRETA'));
});

test('el superadmin habilita la facturación con confirmación y queda auditado', function () {
    [$empresa, $admin] = empresaConAdministrador();
    $superadmin = User::factory()->superadmin()->create();

    Livewire::actingAs($superadmin)->test(Empresas::class)
        ->call('confirmarSiat', $empresa->id)
        ->assertSee('Habilitar la facturación de')
        ->call('cambiarSiat')
        ->assertSet('confirmandoSiatId', null);

    expect($empresa->refresh()->siat_habilitado)->toBeTrue()
        ->and(Auditoria::query()->where('accion', 'empresa.siat_habilitado')->value('empresa_id'))->toBe($empresa->id);

    $this->actingAs($admin)->get(route('configuracion'))->assertSee(route('facturacion'));
    Livewire::actingAs($admin)->test(Empresas::class)->assertForbidden();
});

test('guardar token delegado ejecuta la sincronizacion de catalogos en ese mismo momento', function () {
    $datos = SiatPruebas::empresaFiscal();
    // Primero la verificación del asistente (CUIS de casa matriz) y después el CUIS de la sincronización.
    $this->transporte->responder(SiatPruebas::cuis())->responder(SiatPruebas::cuis());

    foreach (Catalogo::cases() as $catalogo) {
        $this->transporte->responder(SiatPruebas::catalogo($catalogo, [['10', 'VALOR '.$catalogo->name]]));
    }

    $tokenValido = SiatPruebas::token(1023456029);

    Livewire::actingAs($datos['admin'])->test(Asistente::class, ['empresaId' => $datos['empresa']->id])
        ->set('token', $tokenValido)
        ->call('guardarConexion')
        ->assertHasNoErrors()
        ->assertSet('token', '');

    expect(sinScope(SiatSincronizacion::class)->where('empresa_id', $datos['empresa']->id)->whereNotNull('sincronizado_en')->count())
        ->toBe(17);
});

test('superadmin tiene acceso a la pantalla de catalogos globales del sin', function () {
    $superadmin = User::factory()->superadmin()->create();
    $empresa = Empresa::factory()->create();

    DB::table('siat_catalogos')->insert([
        ['empresa_id' => $empresa->id, 'catalogo' => Catalogo::TiposMetodoPago->name, 'codigo' => '1', 'descripcion' => 'EFECTIVO', 'atributos' => null],
        ['empresa_id' => $empresa->id, 'catalogo' => Catalogo::UnidadesMedida->name, 'codigo' => '58', 'descripcion' => 'UNIDAD (BIENES)', 'atributos' => null],
    ]);

    Livewire::actingAs($superadmin)->test(CatalogosSiat::class)
        ->assertSee('Catálogos paramétricos del SIN')
        ->assertSee('Métodos de pago')
        ->assertSee('Unidades de medida')
        ->call('ver', Catalogo::TiposMetodoPago->name)
        ->assertSee('EFECTIVO');

    // Un usuario no superadmin recibe 403
    [, $admin] = empresaConAdministrador();
    Livewire::actingAs($admin)->test(CatalogosSiat::class)->assertForbidden();
});
