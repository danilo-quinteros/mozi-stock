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

$estadoRuta = storage_path('app/private/siat-certificacion/estado.json');
$estado = json_decode((string) file_get_contents($estadoRuta), true);

$casos = [
    [
        'pv' => 1,
        'cantidad' => 500,
        'motivo' => 2,
        'desc' => 'CORTE DEL SERVICIO DE INTERNET',
    ],
    [
        'pv' => 0,
        'cantidad' => 5,
        'motivo' => 2,
        'desc' => 'CORTE DEL SERVICIO DE INTERNET',
    ],
    [
        'pv' => 1,
        'cantidad' => 500,
        'motivo' => 1,
        'desc' => 'CORTE DE SUMINISTRO DE ENERGIA ELECTRICA',
    ],
    [
        'pv' => 0,
        'cantidad' => 5,
        'motivo' => 1,
        'desc' => 'CORTE DE SUMINISTRO DE ENERGIA ELECTRICA',
    ],
    [
        'pv' => 1,
        'cantidad' => 500,
        'motivo' => 3,
        'desc' => 'VIRUS INFORMÁTICO O FALLA DE SOFTWARE',
    ],
    [
        'pv' => 0,
        'cantidad' => 5,
        'motivo' => 3,
        'desc' => 'VIRUS INFORMÁTICO O FALLA DE SOFTWARE',
    ],
];

// Opcional: filtrar por índice de caso (0 a 5) vía parámetro --caso=X o desde un índice con --desde=X
$options = getopt('', ['caso:', 'desde:']);
$casoIdx = isset($options['caso']) ? (int) $options['caso'] : null;
$desdeIdx = isset($options['desde']) ? (int) $options['desde'] : null;

if ($casoIdx !== null && isset($casos[$casoIdx])) {
    $casosAEjecutar = [$casoIdx => $casos[$casoIdx]];
} elseif ($desdeIdx !== null) {
    $casosAEjecutar = array_slice($casos, $desdeIdx, null, true);
} else {
    $casosAEjecutar = $casos;
}

foreach ($casosAEjecutar as $i => $item) {
    $pv = $item['pv'];
    $cantidad = $item['cantidad'];
    $motivo = $item['motivo'];
    $desc = $item['desc'];

    echo "=================================================================\n";
    echo ' EJECUTANDO CASO ('.($i + 1)."): '{$desc}' | PV {$pv} | {$cantidad} facturas\n";
    echo "=================================================================\n";

    $punto = new PuntoEmision(0, $pv);
    $cuis = $estado['cuis'][(string) $pv] ?? $cliente->codigos($contribuyente)->cuis($punto)->codigo;

    // 1. Obtener CUFD del evento
    echo "1. Obteniendo CUFD de contingencia y abriendo evento...\n";
    $r1 = $cliente->codigos($contribuyente)->cufd($punto, $cuis);
    $cufdEventoObj = $r1->cufd;
    if ($cufdEventoObj === null) {
        echo "[ERROR] No se pudo obtener CUFD inicial\n";
        exit(1);
    }
    $cufdEvento = $cufdEventoObj->codigo;
    $inicio = new DateTimeImmutable('now', new DateTimeZone('America/La_Paz'));

    echo "   Esperando lapso de contingencia (5s)...\n";
    sleep(5);
    $fin = new DateTimeImmutable('now', new DateTimeZone('America/La_Paz'));

    // 2. Obtener CUFD nuevo
    $r2 = $cliente->codigos($contribuyente)->cufd($punto, $cuis);
    $cufdNuevoObj = $r2->cufd;
    if ($cufdNuevoObj === null) {
        echo "[ERROR] No se pudo obtener CUFD nuevo\n";
        exit(1);
    }
    $cufdNuevo = $cufdNuevoObj->codigo;

    // 3. Registrar evento con la descripción literal exacta
    echo "2. Registrando evento en SIAT: Motivo {$motivo} - '{$desc}'...\n";
    $respEv = $cliente->operaciones($contribuyente)->registroEventoSignificativo(
        sucursal: 0,
        puntoVenta: $pv,
        cuis: $cuis,
        cufd: $cufdNuevo,
        cufdEvento: $cufdEvento,
        codigoMotivoEvento: $motivo,
        descripcion: $desc,
        fechaHoraInicio: $inicio,
        fechaHoraFin: $fin,
    );

    if (! $respEv->transaccion || $respEv->codigoRecepcionEventoSignificativo === null) {
        echo '   [ERROR] Falló registro de evento: '.json_encode($respEv->mensajes)."\n";
        exit(1);
    }

    $codRecEvento = (int) $respEv->codigoRecepcionEventoSignificativo;
    echo "   [OK] Evento registrado exitosamente. Código de Recepción: {$codRecEvento}\n";

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

    // 4. Construir facturas
    echo "3. Generando {$cantidad} facturas amparadas en contingencia...\n";
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

    // 5. Transmitir recepcionPaqueteFactura
    $contexto = new ContextoDocumento($punto, $cuis, $cufdNuevo, TipoEmision::FueraDeLinea);
    echo "4. Transmitiendo recepcionPaqueteFactura al SIAT...\n";
    $startSend = microtime(true);
    $recepcion = $cliente->compraVenta($contribuyente)->recepcionPaquete(
        contexto: $contexto,
        archivoTarGz: $tarGz,
        cantidadFacturas: $cantidad,
        codigoEvento: $codRecEvento,
    );
    $durSend = round(microtime(true) - $startSend, 2);

    echo "   Respuesta SIAT: Estado {$recepcion->codigoEstado} · Transacción: ".($recepcion->transaccion ? 'true' : 'false')." ({$durSend}s)\n";
    $codRec = $recepcion->codigoRecepcion ?? '';
    echo "   Código Recepción Paquete: {$codRec}\n";
    foreach ($recepcion->mensajes as $m) {
        echo "   Mensaje: [{$m->codigo}] {$m->descripcion}\n";
    }

    if (! $recepcion->transaccion || $codRec === '') {
        echo "[ERROR] Recepción rechazada por el SIN.\n";
        exit(1);
    }

    // 6. Validar paquete
    echo "5. Esperando 5 segundos para indexación del paquete en SIN...\n";
    sleep(5);

    echo "6. Consultando validacionRecepcionPaqueteFactura...\n";
    $okVal = false;
    $val = null;
    for ($intento = 1; $intento <= 8; $intento++) {
        $val = $cliente->compraVenta($contribuyente)->validacionRecepcionPaquete($contexto, $codRec);
        echo "   Intento {$intento}/8: Estado {$val->codigoEstado} · Transacción: ".($val->transaccion ? 'true' : 'false')."\n";
        foreach ($val->mensajes as $m) {
            echo "      Msg: [{$m->codigo}] {$m->descripcion}\n";
        }
        $okVal = $val->validada() || $val->codigoEstado === 908;
        if ($okVal) {
            break;
        }
        echo "   Aún en proceso, esperando 5 segundos...\n";
        sleep(5);
    }

    if ($okVal) {
        echo "=================================================================\n";
        echo " [EXITO] ¡PAQUETE VALIDADO CON ESTADO 908 (RECEPCIÓN VALIDADA)!\n";
        echo "=================================================================\n\n";
    } else {
        echo "[FALLA] No se pudo validar con 908.\n\n";
    }

    // Registrar evidencias
    $fechaHora = now('America/La_Paz')->format('Y-m-d H:i:s');
    $fp = fopen(base_path('../docs/evidencias/siat-certificacion/VI-paquetes.csv'), 'a');
    if ($fp) {
        fputcsv($fp, [
            $fechaHora, 'VI', "Paquete PV {$pv} Cant {$cantidad} Ev {$codRecEvento} {$desc}", 0, $pv, 'recepcionPaqueteFactura', '', '',
            (string) $recepcion->codigoEstado, $recepcion->transaccion ? 'true' : 'false', $codRec, '', 'ok', '-',
        ]);
        fputcsv($fp, [
            $fechaHora, 'VI', "Paquete PV {$pv} Cant {$cantidad} Ev {$codRecEvento} {$desc} val", 0, $pv, 'validacionRecepcionPaqueteFactura', '', '',
            $val !== null ? (string) $val->codigoEstado : 'ERROR', $val !== null && $val->transaccion ? 'true' : 'false', $codRec, '', $okVal ? 'ok' : 'falla', '-',
        ]);
        fclose($fp);
    }
}
