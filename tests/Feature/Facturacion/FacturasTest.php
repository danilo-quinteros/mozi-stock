<?php

use App\Domain\Facturacion\Actions\AnularFactura;
use App\Domain\Facturacion\Actions\EmitirFactura;
use App\Domain\Facturacion\Actions\GuardarDatosFiscales;
use App\Domain\Facturacion\DatosFactura;
use App\Domain\Facturacion\EmisionFacturas;
use App\Domain\Facturacion\ErrorFacturacion;
use App\Domain\Facturacion\Montos\CalculadoraFiscal;
use App\Domain\Ventas\Actions\ConfirmarVenta;
use App\Domain\Ventas\Actions\RegistrarDevolucion;
use App\Enums\EstadoFactura;
use App\Enums\MetodoPago;
use App\Enums\ModoFacturacion;
use App\Http\Controllers\ImpersonacionController;
use App\Jobs\Siat\EnviarFactura;
use App\Livewire\Facturacion\Asistente;
use App\Livewire\Facturacion\Facturas;
use App\Livewire\Pedidos\Editor;
use App\Livewire\Pedidos\Lista as PedidosLista;
use App\Livewire\Ventas\Factura;
use App\Livewire\Ventas\Pos;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\MovimientoCaja;
use App\Models\PagoVenta;
use App\Models\Scopes\EmpresaScope;
use App\Models\SiatFactura;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use MoziStudios\Siat\Excepciones\ErrorComunicacion;
use MoziStudios\Siat\Sincronizacion\Catalogo;
use Tests\Feature\Facturacion\SiatPruebas;

/*
 * Fase 3 SIAT: factura de una venta confirmada, envío al SIN (job), anulación/reversión, impresión
 * y aislamiento. Sin red: el transporte del paquete es falso y responde lo que cada prueba prepara.
 */

const ACTIVIDAD_PRUEBA = '477300';

beforeEach(function () {
    $this->transporte = SiatPruebas::configurar();
});

/**
 * Empresa lista para facturar: catálogos sincronizados, actividad/producto/unidad, CUIS y CUFD vigentes
 * (así el SIN falso solo recibe las operaciones de la factura). Producto de Bs 113 (neto 100 + IVA 13).
 *
 * @return array<string, mixed>
 */
function empresaFacturable(int $nit = 1023456029, ModoFacturacion $modo = ModoFacturacion::Automatica): array
{
    $datos = SiatPruebas::empresaFiscal($nit);
    $empresaId = $datos['empresa']->id;
    $catalogos = [
        Catalogo::TiposDocumentoIdentidad->name => [['1', 'CI - CEDULA DE IDENTIDAD'], ['5', 'NIT - NÚMERO DE IDENTIFICACIÓN TRIBUTARIA']],
        Catalogo::TiposMetodoPago->name => [
            ['1', 'EFECTIVO'], ['2', 'TARJETA'], ['7', 'TRANSFERENCIA BANCARIA'],
            ['13', 'EFECTIVO – TRANSFERENCIA BANCARIA'], ['33', 'PAGO POSTERIOR'],
            ['34', 'EFECTIVO – PAGO POSTERIOR'], ['57', 'PAGO POSTERIOR – TRANSFERENCIA BANCARIA'],
        ],
        Catalogo::Actividades->name => [[ACTIVIDAD_PRUEBA, 'VENTA AL POR MENOR DE PRODUCTOS FARMACÉUTICOS']],
        Catalogo::ProductosServicios->name => [['62271', 'MEDICAMENTOS', ['codigoActividad' => ACTIVIDAD_PRUEBA]], ['83141', 'SERVICIOS DE SOFTWARE', ['codigoActividad' => '620100']]],
        Catalogo::UnidadesMedida->name => [['57', 'UNIDAD (BIENES)'], ['58', 'UNIDAD (SERVICIOS)']],
        Catalogo::LeyendasFactura->name => [[ACTIVIDAD_PRUEBA, 'Ley N° 453: Tienes derecho a recibir información sobre las características de los productos.']],
        Catalogo::MotivosAnulacion->name => [['1', 'FACTURA MAL EMITIDA'], ['3', 'DATOS DE EMISION INCORRECTOS']],
    ];

    foreach ($catalogos as $catalogo => $filas) {
        DB::table('siat_catalogos')->insert(array_map(fn (array $f): array => [
            'empresa_id' => $empresaId, 'catalogo' => $catalogo, 'codigo' => $f[0], 'descripcion' => $f[1],
            'atributos' => isset($f[2]) ? json_encode($f[2]) : null,
        ], $filas));
        DB::table('siat_sincronizaciones')->insert(['empresa_id' => $empresaId, 'catalogo' => $catalogo, 'cantidad' => count($filas),
            'sincronizado_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    $datos['configuracion']->forceFill(['modo' => $modo, 'actividad_economica' => ACTIVIDAD_PRUEBA, 'producto_sin' => 62271, 'unidad_medida' => 57])->save();
    $cuis = DB::table('siat_cuis')->insertGetId(['empresa_id' => $empresaId, 'siat_punto_venta_id' => $datos['punto']->id, 'ambiente' => 2,
        'codigo_sistema' => SiatPruebas::SISTEMA, 'codigo' => 'C2FD8E5A', 'vence_en' => now()->addYear()]);
    DB::table('siat_cufd')->insert(['empresa_id' => $empresaId, 'siat_punto_venta_id' => $datos['punto']->id, 'siat_cuis_id' => $cuis,
        'codigo' => 'BQUE+QytqQUDBKVUFOSVRPQkxVRFZNVFVJBMDAwMDAwMA19E23EF34124CD', 'codigo_control' => 'A19E23EF34124CD',
        'codigo_interno' => 'C', 'direccion' => 'AV. BANZER 123', 'vence_en' => now()->addDay()]);

    $datos['producto'] = productoDe($datos['empresa'], ['nombre' => 'Paracetamol 500', 'sku' => 'PARA-500', 'precio_consumidor' => '113.00']);

    return $datos;
}

/**
 * @param  list<array{codigo: int, descripcion: string}>  $mensajes
 * @return array<string, mixed>
 */
function respuestaSin(int $estado, array $mensajes = [], string $descripcion = ''): array
{
    return ['RespuestaServicioFacturacion' => ['transaccion' => in_array($estado, [901, 905, 907, 908], true), 'codigoEstado' => $estado,
        'codigoDescripcion' => $descripcion, 'codigoRecepcion' => 'REC-'.$estado, 'mensajesList' => $mensajes]];
}

/** Venta en efectivo de $cantidad unidades del producto de la empresa facturable. */
function ventaFacturable(array $datos, string $cantidad = '1', ?DatosFactura $factura = null): Venta
{
    test()->actingAs($datos['admin']);
    cargarStock($datos['admin'], $datos['sucursal'], $datos['producto'], '50');
    $pedido = crearPedido($datos['admin'], $datos['sucursal'], [['producto_id' => $datos['producto']->id, 'cantidad' => $cantidad]]);

    return app(ConfirmarVenta::class)->ejecutar($datos['admin'], $pedido, ['metodo' => 'efectivo', 'en_cuotas' => false,
        'cuota_inicial' => null, 'efectivo' => null, 'electronico' => null, 'externo' => true, 'clave' => (string) Str::uuid()], $factura);
}

function facturaDe(Venta $venta): SiatFactura
{
    return SiatFactura::withoutGlobalScope(EmpresaScope::class)->where('venta_id', $venta->id)->latest('id')->firstOrFail();
}

test('confirmar una venta registra su factura y el job la deja validada por el SIN', function () {
    $datos = empresaFacturable();
    $this->transporte->responder(respuestaSin(908, descripcion: 'VALIDADA'));

    $venta = ventaFacturable($datos);
    $factura = facturaDe($venta);
    $solicitud = $this->transporte->llamadas[0]['parametros']['SolicitudServicioRecepcionFactura'];

    expect($factura->estado)->toBe(EstadoFactura::Validada)
        ->and($factura->numero)->toBe(1)
        ->and($factura->total)->toBe('113.00')
        ->and($factura->total_precio_neto)->toBe('100.00')
        ->and($factura->iva)->toBe('13.00')
        ->and($factura->numero_documento)->toBe(DatosFactura::SIN_DATOS_DOCUMENTO)
        ->and($factura->razon_social)->toBe(DatosFactura::SIN_DATOS_RAZON_SOCIAL)
        ->and($factura->codigo_excepcion)->toBe(1)
        ->and($factura->metodo_pago)->toBe(1)
        ->and($factura->cuf)->not->toBeNull()
        ->and($factura->xml)->toContain('<numeroFactura>1</numeroFactura>')
        ->and($factura->xml)->toMatch('#<montoaPagar>113(\.00?)?</montoaPagar>#')
        ->and($factura->xml)->toMatch('#<iva>13(\.00?)?</iva>#')
        ->and($factura->leyenda)->toStartWith('Ley N° 453')
        ->and(array_column($this->transporte->llamadas, 'operacion'))->toBe(['recepcionFactura'])
        ->and($solicitud['codigoPuntoVenta'])->toBe(0)
        ->and($solicitud['nit'])->toBe(1023456029)
        ->and(Auditoria::query()->whereIn('accion', ['siat.factura.registrada', 'siat.factura.validada'])->count())->toBe(2);

    // Facturar no es cobrar: un solo pago y un solo movimiento de caja, los de la venta.
    expect(PagoVenta::query()->count())->toBe(1)
        ->and((string) MovimientoCaja::query()->sum('monto'))->toBe('113.00');
});

test('en modo manual la venta solo se factura si se pide, y la factura espera al job', function () {
    Queue::fake();
    $datos = empresaFacturable(modo: ModoFacturacion::Manual);

    $sinFactura = ventaFacturable($datos);
    $conFactura = ventaFacturable($datos, factura: new DatosFactura(DatosFactura::TIPO_CI, '4455667', '1B', 'Carlos Mendoza', 1));
    $factura = facturaDe($conFactura);

    expect(SiatFactura::query()->where('venta_id', $sinFactura->id)->exists())->toBeFalse()
        ->and($factura->estado)->toBe(EstadoFactura::Pendiente)
        ->and($factura->complemento)->toBe('1B')
        ->and($factura->codigo_excepcion)->toBe(0)
        ->and($factura->numero)->toBeNull()
        ->and($this->transporte->llamadas)->toBe([]);
    Queue::assertPushed(EnviarFactura::class, fn (EnviarFactura $job) => $job->facturaId === $factura->id && $job->empresaId === $datos['empresa']->id);
});

test('un total sin neto exacto deja la factura bloqueada con los totales vecinos y la venta confirmada', function () {
    Queue::fake();
    $datos = empresaFacturable();
    $precio = '10.00';

    while (CalculadoraFiscal::totalesVecinos($precio) === null) {
        $precio = bcadd($precio, '0.01', 2);
    }

    [$inferior, $superior] = CalculadoraFiscal::totalesVecinos($precio);
    $datos['producto']->forceFill(['precio_consumidor' => $precio])->save();
    $venta = ventaFacturable($datos);
    $factura = facturaDe($venta);

    expect($venta->total)->toBe($precio)
        ->and(stockDe($datos['sucursal'], $datos['producto']))->toBe('49.000')
        ->and($factura->estado)->toBe(EstadoFactura::Bloqueada)
        ->and($factura->motivo)->toContain("Bs {$inferior}")->toContain("Bs {$superior}")
        ->and($factura->iva)->toBeNull()
        ->and($this->transporte->llamadas)->toBe([]);
    Queue::assertNotPushed(EnviarFactura::class);
});

test('sin configuración de producto la factura queda bloqueada con todos los motivos juntos', function () {
    Queue::fake();
    $datos = empresaFacturable();
    $datos['configuracion']->forceFill(['actividad_economica' => null])->save();

    $factura = facturaDe(ventaFacturable($datos, factura: new DatosFactura(DatosFactura::TIPO_NIT, '99002', null, 'S/N', 2, codigoExcepcion: 1)));

    expect($factura->estado)->toBe(EstadoFactura::Bloqueada)
        ->and($factura->motivo)->toContain('actividad económica')
        ->and($factura->motivo)->not->toContain('tarjeta');   // sin actividad no se revisan los catálogos: un motivo por vez
});

test('sin respuesta del SIN la factura sigue pendiente y el reintento consulta su estado antes de reenviar', function () {
    Queue::fake();
    $datos = empresaFacturable();
    $factura = facturaDe(ventaFacturable($datos));
    $emision = app(EmisionFacturas::class);

    $this->transporte->responder(new ErrorComunicacion('tiempo de espera agotado'));
    expect(fn () => $emision->procesar($datos['empresa']->id, $factura->id))->toThrow(ErrorComunicacion::class);

    $factura->refresh();
    $cuf = $factura->cuf;
    expect($factura->estado)->toBe(EstadoFactura::Pendiente)
        ->and($factura->intentos)->toBe(1)
        ->and($factura->motivo)->toContain('Sin respuesta del SIN')
        ->and($cuf)->not->toBeNull();

    // El SIN sí la había recibido: se toma su estado sin enviarla otra vez.
    $this->transporte->responder(respuestaSin(908));
    expect($emision->procesar($datos['empresa']->id, $factura->id))->toBeFalse();

    $factura->refresh();
    expect($factura->estado)->toBe(EstadoFactura::Validada)
        ->and($factura->cuf)->toBe($cuf)
        ->and($factura->intentos)->toBe(1)
        ->and(array_column($this->transporte->llamadas, 'operacion'))->toBe(['recepcionFactura', 'verificacionEstadoFactura']);
});

test('901 deja la factura pendiente para volver a consultar; un rechazo se guarda con el mensaje del SIN', function () {
    Queue::fake();
    $datos = empresaFacturable();
    $emision = app(EmisionFacturas::class);

    $pendiente = facturaDe(ventaFacturable($datos));
    $this->transporte->responder(respuestaSin(901));
    expect($emision->procesar($datos['empresa']->id, $pendiente->id))->toBeTrue()
        ->and($pendiente->refresh()->estado)->toBe(EstadoFactura::Pendiente);

    $rechazada = facturaDe(ventaFacturable($datos));
    $this->transporte->responder(respuestaSin(902, [['codigo' => 1037, 'descripcion' => 'NIT INEXISTENTE']], 'RECHAZADA'));
    expect($emision->procesar($datos['empresa']->id, $rechazada->id))->toBeFalse();

    $rechazada->refresh();
    expect($rechazada->estado)->toBe(EstadoFactura::Rechazada)
        ->and($rechazada->estado->reintentable())->toBeTrue()
        ->and($rechazada->motivo)->toContain('NIT INEXISTENTE')
        ->and($rechazada->mensajes)->toBe([['codigo' => 1037, 'descripcion' => 'NIT INEXISTENTE']]);
});

test('una factura rechazada se corrige y vuelve a la cola conservando su número', function () {
    Queue::fake();
    $datos = empresaFacturable();
    $factura = facturaDe(ventaFacturable($datos));
    $this->transporte->responder(respuestaSin(902, [['codigo' => 1037, 'descripcion' => 'NIT INEXISTENTE']]));
    app(EmisionFacturas::class)->procesar($datos['empresa']->id, $factura->id);

    $corregida = app(EmitirFactura::class)->reintentar($datos['admin'], $factura->refresh(),
        new DatosFactura(DatosFactura::TIPO_NIT, '1020304050', null, 'COMERCIAL SUR SRL', 1, codigoExcepcion: 1));

    expect($corregida->estado)->toBe(EstadoFactura::Pendiente)
        ->and($corregida->numero)->toBe(1)
        ->and($corregida->numero_documento)->toBe('1020304050')
        ->and($corregida->xml)->toBeNull()
        ->and(Auditoria::query()->where('accion', 'siat.factura.reintentada')->count())->toBe(1);
    Queue::assertPushed(EnviarFactura::class, 2);
});

test('una factura que quedó sin método de pago fiscal lo toma del pago de la venta al reintentar', function () {
    Queue::fake();
    $datos = empresaFacturable();
    // Así la registra la venta cuando aún no había catálogos sincronizados: sin método fiscal.
    $factura = facturaDe(ventaFacturable($datos, factura: new DatosFactura(DatosFactura::TIPO_NIT, DatosFactura::SIN_DATOS_DOCUMENTO, null,
        DatosFactura::SIN_DATOS_RAZON_SOCIAL, null, codigoExcepcion: 1)));

    expect($factura->estado)->toBe(EstadoFactura::Bloqueada)
        ->and($factura->motivo)->toContain('Falta elegir el método de pago fiscal.');

    // «Corregir datos y reintentar» propone el método de la venta (efectivo).
    Livewire::actingAs($datos['admin'])->test(Factura::class, ['ventaId' => $factura->venta_id])
        ->call('abrirCorreccion', $factura->id)
        ->assertSet('factura.metodo_pago', '1');

    $corregida = app(EmitirFactura::class)->reintentar($datos['admin'], $factura);

    expect($corregida->metodo_pago)->toBe(1)
        ->and($corregida->estado)->toBe(EstadoFactura::Pendiente);
});

test('un producto sin código / SKU deja la factura bloqueada con el motivo, también al consultar al SIN', function () {
    Queue::fake();
    $datos = empresaFacturable();
    $datos['producto']->forceFill(['sku' => null])->save();

    $factura = facturaDe(ventaFacturable($datos));
    expect($factura->estado)->toBe(EstadoFactura::Bloqueada)
        ->and($factura->motivo)->toContain('Falta el código / SKU en: '.$datos['producto']->nombre);

    // Factura registrada antes de esta validación: pendiente con una línea sin código. Al emitir se bloquea, sin error de tipos.
    $datos['producto']->forceFill(['sku' => 'MED-01'])->save();
    $vieja = facturaDe(ventaFacturable($datos));
    $vieja->forceFill(['lineas' => array_map(fn (array $l): array => ['codigo_producto' => null] + $l, $vieja->lineas)])->save();

    expect(app(EmisionFacturas::class)->procesar($datos['empresa']->id, $vieja->id))->toBeFalse()
        ->and($vieja->refresh()->estado)->toBe(EstadoFactura::Bloqueada)
        ->and($vieja->motivo)->toContain('Falta el código / SKU en:');
});

test('en Pedidos y ventas, la venta con factura validada tiene «Imprimir factura» y en POS el menú marca Pedidos', function () {
    Queue::fake();
    $datos = empresaFacturable();
    $factura = facturaDe(ventaFacturable($datos));
    $factura->forceFill(['estado' => EstadoFactura::Validada, 'xml' => '<facturaComputarizadaCompraVenta/>'])->save();
    ventaFacturable($datos); // otra venta con la factura aún pendiente: sin enlace

    $html = Livewire::actingAs($datos['admin'])->test(PedidosLista::class)->html();
    expect(substr_count($html, 'data-test="imprimir-factura"'))->toBe(1)
        ->and($html)->toContain(route('facturas.imprimir', $factura));

    $this->actingAs($datos['admin'])->get(route('pos'))->assertOk()
        ->assertSee('class="nav-item active" href="'.route('pedidos.index').'"', false);
});

test('anular y revertir en el SIN; durante «Entrar como» no se permite', function () {
    $datos = empresaFacturable();
    $this->transporte->responder(respuestaSin(908));
    $venta = ventaFacturable($datos);
    $factura = facturaDe($venta);
    $acciones = app(AnularFactura::class);

    $this->transporte->responder(respuestaSin(905));
    $acciones->anular($datos['admin'], $factura, 1);
    $factura->refresh();
    expect($factura->estado)->toBe(EstadoFactura::Anulada)
        ->and($factura->venta_vigente)->toBeNull()
        ->and($factura->anulada_por)->toBe($datos['admin']->id)
        ->and($factura->motivo_anulacion)->toBe(1);

    $this->transporte->responder(respuestaSin(907));
    $acciones->revertir($datos['admin'], $factura);
    $factura->refresh();
    expect($factura->estado)->toBe(EstadoFactura::Validada)
        ->and($factura->venta_vigente)->toBe($venta->id)
        ->and($factura->revertida_en)->not->toBeNull()
        ->and(array_column($this->transporte->llamadas, 'operacion'))->toBe(['recepcionFactura', 'anulacionFactura', 'reversionAnulacionFactura']);

    // El motivo debe ser del catálogo sincronizado; la reversión no se repite.
    expect(fn () => $acciones->anular($datos['admin'], $factura, 99))->toThrow(ErrorFacturacion::class, 'motivo')
        ->and(fn () => $acciones->revertir($datos['admin'], $factura))->toThrow(ErrorFacturacion::class);

    request()->setLaravelSession(app('session.store'));
    session()->put(ImpersonacionController::SESION, User::factory()->superadmin()->create()->id);
    expect(fn () => $acciones->anular($datos['admin'], $factura, 1))->toThrow(AuthorizationException::class)
        ->and(count($this->transporte->llamadas))->toBe(3);
});

test('la factura validada se imprime y descarga solo desde su empresa; una no validada no', function () {
    $datos = empresaFacturable();
    $otra = SiatPruebas::empresaFiscal(7654321019);   // antes de autenticar: el modelo exige la empresa propia
    $this->transporte->responder(respuestaSin(908));
    $factura = facturaDe(ventaFacturable($datos));

    $this->actingAs($datos['admin'])->get(route('facturas.imprimir', $factura))
        ->assertOk()
        ->assertSee('Ciento trece 00/100 Bolivianos')
        ->assertSee('PARA-500')
        ->assertSee('https://pilotosiat.impuestos.gob.bo/consulta/QR?nit=1023456029&amp;cuf='.$factura->cuf.'&amp;numero=1&amp;t=2', false)
        ->assertSee('<svg', false);
    $this->get(route('facturas.imprimir', ['factura' => $factura, 'formato' => 'rollo']))->assertOk()->assertSee('t=1', false);
    $this->get(route('facturas.xml', $factura))->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="factura-1.xml"');

    $this->actingAs($otra['admin'])->get(route('facturas.imprimir', $factura))->assertNotFound();
    $this->get(route('facturas.xml', $factura))->assertNotFound();

    $factura->forceFill(['estado' => EstadoFactura::Bloqueada])->save();
    $this->actingAs($datos['admin'])->get(route('facturas.imprimir', $factura))->assertNotFound();
});

test('una venta con factura vigente no admite devoluciones parciales (decisión D4)', function () {
    Queue::fake();
    $datos = empresaFacturable();
    $venta = ventaFacturable($datos, '2');
    $item = DB::table('venta_items')->where('venta_id', $venta->id)->value('id');

    expect(fn () => app(RegistrarDevolucion::class)->ejecutar($datos['admin'], $venta, [
        'lineas' => [$item => ['cantidad' => '1', 'reingresa' => true]], 'motivo' => 'Defectuoso', 'metodo' => 'efectivo',
        'efectivo' => null, 'electronico' => null, 'clave' => (string) Str::uuid(),
    ]))->toThrow(ValidationException::class, 'factura');
});

test('la confirmación propone los datos fiscales y la factura sale con lo que corrige el vendedor', function () {
    Queue::fake();
    $datos = empresaFacturable();
    $this->actingAs($datos['admin']);
    cargarStock($datos['admin'], $datos['sucursal'], $datos['producto'], '10');

    Livewire::test(Editor::class)
        ->call('agregarProducto', $datos['producto']->id)
        ->call('elegirMetodo', 'efectivo')
        ->call('revisar')
        ->assertSet('confirmando', true)
        ->assertSee('Factura SIAT')
        ->assertSet('factura.numero_documento', '99002')
        ->assertSet('factura.metodo_pago', '1')
        ->set('factura.tipo_documento', '1')
        ->set('factura.numero_documento', 'ABC')
        ->call('confirmar')
        ->assertHasErrors('factura.numero_documento')
        ->set('factura.numero_documento', '4455667')
        ->set('factura.razon_social', 'Carlos Mendoza')
        ->call('confirmar')
        ->assertHasNoErrors();

    $factura = facturaDe(Venta::query()->sole());
    expect($factura->tipo_documento)->toBe(DatosFactura::TIPO_CI)
        ->and($factura->numero_documento)->toBe('4455667')
        ->and($factura->codigo_excepcion)->toBe(0)
        ->and($factura->estado)->toBe(EstadoFactura::Pendiente);
});

test('venta a crédito con cuota inicial sugiere método fiscal compuesto y confirma sin error de validación', function () {
    Queue::fake();
    $datos = empresaFacturable();
    $this->actingAs($datos['admin']);
    cargarStock($datos['admin'], $datos['sucursal'], $datos['producto'], '10');

    $cliente = Cliente::create([
        'empresa_id' => $datos['empresa']->id,
        'nombre' => 'Ana Morales',
        'documento' => '5566778',
        'tipo_documento' => 'ci',
    ]);

    // Método sugerido: 34 (EFECTIVO – PAGO POSTERIOR)
    $metodoSugerido = DatosFactura::metodoSugerido(
        $datos['empresa']->id,
        MetodoPago::Efectivo,
        conSaldo: true,
        electronicoEmpresa: null,
    );
    expect($metodoSugerido)->toBe(34);

    Livewire::test(Editor::class)
        ->call('agregarProducto', $datos['producto']->id)
        ->call('seleccionarCliente', $cliente->id)
        ->set('pago_en_cuotas', true)
        ->set('pago_cuota_inicial', '13.00')
        ->set('pago_vencimiento', now()->addDays(30)->toDateString())
        ->call('elegirMetodo', 'efectivo')
        ->call('revisar')
        ->assertSet('confirmando', true)
        ->assertSet('factura.metodo_pago', '34')
        ->call('confirmar')
        ->assertHasNoErrors();

    $factura = facturaDe(Venta::query()->sole());
    expect($factura->metodo_pago)->toBe(34)
        ->and($factura->estado)->toBe(EstadoFactura::Pendiente);
});

test('metodoSugerido propone códigos correctos para crédito y contado', function () {
    $datos = empresaFacturable();
    $id = $datos['empresa']->id;

    // Con saldo (a crédito)
    expect(DatosFactura::metodoSugerido($id, null, conSaldo: true))->toBe(33)
        ->and(DatosFactura::metodoSugerido($id, MetodoPago::Efectivo, conSaldo: true))->toBe(34)
        ->and(DatosFactura::metodoSugerido($id, MetodoPago::Electronico, conSaldo: true))->toBe(57);

    // Sin saldo (al contado)
    expect(DatosFactura::metodoSugerido($id, MetodoPago::Efectivo, conSaldo: false))->toBe(1)
        ->and(DatosFactura::metodoSugerido($id, MetodoPago::Electronico, conSaldo: false))->toBe(7)
        ->and(DatosFactura::metodoSugerido($id, MetodoPago::Mixto, conSaldo: false))->toBe(13);
});

test('en modo manual la confirmación no factura si no se marca y avisa cuando el total no es facturable', function () {
    Queue::fake();
    $datos = empresaFacturable(modo: ModoFacturacion::Manual);
    $precio = '10.00';

    while (CalculadoraFiscal::totalesVecinos($precio) === null) {
        $precio = bcadd($precio, '0.01', 2);
    }

    $datos['producto']->forceFill(['precio_consumidor' => $precio])->save();
    $this->actingAs($datos['admin']);
    cargarStock($datos['admin'], $datos['sucursal'], $datos['producto'], '10');

    Livewire::test(Editor::class)
        ->call('agregarProducto', $datos['producto']->id)
        ->call('elegirMetodo', 'efectivo')
        ->call('revisar')
        ->assertSee('Emitir factura SIAT')
        ->assertSet('emitirFactura', false)
        ->assertDontSee('Número de documento')
        ->set('emitirFactura', true)
        ->assertSee('Número de documento')
        ->assertSee('Este total no se puede facturar exacto')
        ->set('emitirFactura', false)
        ->call('confirmar');

    expect(Venta::query()->count())->toBe(1)
        ->and(SiatFactura::query()->exists())->toBeFalse();
});

test('el panel lista solo las facturas de la empresa y muestra lo que falta para facturar', function () {
    Queue::fake();
    $a = empresaFacturable();
    $b = empresaFacturable(7654321019);
    ventaFacturable($b, factura: new DatosFactura(DatosFactura::TIPO_NIT, '5544332211', null, 'CLIENTE DE B', 1));
    ventaFacturable($a, factura: new DatosFactura(DatosFactura::TIPO_NIT, '1122334455', null, 'CLIENTE DE A', 1));

    Livewire::actingAs($a['admin'])->test(Facturas::class, ['empresaId' => $a['empresa']->id])
        ->assertSee('CLIENTE DE A')
        ->assertDontSee('CLIENTE DE B')
        ->assertSee('Preparación para facturar')
        ->set('estado', 'validada')
        ->assertDontSee('CLIENTE DE A');

    // El ID de otra empresa no sirve aunque se envíe a mano.
    Livewire::actingAs($a['admin'])->test(Facturas::class, ['empresaId' => $b['empresa']->id])->assertForbidden();
});

test('los datos fiscales aceptan solo actividad, producto y unidad de los catálogos, y el producto de esa actividad', function () {
    $datos = empresaFacturable();
    $this->actingAs($datos['admin']);
    $base = ['nit' => 1023456029, 'razon_social' => 'FARMACIA SUR', 'modo' => 'automatica', 'metodo_pago_electronico' => 7];
    $guardar = fn (array $extra) => app(GuardarDatosFiscales::class)->ejecutar($datos['admin'], $datos['empresa'], $base + $extra);

    expect(fn () => $guardar(['actividad_economica' => ACTIVIDAD_PRUEBA, 'producto_sin' => 83141, 'unidad_medida' => 57]))
        ->toThrow(ValidationException::class, 'no corresponde a la actividad')
        ->and(fn () => $guardar(['actividad_economica' => '999999', 'producto_sin' => null, 'unidad_medida' => 57]))
        ->toThrow(ValidationException::class, 'actividad del catálogo')
        ->and(fn () => $guardar(['actividad_economica' => ACTIVIDAD_PRUEBA, 'producto_sin' => 62271, 'unidad_medida' => 1]))
        ->toThrow(ValidationException::class, 'unidad de medida');

    $configuracion = $guardar(['actividad_economica' => ACTIVIDAD_PRUEBA, 'producto_sin' => '62271', 'unidad_medida' => '58']);
    expect($configuracion->producto_sin)->toBe(62271)
        ->and($configuracion->unidad_medida)->toBe(58);
});

test('la pantalla de datos fiscales ofrece solo los productos SIN de la actividad elegida', function () {
    $datos = empresaFacturable();

    Livewire::actingAs($datos['admin'])->test(Asistente::class, ['empresaId' => $datos['empresa']->id])
        ->assertSet('actividad_economica', ACTIVIDAD_PRUEBA)
        ->assertSee('62271 · MEDICAMENTOS')
        ->assertDontSee('83141 · SERVICIOS DE SOFTWARE')
        ->set('actividad_economica', '')
        ->assertSet('producto_sin', '')
        ->assertSee('Elige primero la actividad');
});

test('venta con metodo electronico envia a siat codigoMetodoPago 7 Transferencia bancaria', function () {
    $datos = empresaFacturable();
    $this->transporte->responder(respuestaSin(908, descripcion: 'VALIDADA'));

    $this->actingAs($datos['admin']);
    cargarStock($datos['admin'], $datos['sucursal'], $datos['producto'], '10');
    $pedido = crearPedido($datos['admin'], $datos['sucursal'], [['producto_id' => $datos['producto']->id, 'cantidad' => '1']]);

    $venta = app(ConfirmarVenta::class)->ejecutar($datos['admin'], $pedido, [
        'metodo' => 'electronico',
        'en_cuotas' => false,
        'cuota_inicial' => null,
        'efectivo' => null,
        'electronico' => null,
        'clave' => (string) Str::uuid(),
    ]);

    $factura = facturaDe($venta);
    expect($factura->metodo_pago)->toBe(7)
        ->and($factura->xml)->toContain('<codigoMetodoPago>7</codigoMetodoPago>');
});

test('venta con metodo mixto envia a siat codigoMetodoPago 13 Efectivo + transferencia', function () {
    $datos = empresaFacturable();
    $this->transporte->responder(respuestaSin(908, descripcion: 'VALIDADA'));

    $this->actingAs($datos['admin']);
    cargarStock($datos['admin'], $datos['sucursal'], $datos['producto'], '10');
    $pedido = crearPedido($datos['admin'], $datos['sucursal'], [['producto_id' => $datos['producto']->id, 'cantidad' => '1']]);

    // Total del producto es 113.00: 50 en efectivo y 63 en electrónico
    $venta = app(ConfirmarVenta::class)->ejecutar($datos['admin'], $pedido, [
        'metodo' => 'mixto',
        'en_cuotas' => false,
        'cuota_inicial' => null,
        'efectivo' => '50.00',
        'electronico' => '63.00',
        'externo' => true,
        'clave' => (string) Str::uuid(),
    ]);

    $factura = facturaDe($venta);
    expect($factura->metodo_pago)->toBe(13)
        ->and($factura->xml)->toContain('<codigoMetodoPago>13</codigoMetodoPago>');
});

test('en el POS al cambiar metodo de pago se actualiza metodo_pago fiscal con 1, 7 y 13', function () {
    $datos = empresaFacturable();
    $this->actingAs($datos['admin']);
    cargarStock($datos['admin'], $datos['sucursal'], $datos['producto'], '10');

    Livewire::actingAs($datos['admin'])->test(Pos::class)
        ->call('agregarProducto', $datos['producto']->id)
        ->assertSet('pago_metodo', 'efectivo')
        ->assertSet('factura.metodo_pago', '1')
        ->call('elegirMetodo', 'electronico')
        ->assertSet('pago_metodo', 'electronico')
        ->assertSet('factura.metodo_pago', '7')
        ->call('elegirMetodo', 'mixto')
        ->assertSet('pago_metodo', 'mixto')
        ->assertSet('factura.metodo_pago', '13')
        ->call('elegirMetodo', 'efectivo')
        ->assertSet('pago_metodo', 'efectivo')
        ->assertSet('factura.metodo_pago', '1');
});

test('en pedidos al revisar con metodo electronico o mixto propone codigos 7 y 13 para SIAT', function () {
    $datos = empresaFacturable();
    $this->actingAs($datos['admin']);
    cargarStock($datos['admin'], $datos['sucursal'], $datos['producto'], '10');

    Livewire::actingAs($datos['admin'])->test(Editor::class)
        ->call('agregarProducto', $datos['producto']->id)
        ->call('elegirMetodo', 'electronico')
        ->call('revisar')
        ->assertSet('factura.metodo_pago', '7')
        ->set('confirmando', false)
        ->call('elegirMetodo', 'mixto')
        ->set('pago_efectivo', '50.00')
        ->set('pago_electronico', '63.00')
        ->call('revisar')
        ->assertSet('factura.metodo_pago', '13');
});

test('siat factura desglosa las posibles causas en lista y elimina la mencion a facturacion datos fiscales', function () {
    $factura = new SiatFactura;
    $factura->motivo = 'La sucursal de la venta no está registrada en el SIN o no tiene un punto de venta activo. Faltan el NIT o la razón social en Facturación → Datos fiscales. Faltan la actividad económica, el producto SIN o la unidad de medida en Facturación → Datos fiscales.';

    expect($factura->listaMotivos())->toBe([
        'La sucursal de la venta no está registrada en el SIN o no tiene un punto de venta activo.',
        'Faltan el NIT o la razón social.',
        'Faltan la actividad económica, el producto SIN o la unidad de medida.',
    ]);

    $factura->motivo = "La sucursal de la venta no está registrada en el SIN o no tiene un punto de venta activo.\nFaltan el NIT o la razón social.\nFaltan la actividad económica, el producto SIN o la unidad de medida.";
    expect($factura->listaMotivos())->toBe([
        'La sucursal de la venta no está registrada en el SIN o no tiene un punto de venta activo.',
        'Faltan el NIT o la razón social.',
        'Faltan la actividad económica, el producto SIN o la unidad de medida.',
    ]);
});

test('componente de factura muestra la lista de causas de bloqueo como elementos li', function () {
    Queue::fake();
    $datos = empresaFacturable();
    $this->actingAs($datos['admin']);
    $venta = ventaFacturable($datos);

    $factura = facturaDe($venta);
    $factura->forceFill([
        'estado' => EstadoFactura::Bloqueada,
        'motivo' => "La sucursal de la venta no está registrada en el SIN o no tiene un punto de venta activo.\nFaltan el NIT o la razón social.\nFaltan la actividad económica, el producto SIN o la unidad de medida.",
    ])->save();

    Livewire::actingAs($datos['admin'])->test(Factura::class, ['ventaId' => $venta->id])
        ->assertSeeHtml('<ul')
        ->assertSeeHtml('<li>La sucursal de la venta no está registrada en el SIN o no tiene un punto de venta activo.</li>')
        ->assertSeeHtml('<li>Faltan el NIT o la razón social.</li>')
        ->assertSeeHtml('<li>Faltan la actividad económica, el producto SIN o la unidad de medida.</li>')
        ->assertDontSee('Facturación → Datos fiscales');
});

test('la factura toma la unidad de medida sin configurada en la unidad del producto', function () {
    Queue::fake();
    auth()->logout();
    $datos = empresaFacturable();
    $this->actingAs($datos['admin']);
    $datos['configuracion']->forceFill(['unidad_medida' => null])->save();
    $datos['producto']->unidad->forceFill(['unidad_medida_sin' => 57])->save();

    $venta = ventaFacturable($datos);
    $factura = facturaDe($venta);

    expect($factura->lineas[0]['unidad_medida'])->toBe(57);
});

test('si el producto no tiene unidad de medida sin la factura queda bloqueada avisando el motivo', function () {
    Queue::fake();
    auth()->logout();
    $datos = empresaFacturable();
    $this->actingAs($datos['admin']);
    $datos['configuracion']->forceFill(['unidad_medida' => null])->save();
    $datos['producto']->unidad->forceFill(['unidad_medida_sin' => null])->save();

    $venta = ventaFacturable($datos);
    $factura = facturaDe($venta);

    expect($factura->estado)->toBe(EstadoFactura::Bloqueada)
        ->and($factura->motivo)->toContain('Falta la unidad de medida SIN en:');
});
