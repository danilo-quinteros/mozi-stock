<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use MoziStudios\Siat\Algoritmos\Archivo;
use MoziStudios\Siat\ClienteSiat;
use MoziStudios\Siat\Codigos\Cufd;
use MoziStudios\Siat\Configuracion\Contribuyente;
use MoziStudios\Siat\Configuracion\PuntoEmision;
use MoziStudios\Siat\Configuracion\TokenDelegado;
use MoziStudios\Siat\Constantes\TipoEmision;
use MoziStudios\Siat\Factura\DetalleCompraVenta;
use MoziStudios\Siat\Factura\FacturaCompraVenta;
use MoziStudios\Siat\Fecha\FechaSiat;
use MoziStudios\Siat\Servicios\ContextoDocumento;

$cliente = app(ClienteSiat::class);
$contribuyente = new Contribuyente(
    (int) config('siat.pruebas.nit'),
    new TokenDelegado((string) config('siat.pruebas.token'))
);

// Códigos del catálogo sincronizado (sincronizarParametricaEventosSignificativos), no los del Excel:
// el Excel de paquetes usa una numeración vieja y el portal cuenta el caso por el código del catálogo.
$catalogoEventos = [
    1 => 'CORTE DEL SERVICIO DE INTERNET',
    2 => 'INACCESIBILIDAD AL SERVICIO WEB DE LA ADMINISTRACIÓN TRIBUTARIA',
    3 => 'INGRESO A ZONAS SIN INTERNET POR DESPLIEGUE DE PUNTO DE VENTA',
    4 => 'VENTA EN LUGARES SIN INTERNET',
    5 => 'VIRUS INFORMÁTICO O FALLA DE SOFTWARE',
    6 => 'CAMBIO DE INFRAESTRUCTURA DE SISTEMA O FALLA DE HARDWARE',
    7 => 'CORTE DE SUMINISTRO DE ENERGIA ELÉCTRICA',
];

$options = getopt('', ['ejecutar', 'pv:', 'motivo:', 'cantidad:']);
$pv = (int) ($options['pv'] ?? 1);
$motivo = (int) ($options['motivo'] ?? 5);
$cantidad = (int) ($options['cantidad'] ?? ($pv === 1 ? 500 : 10));
if (! isset($catalogoEventos[$motivo]) || ! in_array($pv, [0, 1], true) || $cantidad < 1 || $cantidad > 500) {
    echo "Uso: php ejecutar_caso_individual_paquete.php --pv=0|1 --motivo=1..7 [--cantidad=1..500] [--ejecutar]\n";
    exit(1);
}
$descripcion = $catalogoEventos[$motivo];

$punto = new PuntoEmision(0, $pv);
$respCuis = $cliente->codigos($contribuyente)->cuis($punto);
$cuis = $respCuis->codigo ?? 'D4532626';

echo "=================================================================\n";
echo " CASO SELECCIONADO: ETAPA VI - CONSUMO EMISIÓN DE PAQUETES\n";
echo "=================================================================\n";
echo "1. descripcion:           {$descripcion}\n";
echo '2. codigoAmbiente:        '.config('siat.ambiente')."\n";
echo "3. codigoEmision:         2 (Fuera de línea)\n";
echo "4. archivo:               [Cadena binaria tar.gz en base64 con 500 facturas XML]\n";
echo '5. codigoSistema:         '.config('siat.codigo_sistema')."\n";
echo "6. hashArchivo:           [SHA-256 del archivo tar.gz]\n";
echo "7. codigoSucursal:        0\n";
echo "8. cantidadFacturas:      {$cantidad}\n";
echo "9. codigoModalidad:       2 (Computarizada en línea)\n";
echo "10. codigoRecepcion:      no aplica (se recibe como respuesta de SIAT)\n";
echo "11. cuis:                 {$cuis}\n";
echo "12. codigoPuntoVenta:     {$pv}\n";
echo "13. fechaEnvio:           [Fecha actual UTC extendido sin zona horaria]\n";
echo "14. tipoFacturaDocumento: 1 (Con derecho a crédito fiscal)\n";
echo '15. nit:                  '.config('siat.pruebas.nit')."\n";
echo "16. codigoEvento:         [Código de recepción del evento registrado]\n";
echo "17. codigoDocumentoSector:1 (Compra-Venta)\n";
echo "18. cufd:                 [CUFD vigente al momento de la transmisión]\n";
echo "=================================================================\n";

if (! isset($options['ejecutar'])) {
    echo "\nPara transmitir este caso al SIAT ejecuta:\n";
    echo "  php ejecutar_caso_individual_paquete.php --pv={$pv} --motivo={$motivo} --cantidad={$cantidad} --ejecutar\n";
    exit(0);
}

$estadoRuta = storage_path('app/private/siat-certificacion/estado.json');
$estado = json_decode((string) file_get_contents($estadoRuta), true);

echo "\n-> Paso 1: Obteniendo CUFD de contingencia para las facturas...\n";
$r1 = $cliente->codigos($contribuyente)->cufd($punto, $cuis);
$cufdEventoObj = $r1->cufd;
if ($cufdEventoObj === null) {
    echo "[ERROR] No se pudo obtener CUFD inicial de contingencia\n";
    exit(1);
}
$cufdEvento = $cufdEventoObj->codigo;
$inicio = new DateTimeImmutable('now', new DateTimeZone('America/La_Paz'));

echo "-> Paso 2: Lapso de contingencia (5 segundos)...\n";
sleep(5);
$fin = new DateTimeImmutable('now', new DateTimeZone('America/La_Paz'));

echo "-> Paso 3: Obteniendo CUFD nuevo para la transmisión...\n";
$r2 = $cliente->codigos($contribuyente)->cufd($punto, $cuis);
$cufdNuevoObj = $r2->cufd;
if ($cufdNuevoObj === null) {
    echo "[ERROR] No se pudo obtener CUFD nuevo\n";
    exit(1);
}
$cufdNuevo = $cufdNuevoObj->codigo;

echo "-> Paso 4: Registrando evento significativo (Motivo {$motivo}: {$descripcion})...\n";
$respEv = $cliente->operaciones($contribuyente)->registroEventoSignificativo(
    sucursal: 0,
    puntoVenta: $pv,
    cuis: $cuis,
    cufd: $cufdNuevo,
    cufdEvento: $cufdEvento,
    codigoMotivoEvento: $motivo,
    descripcion: $descripcion,
    fechaHoraInicio: $inicio,
    fechaHoraFin: $fin,
);

if (! $respEv->transaccion || $respEv->codigoRecepcionEventoSignificativo === null) {
    echo '   [ERROR] Falló registro de evento: '.json_encode($respEv->mensajes)."\n";
    exit(1);
}

$codRecEvento = (int) $respEv->codigoRecepcionEventoSignificativo;
echo "   [OK] Evento registrado con Código de Recepción: {$codRecEvento}\n";

$estado['eventos'][] = [
    'pv' => $pv,
    'codigoEvento' => $motivo,
    'codigoRecepcion' => $codRecEvento,
    'fechaInicio' => $inicio->format('Y-m-d H:i:s'),
    'fechaFin' => $fin->format('Y-m-d H:i:s'),
    'cufdEvento' => $cufdEvento,
    'codigoControl' => $cufdEventoObj->codigoControl,
    'codigoInterno' => $cufdEventoObj->codigoInterno,
];

echo "-> Paso 5: Generando {$cantidad} facturas amparadas en el CUFD del evento...\n";
$startBuild = microtime(true);
$cufdFacturas = new Cufd(
    codigo: $cufdEvento,
    codigoControl: $cufdEventoObj->codigoControl,
    codigoInterno: $cufdEventoObj->codigoInterno,
    direccion: $cufdEventoObj->direccion ?? 'Av. Principal #100',
);

$xmlMap = [];
for ($f = 1; $f <= $cantidad; $f++) {
    $estado['ultimo_numero'][(string) $pv] = ($estado['ultimo_numero'][(string) $pv] ?? 730000) + 1;
    $num = $estado['ultimo_numero'][(string) $pv];

    $factura = new FacturaCompraVenta(
        nitEmisor: (int) config('siat.pruebas.nit'),
        razonSocialEmisor: 'MOZISTOCK PRUEBAS',
        municipio: 'La Paz',
        numeroFactura: $num,
        punto: $punto,
        direccion: $cufdFacturas->direccion ?? 'Av. Principal #100',
        fechaEmision: FechaSiat::desde($inicio),
        cufd: $cufdFacturas,
        codigoTipoDocumentoIdentidad: 5,
        numeroDocumento: '99002',
        codigoCliente: '99002',
        codigoMetodoPago: 1,
        subTotalNeto: '100.00',
        totalPrecioNeto: '100.00',
        iva: '13.00',
        montoaPagar: '113.00',
        codigoMoneda: 1,
        tipoCambio: '1',
        montoTotalMoneda: '113.00',
        leyenda: 'Ley N° 453: Puedes acceder a la reclamación cuando tus derechos han sido vulnerados.',
        usuario: 'mozistock-cert',
        detalles: [
            new DetalleCompraVenta(
                actividadEconomica: '6201000',
                codigoProductoSin: 1003913,
                codigoProducto: 'ITEM-1',
                descripcion: 'Servicio de prueba de certificacion',
                cantidad: '1.00',
                unidadMedida: 58,
                precioNetoUnitario: '100.00',
                subTotal: '100.00',
            ),
        ],
        nombreRazonSocial: 'CONTROL TRIBUTARIO',
        codigoExcepcion: 1,
        tipoEmision: TipoEmision::FueraDeLinea,
    );

    $prep = $cliente->preparador()->preparar($factura);
    $xmlMap["factura_{$pv}_{$num}.xml"] = $prep->xml;
}
file_put_contents($estadoRuta, json_encode($estado, JSON_PRETTY_PRINT));

$tarGz = Archivo::tarGz($xmlMap);
$hash = Archivo::sha256($tarGz);
$durBuild = round(microtime(true) - $startBuild, 2);
echo "   [OK] {$cantidad} facturas empaquetadas en {$durBuild}s (".round(strlen($tarGz) / 1024, 1)." KB, hash: {$hash})\n";

echo "-> Paso 6: Transmitiendo recepcionPaqueteFactura al SIAT...\n";
$contexto = new ContextoDocumento($punto, $cuis, $cufdNuevo, TipoEmision::FueraDeLinea);
$startSend = microtime(true);
$recepcion = $cliente->compraVenta($contribuyente)->recepcionPaquete(
    contexto: $contexto,
    archivoTarGz: $tarGz,
    cantidadFacturas: $cantidad,
    codigoEvento: $codRecEvento,
);
$durSend = round(microtime(true) - $startSend, 2);

echo "   Respuesta SIAT (recepcionPaqueteFactura):\n";
echo "   - codigoEstado:     {$recepcion->codigoEstado}\n";
echo '   - transaccion:      '.($recepcion->transaccion ? 'true' : 'false')."\n";
echo '   - codigoRecepcion:  '.($recepcion->codigoRecepcion ?? 'NULL')."\n";
echo '   - mensajes:         '.json_encode($recepcion->mensajes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
$codRec = $recepcion->codigoRecepcion ?? '';

if (! $recepcion->transaccion || $codRec === '') {
    echo "[ERROR] Recepción rechazada por el SIN.\n";
    exit(1);
}

echo "-> Paso 7: Esperando 5 segundos para indexación en SIN...\n";
sleep(5);

echo "-> Paso 8: Validando paquete con validacionRecepcionPaqueteFactura...\n";
$okVal = false;
$val = null;
for ($intento = 1; $intento <= 8; $intento++) {
    $val = $cliente->compraVenta($contribuyente)->validacionRecepcionPaquete($contexto, $codRec);
    echo "   Intento {$intento}/8 Respuesta SIAT (validacionRecepcionPaqueteFactura):\n";
    echo "   - codigoEstado:     {$val->codigoEstado}\n";
    echo '   - transaccion:      '.($val->transaccion ? 'true' : 'false')."\n";
    echo '   - codigoRecepcion:  '.($val->codigoRecepcion ?? $codRec)."\n";
    echo '   - mensajes:         '.json_encode($val->mensajes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
    $okVal = $val->validada() || $val->codigoEstado === 908;
    if ($okVal) {
        break;
    }
    echo "   En proceso, esperando 5 segundos...\n";
    sleep(5);
}

if ($okVal) {
    echo "\n=================================================================\n";
    echo " [EXITO] ¡PAQUETE VALIDADO CON ESTADO 908 (RECEPCIÓN VALIDADA)!\n";
    echo "=================================================================\n";
} else {
    echo "\n[FALLA] No se pudo validar con 908.\n";
}

$fechaHora = now('America/La_Paz')->format('Y-m-d H:i:s');
$fp = fopen(base_path('../docs/evidencias/siat-certificacion/VI-paquetes.csv'), 'a');
if ($fp) {
    fputcsv($fp, [
        $fechaHora, 'VI', "Paquete PV {$pv} Cant {$cantidad} Ev {$codRecEvento} {$descripcion}", 0, $pv, 'recepcionPaqueteFactura', '', '',
        (string) $recepcion->codigoEstado, $recepcion->transaccion ? 'true' : 'false', $codRec, '', 'ok', '-',
    ]);
    fputcsv($fp, [
        $fechaHora, 'VI', "Paquete PV {$pv} Cant {$cantidad} Ev {$codRecEvento} {$descripcion} val", 0, $pv, 'validacionRecepcionPaqueteFactura', '', '',
        $val !== null ? (string) $val->codigoEstado : 'ERROR', $val !== null && $val->transaccion ? 'true' : 'false', $codRec, '', $okVal ? 'ok' : 'falla', '-',
    ]);
    fclose($fp);
}
