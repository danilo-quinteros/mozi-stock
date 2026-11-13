<?php

use App\Domain\Facturacion\PreparacionFacturacion;
use App\Domain\Facturacion\VerificacionSiat;
use App\Domain\Tenancy\GuiaConfiguracion;
use App\Enums\ModalidadFacturacion;
use App\Livewire\Facturacion\Asistente;
use App\Livewire\Facturacion\Panel;
use App\Livewire\Inicio\Guia;
use App\Models\Auditoria;
use App\Models\Scopes\EmpresaScope;
use App\Models\SiatConfiguracion;
use App\Models\SiatPuntoVenta;
use App\Models\SiatSucursal;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use MoziStudios\Siat\Excepciones\ErrorComunicacion;
use MoziStudios\Siat\Sincronizacion\Catalogo;
use Tests\Feature\Facturacion\SiatPruebas;

/*
 * Asistente de conexión con el SIN (Facturación → Datos fiscales). Lo verificable se verifica con el SIN
 * (CUIS de casa matriz); lo demás se revisa en MoziStock y se marca «correcto», nunca «verificado».
 */

const NIT_ASISTENTE = 1023456029;

beforeEach(function () {
    $this->transporte = SiatPruebas::configurar();
    Storage::fake('local');
});

function configuracionAsistente(int $empresaId): ?SiatConfiguracion
{
    return SiatConfiguracion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresaId)->first();
}

function p12Asistente(string $password, int $dias = 365): string
{
    $llave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['countryName' => 'BO', 'commonName' => 'Firma Asistente'], $llave, ['digest_alg' => 'sha256']);
    $certificado = openssl_csr_sign($csr, null, $llave, $dias, ['digest_alg' => 'sha256']);
    $p12 = '';
    openssl_pkcs12_export($certificado, $p12, $llave, $password);

    return $p12;
}

test('al guardar NIT y token el asistente lo verifica con el SIN, sincroniza los catálogos y marca el paso como verificado', function () {
    [$empresa, $admin] = empresaConAdministrador(['siat_habilitado' => true]);
    // CUIS de la verificación, CUIS de casa matriz para sincronizar (aún sin sucursales en el SIN) y los catálogos.
    $this->transporte->responder(SiatPruebas::cuis())->responder(SiatPruebas::cuis());
    foreach (Catalogo::cases() as $catalogo) {
        $this->transporte->responder(SiatPruebas::catalogo($catalogo, [['10', 'VALOR '.$catalogo->name]]));
    }

    Livewire::actingAs($admin)->test(Asistente::class, ['empresaId' => $empresa->id])
        ->assertSet('paso', 'modalidad')
        ->call('elegirModalidad', 'computarizada')
        ->call('ir', 'token')
        ->set('nit', (string) NIT_ASISTENTE)
        ->set('razon_social', 'FARMACIA PARMA SRL')
        ->set('token', SiatPruebas::token(NIT_ASISTENTE))
        ->call('guardarConexion')
        ->assertHasNoErrors()
        ->assertSet('token', '')
        ->assertSet('paso', 'revisar')
        ->assertSet('avisoTipo', 'success')
        ->assertSee('Sucursales en el SIN')
        ->assertDontSee('Habilitación de MoziStudios')
        ->assertDispatched('siat-actualizado');

    $operaciones = collect($this->transporte->llamadas)->pluck('operacion');
    expect($operaciones->take(2)->all())->toBe(['cuis', 'cuis'])
        ->and($operaciones->count())->toBe(2 + count(Catalogo::cases()))
        ->and($this->transporte->llamadas[1]['parametros']['SolicitudCuis']['codigoSucursal'])->toBe(0);

    $primera = $this->transporte->llamadas[0];
    expect($primera['operacion'])->toBe('cuis')
        ->and($primera['parametros']['SolicitudCuis']['nit'])->toBe(NIT_ASISTENTE)
        ->and($primera['parametros']['SolicitudCuis']['codigoSucursal'])->toBe(0)
        ->and($primera['parametros']['SolicitudCuis']['codigoPuntoVenta'])->toBe(0)
        ->and($primera['parametros']['SolicitudCuis']['codigoModalidad'])->toBe(ModalidadFacturacion::Computarizada->siat()->value)
        ->and($primera['parametros']['SolicitudCuis']['codigoSistema'])->toBe(SiatPruebas::SISTEMA);

    $configuracion = configuracionAsistente($empresa->id);
    $estado = app(VerificacionSiat::class)->estado($empresa->fresh());

    expect($configuracion->verificacion['resultado'])->toBe('aceptado')
        ->and($configuracion->verificado_en)->not->toBeNull()
        ->and(json_encode($configuracion->verificacion))->not->toContain('firmaSECRETA')
        ->and($estado['pasos']['modalidad']['estado'])->toBe(VerificacionSiat::VERIFICADO)
        ->and($estado['pasos']['token']['estado'])->toBe(VerificacionSiat::VERIFICADO)
        ->and($estado['catalogos'])->toBe([])
        ->and($estado['pasos']['firma']['estado'])->toBe(VerificacionSiat::NO_APLICA)
        ->and(collect($estado['revision'])->pluck('clave')->all())->toBe(['modalidad', 'nit', 'token', 'firma', 'conexion', 'sucursales', 'actividad'])
        // Conectado con el SIN pero sin sucursal registrada ni actividad: Revisar no puede estar listo.
        ->and($estado['listo'])->toBeFalse()
        ->and($estado['pasos']['revisar']['detalle'])->toBe('Falta sucursal o actividad')
        ->and(Auditoria::query()->where('accion', 'siat.configuracion_verificada')->count())->toBe(1);
});

test('al guardar y verificar, la sucursal por defecto queda vinculada a la sucursal 0 del SIN con el municipio', function () {
    [$empresa, $admin] = empresaConAdministrador(['siat_habilitado' => true]);
    $matriz = Sucursal::factory()->create(['empresa_id' => $empresa->id, 'nombre' => 'Casa Matriz']);
    Sucursal::factory()->create(['empresa_id' => $empresa->id, 'nombre' => 'Sucursal Norte']);
    $siatSucursales = fn () => SiatSucursal::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresa->id);

    $componente = Livewire::actingAs($admin)->test(Asistente::class, ['empresaId' => $empresa->id])
        ->call('ir', 'token')
        ->assertSee('«Casa Matriz» queda como la casa matriz (sucursal 0) del SIN.')
        ->set('nit', (string) NIT_ASISTENTE)
        ->set('razon_social', 'FARMACIA PARMA SRL')
        ->set('token', SiatPruebas::token(NIT_ASISTENTE))
        ->call('guardarConexion')
        ->assertHasErrors(['municipio']);

    expect($this->transporte->llamadas)->toBe([])
        ->and($siatSucursales()->exists())->toBeFalse();

    // Verificación, CUIS del punto 0 recién vinculado (para sincronizar) y los catálogos.
    $this->transporte->responder(SiatPruebas::cuis())->responder(SiatPruebas::cuis());
    foreach (Catalogo::cases() as $catalogo) {
        $this->transporte->responder(SiatPruebas::catalogo($catalogo, [['10', 'VALOR '.$catalogo->name]]));
    }

    $componente
        ->set('token', SiatPruebas::token(NIT_ASISTENTE))
        ->set('municipio', 'Santa Cruz')
        ->call('guardarConexion')
        ->assertHasNoErrors()
        ->assertSet('municipio', '')
        ->assertSet('paso', 'revisar');

    $vinculada = $siatSucursales()->sole();
    $punto = SiatPuntoVenta::withoutGlobalScope(EmpresaScope::class)->where('siat_sucursal_id', $vinculada->id)->sole();
    $estado = app(VerificacionSiat::class)->estado($empresa->fresh());

    expect($vinculada->sucursal_id)->toBe($matriz->id)
        ->and($vinculada->codigo)->toBe(0)
        ->and($vinculada->municipio)->toBe('Santa Cruz')
        ->and($punto->codigo)->toBe(0)
        ->and((bool) $punto->activo)->toBeTrue()
        ->and(Auditoria::query()->where('accion', 'siat.sucursal_registrada')->where('empresa_id', $empresa->id)->count())->toBe(1)
        // Las otras sucursales se registran a mano con su propio código del SIN.
        ->and(collect($estado['revision'])->firstWhere('clave', 'sucursales')['detalle'])->toContain('Sucursal Norte')
        ->and(collect($estado['revision'])->firstWhere('clave', 'sucursales')['detalle'])->not->toContain('Casa Matriz');

    // Ya vinculada, el paso 2 deja de pedir el municipio y no vuelve a vincular nada.
    $this->transporte->responder(SiatPruebas::cuis());
    $componente->call('ir', 'token')
        ->assertDontSee('queda como la casa matriz')
        ->call('guardarConexion')
        ->assertHasNoErrors();

    expect($siatSucursales()->count())->toBe(1);
});

test('Revisar solo queda listo con cada sucursal activa registrada en el SIN y con actividad y producto SIN', function () {
    $datos = SiatPruebas::empresaFiscal(NIT_ASISTENTE);
    SiatPruebas::catalogosSincronizados($datos['empresa']->id);
    $this->transporte->responder(SiatPruebas::cuis());
    $verificacion = app(VerificacionSiat::class);
    $punto = fn (array $estado, string $clave): array => collect($estado['revision'])->firstWhere('clave', $clave);

    // El caso reportado: verificado con el SIN, pero sin actividad y producto la venta no se factura.
    $estado = $verificacion->verificar($datos['admin'], $datos['empresa']);
    expect($estado['pasos']['token']['estado'])->toBe(VerificacionSiat::VERIFICADO)
        ->and($punto($estado, 'sucursales')['estado'])->toBe(VerificacionSiat::CORRECTO)
        ->and($punto($estado, 'actividad')['estado'])->toBe(VerificacionSiat::PENDIENTE)
        ->and($estado['listo'])->toBeFalse();

    $configuracion = fn (array $valores) => SiatConfiguracion::withoutGlobalScope(EmpresaScope::class)->whereKey($datos['configuracion']->id)->update($valores);

    $configuracion(['actividad_economica' => '999999', 'producto_sin' => 83141]);
    expect($punto($verificacion->estado($datos['empresa']), 'actividad')['estado'])->toBe(VerificacionSiat::ERROR);

    $configuracion(['actividad_economica' => '620100', 'producto_sin' => 83141]);
    $estado = $verificacion->estado($datos['empresa']);
    expect($punto($estado, 'actividad')['estado'])->toBe(VerificacionSiat::CORRECTO)
        ->and($estado['listo'])->toBeTrue()
        ->and($estado['pasos']['revisar']['estado'])->toBe(VerificacionSiat::VERIFICADO);

    // Una segunda sucursal activa sin registrar en el SIN: sus ventas no se facturan, Revisar deja de estar listo.
    Sucursal::factory()->create(['empresa_id' => $datos['empresa']->id, 'nombre' => 'Sucursal Norte']);
    $estado = $verificacion->estado($datos['empresa']);
    expect($punto($estado, 'sucursales')['estado'])->toBe(VerificacionSiat::PENDIENTE)
        ->and($punto($estado, 'sucursales')['detalle'])->toContain('Sucursal Norte')
        ->and($estado['listo'])->toBeFalse();

    Livewire::actingAs($datos['admin'])->test(Asistente::class, ['empresaId' => $datos['empresa']->id])
        ->call('ir', 'revisar')
        ->assertSee('Sucursales en el SIN')
        ->assertSee('las ventas quedan con la factura pendiente')
        ->assertDontSee('Habilitación de MoziStudios')
        ->assertDontSee('Sistema MoziStock en el SIN');

    // Con su punto de venta inactivo tampoco cuenta.
    $norte = new SiatSucursal;
    $norte->empresa_id = $datos['empresa']->id;
    $norte->sucursal_id = Sucursal::withoutGlobalScope(EmpresaScope::class)->where('nombre', 'Sucursal Norte')->value('id');
    $norte->codigo = 1;
    $norte->municipio = 'Santa Cruz';
    $norte->save();
    $pv = new SiatPuntoVenta;
    $pv->empresa_id = $datos['empresa']->id;
    $pv->siat_sucursal_id = $norte->id;
    $pv->codigo = 0;
    $pv->nombre = 'Norte';
    $pv->activo = false;
    $pv->save();
    expect($verificacion->estado($datos['empresa'])['listo'])->toBeFalse();

    $pv->activo = true;
    $pv->save();
    expect($verificacion->estado($datos['empresa'])['listo'])->toBeTrue();
});

test('si el SIN acepta el token pero un catálogo falla, el paso 2 queda pendiente y se puede reintentar', function () {
    $datos = SiatPruebas::empresaFiscal(NIT_ASISTENTE);
    // Verificación + CUIS de la sucursal principal; el catálogo de actividades falla.
    $this->transporte->responder(SiatPruebas::cuis())->responder(SiatPruebas::cuis());
    foreach (Catalogo::cases() as $catalogo) {
        $this->transporte->responder($catalogo === Catalogo::Actividades
            ? new ErrorComunicacion('El SIN no respondió a tiempo.')
            : SiatPruebas::catalogo($catalogo, [['10', 'VALOR '.$catalogo->name]]));
    }

    $componente = Livewire::actingAs($datos['admin'])->test(Asistente::class, ['empresaId' => $datos['empresa']->id])
        ->call('ir', 'token')
        ->set('token', SiatPruebas::token(NIT_ASISTENTE))
        ->call('guardarConexion')
        ->assertHasNoErrors()
        ->assertSet('paso', 'token')
        ->assertSet('avisoTipo', 'danger')
        ->assertSee('pero faltan catálogos')
        ->assertSee('Reintentar');

    $estado = app(VerificacionSiat::class)->estado($datos['empresa']);
    expect($estado['pasos']['token']['estado'])->toBe(VerificacionSiat::PENDIENTE)
        ->and($estado['catalogos'])->toBe([__(Catalogo::Actividades->etiqueta())]);

    // Reintento: la sucursal principal ya tiene CUIS guardado, solo vuelven a pedirse los catálogos.
    foreach (Catalogo::cases() as $catalogo) {
        $this->transporte->responder(SiatPruebas::catalogo($catalogo, [['10', 'VALOR '.$catalogo->name]]));
    }

    $componente->call('reintentarCatalogos')
        ->assertSet('paso', 'revisar')
        ->assertSet('avisoTipo', 'success');

    expect(app(VerificacionSiat::class)->estado($datos['empresa'])['pasos']['token']['estado'])->toBe(VerificacionSiat::VERIFICADO);
});

test('si el SIN rechaza el NIT o el token, el paso queda con error y muestra el mensaje del SIN', function () {
    [$empresa, $admin] = empresaConAdministrador(['siat_habilitado' => true]);
    $this->transporte->responder(['RespuestaCuis' => ['transaccion' => false, 'mensajesList' => [
        ['codigo' => 2, 'descripcion' => 'NIT INEXISTENTE O NO HABILITADO'],
    ]]]);

    Livewire::actingAs($admin)->test(Asistente::class, ['empresaId' => $empresa->id])
        ->call('ir', 'token')
        ->set('nit', (string) NIT_ASISTENTE)
        ->set('razon_social', 'FARMACIA PARMA SRL')
        ->set('token', SiatPruebas::token(NIT_ASISTENTE))
        ->call('guardarConexion')
        ->assertSet('paso', 'token')
        ->assertSet('avisoTipo', 'danger')
        ->assertSee('NIT INEXISTENTE O NO HABILITADO');

    $estado = app(VerificacionSiat::class)->estado($empresa->fresh());

    expect($estado['pasos']['token']['estado'])->toBe(VerificacionSiat::ERROR)
        ->and($estado['listo'])->toBeFalse()
        // Rechazado: no se intenta sincronizar catálogos con ese token.
        ->and(collect($this->transporte->llamadas)->pluck('operacion')->all())->toBe(['cuis']);
});

test('un 401 del SIN es token rechazado; un corte de red no marca error en los datos', function () {
    $datos = SiatPruebas::empresaFiscal(NIT_ASISTENTE);
    $verificacion = app(VerificacionSiat::class);

    $this->transporte->responder(new ErrorComunicacion('El SIN no respondió correctamente a FacturacionCodigos.cuis: Unauthorized | HTTP/1.1 401'));
    $estado = $verificacion->verificar($datos['admin'], $datos['empresa']);
    expect($estado['pasos']['token']['estado'])->toBe(VerificacionSiat::ERROR)
        ->and($estado['pasos']['token']['detalle'])->toContain('no aceptó el token');

    $this->transporte->responder(new ErrorComunicacion('El SIN no respondió a tiempo.'));
    $estado = $verificacion->verificar($datos['admin'], $datos['empresa']);
    expect($estado['pasos']['token']['estado'])->toBe(VerificacionSiat::CORRECTO)
        ->and(collect($estado['revision'])->firstWhere('clave', 'conexion')['detalle'])->toContain('No se pudo conectar');
});

test('sin habilitación de MoziStudios no se consulta al SIN y lo correcto se marca como correcto', function () {
    // Sin habilitación solo el superadmin abre Facturación de la empresa (el administrador aún no la ve).
    [$empresa] = empresaConAdministrador(['siat_habilitado' => false]);
    $superadmin = User::factory()->superadmin()->create();

    Livewire::actingAs($superadmin)->test(Asistente::class, ['empresaId' => $empresa->id])
        ->call('ir', 'token')
        ->set('nit', (string) NIT_ASISTENTE)
        ->set('razon_social', 'FARMACIA PARMA SRL')
        ->set('token', SiatPruebas::token(NIT_ASISTENTE))
        ->call('guardarConexion')
        ->assertHasNoErrors()
        ->assertSet('paso', 'revisar')
        ->assertSee('Se verificará cuando MoziStudios habilite la facturación de tu empresa.');

    $estado = app(VerificacionSiat::class)->estado($empresa->fresh());

    expect($this->transporte->llamadas)->toBe([])
        ->and($estado['pasos']['token']['estado'])->toBe(VerificacionSiat::CORRECTO)
        ->and($estado['sin'])->toBeNull();
});

test('el token de otro sistema se rechaza antes de llamar al SIN', function () {
    [$empresa, $admin] = empresaConAdministrador(['siat_habilitado' => true]);

    Livewire::actingAs($admin)->test(Asistente::class, ['empresaId' => $empresa->id])
        ->call('ir', 'token')
        ->set('nit', (string) NIT_ASISTENTE)
        ->set('razon_social', 'FARMACIA PARMA SRL')
        ->set('token', SiatPruebas::token(NIT_ASISTENTE, sistema: 'OTRO999SISTEMA'))
        ->call('guardarConexion')
        ->assertHasErrors(['token']);

    expect($this->transporte->llamadas)->toBe([])
        ->and(configuracionAsistente($empresa->id)->tieneToken())->toBeFalse();
});

test('la verificación deja de valer si cambia la modalidad', function () {
    $datos = SiatPruebas::empresaFiscal(NIT_ASISTENTE);
    SiatPruebas::catalogosSincronizados($datos['empresa']->id);
    $this->transporte->responder(SiatPruebas::cuis());
    $verificacion = app(VerificacionSiat::class);

    expect($verificacion->verificar($datos['admin'], $datos['empresa'])['pasos']['token']['estado'])->toBe(VerificacionSiat::VERIFICADO);

    SiatConfiguracion::withoutGlobalScope(EmpresaScope::class)->whereKey($datos['configuracion']->id)->update(['modalidad' => 'electronica']);

    $estado = $verificacion->estado($datos['empresa']);
    expect($estado['sin'])->toBeNull()
        ->and($estado['pasos']['token']['estado'])->toBe(VerificacionSiat::CORRECTO)
        ->and($estado['pasos']['firma']['estado'])->toBe(VerificacionSiat::PENDIENTE);
});

test('la modalidad electrónica se guarda antes de la firma y la firma queda pendiente hasta subir el .p12', function () {
    $datos = SiatPruebas::empresaFiscal(NIT_ASISTENTE);
    SiatPruebas::catalogosSincronizados($datos['empresa']->id);
    $this->transporte->responder(SiatPruebas::cuis());

    Livewire::actingAs($datos['admin'])->test(Asistente::class, ['empresaId' => $datos['empresa']->id])
        ->call('elegirModalidad', 'electronica')
        ->call('ir', 'token')
        ->call('guardarConexion')
        ->assertHasNoErrors()
        ->assertSet('paso', 'firma');

    expect(configuracionAsistente($datos['empresa']->id)->modalidad)->toBe(ModalidadFacturacion::Electronica)
        ->and($this->transporte->llamadas[0]['parametros']['SolicitudCuis']['codigoModalidad'])->toBe(ModalidadFacturacion::Electronica->siat()->value);

    $firma = collect(PreparacionFacturacion::revisar($datos['empresa']))->firstWhere('clave', 'firma');
    expect($firma['listo'])->toBeFalse();

    $estado = app(VerificacionSiat::class)->estado($datos['empresa']);
    expect($estado['pasos']['firma']['estado'])->toBe(VerificacionSiat::PENDIENTE)
        ->and($estado['listo'])->toBeFalse();
});

test('la firma digital se comprueba en MoziStock y una contraseña incorrecta no guarda el archivo', function () {
    $datos = SiatPruebas::empresaFiscal(NIT_ASISTENTE);
    SiatPruebas::catalogosSincronizados($datos['empresa']->id);
    $clave = 'ClaveFirma77!';
    // Al pasar a electrónica se vuelve a verificar con el SIN (otra modalidad).
    $this->transporte->responder(SiatPruebas::cuis());

    $componente = Livewire::actingAs($datos['admin'])->test(Asistente::class, ['empresaId' => $datos['empresa']->id])
        ->call('ir', 'firma')
        ->set('certificado', UploadedFile::fake()->createWithContent('firma.p12', p12Asistente($clave)))
        ->set('certificado_pass', 'otra-clave')
        ->call('guardarFirma')
        ->assertHasErrors(['certificado_pass']);

    expect(Storage::disk('local')->allFiles('certificados'))->toBeEmpty();

    $componente
        ->set('certificado', UploadedFile::fake()->createWithContent('firma.p12', p12Asistente($clave)))
        ->set('certificado_pass', $clave)
        ->call('guardarFirma')
        ->assertHasNoErrors()
        ->assertSet('certificado_pass', '')
        ->assertSet('paso', 'revisar');

    $configuracion = configuracionAsistente($datos['empresa']->id);
    $estado = app(VerificacionSiat::class)->estado($datos['empresa']);

    expect($configuracion->modalidad)->toBe(ModalidadFacturacion::Electronica)
        ->and(Storage::disk('local')->allFiles('certificados'))->toHaveCount(1)
        ->and($estado['pasos']['firma']['estado'])->toBe(VerificacionSiat::CORRECTO);
});

test('un certificado vencido se rechaza al subirlo', function () {
    $datos = SiatPruebas::empresaFiscal(NIT_ASISTENTE);
    $clave = 'ClaveFirma77!';
    // Vigencia de 0 días: vence en este instante; un minuto después ya está vencido.
    $p12 = p12Asistente($clave, 0);
    $this->travel(1)->minutes();

    Livewire::actingAs($datos['admin'])->test(Asistente::class, ['empresaId' => $datos['empresa']->id])
        ->call('ir', 'firma')
        ->set('certificado', UploadedFile::fake()->createWithContent('vieja.p12', $p12))
        ->set('certificado_pass', $clave)
        ->call('guardarFirma')
        ->assertHasErrors(['certificado']);

    expect(Storage::disk('local')->allFiles('certificados'))->toBeEmpty();
});

test('el administrador de otra empresa no puede abrir ni verificar el asistente de la empresa A', function () {
    $a = SiatPruebas::empresaFiscal(NIT_ASISTENTE);
    [, $adminB] = empresaConAdministrador(['siat_habilitado' => true]);

    Livewire::actingAs($adminB)->test(Asistente::class, ['empresaId' => $a['empresa']->id])->assertForbidden();

    expect(fn () => app(VerificacionSiat::class)->verificar($adminB, $a['empresa']))
        ->toThrow(AuthorizationException::class)
        ->and($this->transporte->llamadas)->toBe([]);
});

test('Facturación → Datos fiscales muestra el asistente', function () {
    [$empresa, $admin] = empresaConAdministrador(['siat_habilitado' => true]);

    Livewire::actingAs($admin)->test(Panel::class)
        ->assertSeeLivewire(Asistente::class)
        ->assertSee('Conecta tu empresa con Impuestos Nacionales');
});

test('en la guía del Inicio el asistente avisa al guardar y la firma pendiente detiene el paso SIAT', function () {
    $datos = SiatPruebas::empresaFiscal(NIT_ASISTENTE);
    SiatPruebas::catalogosSincronizados($datos['empresa']->id);
    $this->transporte->responder(SiatPruebas::cuis());
    $this->actingAs($datos['admin']);

    Livewire::test(Guia::class)->call('abrir', 'sucursal-siat')->call('cerrar')
        ->assertDispatched('siat-refrescar');

    Livewire::test(Asistente::class, ['empresaId' => $datos['empresa']->id, 'embebido' => true])
        ->call('elegirModalidad', 'electronica')
        ->call('ir', 'token')
        ->call('guardarConexion')
        ->assertDispatched('siat-actualizado');

    $siat = GuiaConfiguracion::para($datos['admin'])['siat'];
    $firma = collect($siat['pasos'][0]['puntos'])->firstWhere('clave', 'firma');

    expect($firma)->not->toBeNull()
        ->and($firma['listo'])->toBeFalse()
        ->and($siat['pasos'][0]['listo'])->toBeFalse();
});
