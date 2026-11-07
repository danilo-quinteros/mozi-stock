<?php

use App\Domain\Facturacion\Actions\GuardarDatosFiscales;
use App\Domain\Facturacion\EmisionFacturas;
use App\Domain\Facturacion\Firma\FirmadorXmlDSig;
use App\Enums\ModalidadFacturacion;
use App\Enums\ModoFacturacion;
use App\Livewire\Facturacion\Datos;
use App\Models\Scopes\EmpresaScope;
use App\Models\SiatConfiguracion;
use App\Models\SiatFactura;
use App\Models\Venta;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use MoziStudios\Siat\Sincronizacion\Catalogo;
use Tests\Feature\Facturacion\SiatPruebas;

const ACTIVIDAD_ELEC = '477300';

beforeEach(function () {
    $this->transporte = SiatPruebas::configurar();
    Storage::fake('local');
});

function generarCertificadoP12Prueba(string $password = 'Secreto123!'): string
{
    $dn = [
        'countryName' => 'BO',
        'stateOrProvinceName' => 'La Paz',
        'localityName' => 'La Paz',
        'organizationName' => 'Empresa GRACO PRICO SRL',
        'commonName' => 'Certificado Digital Pruebas',
    ];

    $privkey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    $csr = openssl_csr_new($dn, $privkey, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $privkey, 365, ['digest_alg' => 'sha256']);

    $p12 = '';
    openssl_pkcs12_export($cert, $p12, $privkey, $password);

    return $p12;
}

function empresaParaPruebaElectronica(int $nit = 1023456029): array
{
    $datos = SiatPruebas::empresaFiscal($nit);
    $empresaId = $datos['empresa']->id;
    $catalogos = [
        Catalogo::TiposDocumentoIdentidad->name => [['1', 'CI - CEDULA DE IDENTIDAD'], ['5', 'NIT - NÚMERO DE IDENTIFICACIÓN TRIBUTARIA']],
        Catalogo::TiposMetodoPago->name => [['1', 'EFECTIVO'], ['2', 'TARJETA'], ['7', 'TRANSFERENCIA BANCARIA']],
        Catalogo::Actividades->name => [[ACTIVIDAD_ELEC, 'VENTA AL POR MENOR DE PRODUCTOS FARMACÉUTICOS']],
        Catalogo::ProductosServicios->name => [['62271', 'MEDICAMENTOS', ['codigoActividad' => ACTIVIDAD_ELEC]]],
        Catalogo::UnidadesMedida->name => [['57', 'UNIDAD (BIENES)']],
        Catalogo::LeyendasFactura->name => [[ACTIVIDAD_ELEC, 'Ley N° 453: Tienes derecho a recibir información sobre las características de los productos.']],
        Catalogo::MotivosAnulacion->name => [['1', 'FACTURA MAL EMITIDA']],
    ];

    foreach ($catalogos as $catalogo => $filas) {
        DB::table('siat_catalogos')->insert(array_map(fn (array $f): array => [
            'empresa_id' => $empresaId,
            'catalogo' => $catalogo,
            'codigo' => $f[0],
            'descripcion' => $f[1],
            'atributos' => isset($f[2]) ? json_encode($f[2]) : null,
        ], $filas));
    }

    $datos['configuracion']->forceFill([
        'modo' => ModoFacturacion::Automatica,
        'modalidad' => ModalidadFacturacion::Computarizada,
        'actividad_economica' => ACTIVIDAD_ELEC,
        'producto_sin' => 62271,
        'unidad_medida' => 57,
    ])->save();

    $cuis = DB::table('siat_cuis')->insertGetId([
        'empresa_id' => $empresaId,
        'siat_punto_venta_id' => $datos['punto']->id,
        'ambiente' => 2,
        'codigo_sistema' => SiatPruebas::SISTEMA,
        'codigo' => 'C2FD8E5A',
        'vence_en' => now()->addYear(),
    ]);

    DB::table('siat_cufd')->insert([
        'empresa_id' => $empresaId,
        'siat_punto_venta_id' => $datos['punto']->id,
        'siat_cuis_id' => $cuis,
        'codigo' => 'BQUE+QytqQUDBKVUFOSVRPQkxVRFZNVFVJBMDAwMDAwMA19E23EF34124CD',
        'codigo_control' => 'A19E23EF34124CD',
        'codigo_interno' => 'C',
        'direccion' => 'AV. BANZER 123',
        'vence_en' => now()->addDay(),
    ]);

    $datos['producto'] = productoDe($datos['empresa'], [
        'nombre' => 'Paracetamol 500',
        'sku' => 'PARA-500',
        'precio_consumidor' => '113.00',
    ]);

    return $datos;
}

function crearVentaParaPrueba(array $datos): Venta
{
    test()->actingAs($datos['admin']);
    cargarStock($datos['admin'], $datos['sucursal'], $datos['producto'], '50');
    $pedido = crearPedido($datos['admin'], $datos['sucursal'], [['producto_id' => $datos['producto']->id, 'cantidad' => '1']]);

    return app(\App\Domain\Ventas\Actions\ConfirmarVenta::class)->ejecutar($datos['admin'], $pedido, [
        'metodo' => 'efectivo',
        'en_cuotas' => false,
        'cuota_inicial' => null,
        'efectivo' => null,
        'electronico' => null,
        'externo' => true,
        'clave' => (string) Str::uuid(),
    ]);
}

test('las empresas tienen por defecto la modalidad computarizada', function () {
    $datos = empresaParaPruebaElectronica();
    $configuracion = SiatConfiguracion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $datos['empresa']->id)->first();

    expect($configuracion->modalidad)->toBe(ModalidadFacturacion::Computarizada)
        ->and($configuracion->esComputarizada())->toBeTrue()
        ->and($configuracion->esElectronica())->toBeFalse()
        ->and($configuracion->tieneCertificado())->toBeFalse();
});

test('cambiar a modalidad electrónica sin certificado falla la validación', function () {
    $datos = empresaParaPruebaElectronica();

    test()->actingAs($datos['admin']);

    Livewire::test(Datos::class, ['empresaId' => $datos['empresa']->id])
        ->set('modalidad', 'electronica')
        ->set('certificado', null)
        ->set('certificado_pass', '')
        ->call('guardarDatos')
        ->assertHasErrors(['certificado']);
});

test('subir un archivo .p12 con contraseña incorrecta es rechazado y no se guarda', function () {
    $datos = empresaParaPruebaElectronica();
    $p12Contenido = generarCertificadoP12Prueba('ClaveCorrecta99!');

    $archivo = UploadedFile::fake()->createWithContent('softoken.p12', $p12Contenido);

    test()->actingAs($datos['admin']);

    Livewire::test(Datos::class, ['empresaId' => $datos['empresa']->id])
        ->set('modalidad', 'electronica')
        ->set('certificado', $archivo)
        ->set('certificado_pass', 'ClaveIncorrecta')
        ->call('guardarDatos')
        ->assertHasErrors(['certificado_pass']);

    expect(Storage::disk('local')->allFiles('certificados'))->toBeEmpty();
});

test('subir archivo .p12 con contraseña correcta guarda en storage privado y cifra la clave en BD', function () {
    $datos = empresaParaPruebaElectronica();
    $claveReal = 'ClaveCorrecta99!';
    $p12Contenido = generarCertificadoP12Prueba($claveReal);

    $archivo = UploadedFile::fake()->createWithContent('mifirma.p12', $p12Contenido);

    test()->actingAs($datos['admin']);

    Livewire::test(Datos::class, ['empresaId' => $datos['empresa']->id])
        ->set('modalidad', 'electronica')
        ->set('certificado', $archivo)
        ->set('certificado_pass', $claveReal)
        ->call('guardarDatos')
        ->assertHasNoErrors();

    $configuracion = SiatConfiguracion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $datos['empresa']->id)->first();

    expect($configuracion->modalidad)->toBe(ModalidadFacturacion::Electronica)
        ->and($configuracion->esElectronica())->toBeTrue()
        ->and($configuracion->tieneCertificado())->toBeTrue()
        ->and($configuracion->certificado_nombre)->toBe('mifirma.p12')
        ->and($configuracion->certificado_ruta)->not->toBeNull()
        ->and(Storage::disk('local')->exists($configuracion->certificado_ruta))->toBeTrue()
        ->and($configuracion->certificadoPasswordDescifrado())->toBe($claveReal)
        ->and($configuracion->certificado_vence_en)->not->toBeNull()
        ->and($configuracion->certificadoVencido())->toBeFalse();

    // Verificación de seguridad: la contraseña cruda jamás debe guardarse en texto plano en la BD
    $filaRaw = DB::table('siat_configuraciones')->where('id', $configuracion->id)->first();
    expect($filaRaw->certificado_pass)->not->toBe($claveReal)
        ->and(str_contains($filaRaw->certificado_pass, $claveReal))->toBeFalse();
});

test('emisión de venta para empresa electrónica prepara factura electrónica y firma con XMLDSig', function () {
    $datos = empresaParaPruebaElectronica();
    $clave = 'Srv@0789';
    $p12Path = dirname(__DIR__, 3).'/storage/app/private/siat-certificacion/softoken.p12';

    if (! file_exists($p12Path)) {
        $this->markTestSkipped('Archivo softoken.p12 no disponible.');
    }

    // Copiar el archivo real al fake local storage para la prueba
    $rutaEnDisk = 'certificados/empresa_'.$datos['empresa']->id.'_test.p12';
    Storage::disk('local')->put($rutaEnDisk, file_get_contents($p12Path));

    $datos['configuracion']->update([
        'modalidad' => ModalidadFacturacion::Electronica,
        'certificado_ruta' => $rutaEnDisk,
        'certificado_nombre' => 'softoken.p12',
        'certificado_pass' => $clave,
        'certificado_vence_en' => now()->addYear(),
    ]);

    $this->transporte->responder([
        'RespuestaServicioFacturacion' => [
            'transaccion' => true,
            'codigoEstado' => 908,
            'codigoDescripcion' => 'RECEPCIONADA',
            'codigoRecepcion' => 'REC-908',
            'mensajesList' => [],
        ],
    ]);

    $venta = crearVentaParaPrueba($datos);
    $factura = SiatFactura::withoutGlobalScope(EmpresaScope::class)->where('venta_id', $venta->id)->latest('id')->firstOrFail();

    // Procesar la emisión de la factura
    app(EmisionFacturas::class)->procesar($datos['empresa']->id, $factura->id);

    $facturaRefrescada = $factura->fresh();

    expect($facturaRefrescada->xml)->not->toBeNull()
        // Debe usar el elemento raíz de factura electrónica
        ->and(str_contains($facturaRefrescada->xml, 'facturaElectronicaCompraVenta'))->toBeTrue()
        ->and(str_contains($facturaRefrescada->xml, 'facturaElectronicaCompraVenta.xsd'))->toBeTrue()
        // Debe contener la firma digital XMLDSig
        ->and(str_contains($facturaRefrescada->xml, '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"'))->toBeTrue()
        ->and(str_contains($facturaRefrescada->xml, '<X509Certificate>'))->toBeTrue()
        // El CUF debe haber sido generado
        ->and($facturaRefrescada->cuf)->not->toBeNull();
});

test('aislamiento: el certificado y modalidad de una empresa no interfieren con otra', function () {
    $empresaA = empresaParaPruebaElectronica(1023456029);
    $empresaB = empresaParaPruebaElectronica(9876543210);

    $p12Contenido = generarCertificadoP12Prueba('PassEmpresaA!');
    $rutaA = 'certificados/empresa_'.$empresaA['empresa']->id.'.p12';
    Storage::disk('local')->put($rutaA, $p12Contenido);

    $empresaA['configuracion']->update([
        'modalidad' => ModalidadFacturacion::Electronica,
        'certificado_ruta' => $rutaA,
        'certificado_nombre' => 'empresaA.p12',
        'certificado_pass' => 'PassEmpresaA!',
        'certificado_vence_en' => now()->addMonths(6),
    ]);

    // Empresa B debe seguir siendo computarizada y sin certificado
    $configB = SiatConfiguracion::withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresaB['empresa']->id)->first();
    expect($configB->modalidad)->toBe(ModalidadFacturacion::Computarizada)
        ->and($configB->esComputarizada())->toBeTrue()
        ->and($configB->tieneCertificado())->toBeFalse();

    // Administrador de Empresa B no puede modificar los datos fiscales de Empresa A
    test()->actingAs($empresaB['admin']);

    $accion = app(GuardarDatosFiscales::class);

    expect(function () use ($accion, $empresaB, $empresaA) {
        $accion->ejecutar($empresaB['admin'], $empresaA['empresa'], [
            'nit' => '1023456029',
            'razon_social' => 'Intento Hack',
            'modo' => 'automatica',
            'modalidad' => 'computarizada',
        ]);
    })->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

test('si el SIN responde con error 1003 (CUFD invalido), se renueva automaticamente el CUFD y se valida la factura', function () {
    $datos = empresaParaPruebaElectronica();
    $p12Path = dirname(__DIR__, 3).'/storage/app/private/siat-certificacion/softoken.p12';

    if (! file_exists($p12Path)) {
        $this->markTestSkipped('Archivo softoken.p12 no disponible.');
    }

    $rutaCert = 'certificados/empresa_'.$datos['empresa']->id.'_retry.p12';
    Storage::disk('local')->put($rutaCert, file_get_contents($p12Path));

    $datos['configuracion']->update([
        'modalidad' => ModalidadFacturacion::Electronica,
        'certificado_ruta' => $rutaCert,
        'certificado_nombre' => 'softoken.p12',
        'certificado_pass' => 'Srv@0789',
        'certificado_vence_en' => now()->addMonths(6),
    ]);

    // 1ª respuesta de recepción: SIN rechaza con 1003 (CUFD inválido)
    $this->transporte->responder([
        'RespuestaServicioFacturacion' => [
            'codigoEstado' => 902,
            'codigoRecepcion' => null,
            'transaccion' => false,
            'mensajesList' => [
                'codigo' => 1003,
                'descripcion' => 'EL CODIGO UNICO DE FACTURACION DIARIA (CUFD) ENVIADO EN EL XML ES INVALIDO',
            ],
        ],
    ]);

    // Respuesta a la solicitud de nuevo CUFD fresco
    $this->transporte->responder([
        'RespuestaCufd' => [
            'transaccion' => true,
            'codigo' => 'BQUE+NUEVO_CUFD_FRESCO_12345678901234567890',
            'codigoControl' => 'A19E23EF34124CD',
            'codigoInterno' => 'C',
            'direccion' => 'AV. BANZER 123',
            'fechaVigencia' => now()->addDay()->format('Y-m-d\TH:i:s.v'),
        ],
    ]);

    // 2ª respuesta de recepción: SIN valida la factura (908)
    $this->transporte->responder([
        'RespuestaServicioFacturacion' => [
            'codigoEstado' => 908,
            'codigoRecepcion' => '99998888-RECEPCION-OK',
            'transaccion' => true,
            'mensajesList' => [],
        ],
    ]);

    $venta = crearVentaParaPrueba($datos);
    $factura = SiatFactura::withoutGlobalScope(EmpresaScope::class)->where('venta_id', $venta->id)->latest('id')->firstOrFail();

    app(EmisionFacturas::class)->procesar($datos['empresa']->id, $factura->id);

    $facturaRefrescada = $factura->fresh();

    expect($facturaRefrescada->estado)->toBe(\App\Enums\EstadoFactura::Validada)
        ->and($facturaRefrescada->codigo_estado)->toBe(908)
        ->and($facturaRefrescada->intentos)->toBe(2)
        ->and($facturaRefrescada->cuf)->toContain('A19E23EF34124CD');
});
