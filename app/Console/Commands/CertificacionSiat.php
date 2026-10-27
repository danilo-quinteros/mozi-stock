<?php

namespace App\Console\Commands;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use MoziStudios\Siat\Algoritmos\Archivo;
use MoziStudios\Siat\ClienteSiat;
use MoziStudios\Siat\Codigos\Cufd;
use MoziStudios\Siat\Configuracion\ConfiguracionSistema;
use MoziStudios\Siat\Configuracion\Contribuyente;
use MoziStudios\Siat\Configuracion\PuntoEmision;
use MoziStudios\Siat\Configuracion\TokenDelegado;
use MoziStudios\Siat\Constantes\Ambiente;
use MoziStudios\Siat\Constantes\DocumentoSector;
use MoziStudios\Siat\Constantes\Modalidad;
use MoziStudios\Siat\Constantes\TipoEmision;
use MoziStudios\Siat\Constantes\TipoFacturaDocumento;
use MoziStudios\Siat\Cuf\DatosCuf;
use MoziStudios\Siat\Cuf\GeneradorCuf;
use MoziStudios\Siat\Excepciones\SiatException;
use MoziStudios\Siat\Factura\DetalleCompraVenta;
use MoziStudios\Siat\Factura\FacturaCompraVenta;
use MoziStudios\Siat\Factura\GeneradorXmlCompraVentaV2;
use MoziStudios\Siat\Fecha\FechaSiat;
use MoziStudios\Siat\Respuestas\Mensaje;
use MoziStudios\Siat\Servicios\ContextoDocumento;
use MoziStudios\Siat\Sincronizacion\Catalogo;
use MoziStudios\Siat\Soap\Transporte;

/**
 * Runner de pruebas de certificación Fase I ante el piloto del SIN (Bolivia).
 * Opera exclusivamente en el ambiente de pruebas (piloto, ambiente 2) y no toca
 * la base de datos de producción/tenant (no crea modelos de ventas, facturas ni CUIS).
 */
class CertificacionSiat extends Command
{
    protected $signature = 'mozistock:siat-certificacion
        {etapa : Etapa a ejecutar (I, II, III, IV, V, VI, VII, XI, IX o sus nombres)}
        {--caso= : Filtrar por caso específico}
        {--cantidad= : Límite de pruebas/llamadas a ejecutar}
        {--punto-venta= : Filtrar por punto de venta (0 o 1)}
        {--ensayo : Valida y prepara las llamadas sin enviarlas al SIN}
        {--reanudar : Reanuda desde el estado guardado sin repetir números}
        {--pausa=300 : Pausa en milisegundos entre llamadas al SIN}
        {--v2 : Ejecuta contra la versión anterior (endpoints /v2/)}';

    protected $description = 'Ejecuta las pruebas de certificación Fase I del SIAT en el piloto del SIN';

    private const NUMERO_INICIAL = 700000;

    private const ACTIVIDAD_DEFECTO = '6201000';

    private const PRODUCTO_SIN_DEFECTO = 1003913;

    private const LEYENDA_DEFECTO = 'Ley N° 453: Puedes acceder a la reclamación cuando tus derechos han sido vulnerados.';

    private const UNIDAD_MEDIDA_DEFECTO = 58;

    public function handle(): int
    {
        if ((int) config('siat.ambiente') !== Ambiente::Pruebas->value) {
            $this->error('La certificación solo se ejecuta con SIAT_AMBIENTE=2 (piloto del SIN).');

            return self::FAILURE;
        }

        $esV2 = (bool) $this->option('v2');
        $urlBase = $esV2 ? 'https://pilotosiatservicios.impuestos.gob.bo/v2/' : (string) config('siat.url_base');
        if (! str_contains($urlBase, 'pilotosiatservicios')) {
            $this->error('La URL base SIAT no apunta al piloto del SIN.');

            return self::FAILURE;
        }

        $faltantes = array_keys(array_filter([
            'SIAT_CODIGO_SISTEMA' => blank(config('siat.codigo_sistema')),
            'SIAT_PRUEBAS_NIT' => blank(config('siat.pruebas.nit')),
            'SIAT_PRUEBAS_TOKEN' => blank(config('siat.pruebas.token')),
        ]));

        if ($faltantes !== []) {
            $this->error('Faltan variables en .env: '.implode(', ', $faltantes).'.');

            return self::FAILURE;
        }

        try {
            if ($esV2) {
                $configSistema = new ConfiguracionSistema(
                    codigoSistema: (string) config('siat.codigo_sistema'),
                    ambiente: Ambiente::Pruebas,
                    modalidad: Modalidad::Computarizada,
                    urlBase: $urlBase,
                    segundosConexion: (int) config('siat.segundos_conexion', 15),
                    segundosRespuesta: (int) config('siat.segundos_respuesta', 60),
                );
                $cliente = new ClienteSiat($configSistema, app(Transporte::class));
            } else {
                $cliente = app(ClienteSiat::class);
            }
            $contribuyente = new Contribuyente((int) config('siat.pruebas.nit'), new TokenDelegado((string) config('siat.pruebas.token')));
        } catch (SiatException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $etapaArg = strtolower((string) $this->argument('etapa'));
        $ensayo = (bool) $this->option('ensayo');
        $versionLabel = $esV2 ? ' [VERSIÓN ANTERIOR / V2]' : ' [V.3]';
        $this->info("Certificación SIAT · Fase I · Etapa [{$etapaArg}]{$versionLabel}".($ensayo ? ' [MODO ENSAYO]' : ''));
        $this->info("NIT {$contribuyente->nit} · Sistema {$cliente->configuracion()->codigoSistema} · Token {$contribuyente->token->sufijo()} · URL {$urlBase}");

        $this->asegurarDirectorios();
        $estado = $this->cargarEstado();

        $exito = match ($etapaArg) {
            'i', 'cuis' => $this->etapaCuis($cliente, $contribuyente, $estado),
            'ii', 'sincronizacion', 'catalogos' => $this->etapaSincronizacion($cliente, $contribuyente, $estado),
            'iii', 'cufd' => $this->etapaCufd($cliente, $contribuyente, $estado),
            'iv', 'emision', 'emision-individual' => $this->etapaEmision($cliente, $contribuyente, $estado),
            'v', 'eventos', 'eventos-significativos' => $this->etapaEventos($cliente, $contribuyente, $estado),
            'vi', 'paquetes' => $this->etapaPaquetes($cliente, $contribuyente, $estado),
            'vii', 'anulacion' => $this->etapaAnulacion($cliente, $contribuyente, $estado),
            'xi', 'reversion', 'reversion-anulacion' => $this->etapaReversion($cliente, $contribuyente, $estado),
            'ix', 'masiva' => $this->etapaMasiva($cliente, $contribuyente, $estado),
            default => $this->etapaDesconocida($etapaArg),
        };

        if (! $ensayo) {
            $this->guardarEstado($estado);
        }

        return $exito ? self::SUCCESS : self::FAILURE;
    }

    private function etapaDesconocida(string $etapa): bool
    {
        $this->error("Etapa desconocida: [{$etapa}]. Opciones: I (cuis), II (sincronizacion), III (cufd), IV (emision), V (eventos), VI (paquetes), VII (anulacion), XI (reversion).");

        return false;
    }

    /**
     * @return list<int>
     */
    private function puntosVenta(): array
    {
        if ($this->option('punto-venta') !== null) {
            return [(int) $this->option('punto-venta')];
        }

        return [1, 0];
    }

    private function pausa(): void
    {
        $ms = (int) $this->option('pausa');
        if ($ms > 0) {
            usleep($ms * 1000);
        }

    }

    // =========================================================================
    // ETAPA I: Obtención de CUIS
    // =========================================================================
    /**
     * @param  array<string, mixed>  $estado
     */
    private function etapaCuis(ClienteSiat $cliente, Contribuyente $contribuyente, array &$estado): bool
    {
        $cantidad = $this->option('cantidad') !== null ? (int) $this->option('cantidad') : ($this->option('v2') ? 1 : 2);
        $ensayo = (bool) $this->option('ensayo');
        $aprobadas = 0;
        $fallidas = 0;

        foreach ($this->puntosVenta() as $pv) {
            $punto = new PuntoEmision(0, $pv);
            $this->line("-> Etapa I: CUIS para Sucursal 0 / PV {$pv} ({$cantidad} llamadas)...");

            for ($i = 1; $i <= $cantidad; $i++) {
                $caso = "PV {$pv} llamada {$i}";
                $fechaHora = now('America/La_Paz')->format('Y-m-d H:i:s');

                if ($ensayo) {
                    $this->line("   [ENSAYO] {$caso} -> Simulación exitosa");
                    $this->registrarEvidencia('I-cuis.csv', [
                        $fechaHora, 'I', $caso, 0, $pv, 'cuis', '', 'CUIS-ENSAYO', '980', 'true', '', 'ENSAYO', 'ok', '-',
                    ]);
                    $aprobadas++;

                    continue;
                }

                $this->pausa();

                try {
                    $respuesta = $cliente->codigos($contribuyente)->cuis($punto);
                    $mensajes = $this->mensajesTexto($respuesta->mensajes);
                    $ok = $respuesta->transaccion || $respuesta->codigo !== null;

                    if ($ok) {
                        $aprobadas++;
                        $estado['cuis'][(string) $pv] = $respuesta->codigo;
                        $this->line("   ✓ {$caso}: CUIS {$respuesta->codigo} · vence ".($respuesta->fechaVigencia?->format('d/m/Y') ?? '—'));
                    } else {
                        $fallidas++;
                        $this->warn("   ✗ {$caso}: {$mensajes}");
                    }

                    $this->registrarEvidencia('I-cuis.csv', [
                        $fechaHora, 'I', $caso, 0, $pv, 'cuis', '', $respuesta->codigo ?? '',
                        $respuesta->transaccion ? '980' : '902', $respuesta->transaccion ? 'true' : 'false',
                        '', $mensajes, $ok ? 'ok' : 'falla', '-',
                    ]);
                    $this->registrarRespuestaBruta('I-cuis', [
                        'fecha_hora' => $fechaHora, 'pv' => $pv, 'llamada' => $i, 'respuesta' => $respuesta,
                    ]);
                } catch (\Throwable $e) {
                    $fallidas++;
                    $this->error("   ✗ {$caso} excepción: {$e->getMessage()}");
                    $this->registrarEvidencia('I-cuis.csv', [
                        $fechaHora, 'I', $caso, 0, $pv, 'cuis', '', '', 'ERROR', 'false', '', $e->getMessage(), 'falla', '-',
                    ]);
                }
            }
        }

        $this->actualizarSeguimiento('I', $aprobadas, $fallidas);

        return $fallidas === 0;
    }

    // =========================================================================
    // ETAPA II: Sincronización de catálogos
    // =========================================================================
    /**
     * @param  array<string, mixed>  $estado
     */
    private function etapaSincronizacion(ClienteSiat $cliente, Contribuyente $contribuyente, array &$estado): bool
    {
        $cantidad = $this->option('cantidad') !== null ? (int) $this->option('cantidad') : 50;
        $ensayo = (bool) $this->option('ensayo');
        $casoFiltro = $this->option('caso');
        $aprobadas = 0;
        $fallidas = 0;

        $catalogos = Catalogo::cases();

        foreach ($this->puntosVenta() as $pv) {
            $punto = new PuntoEmision(0, $pv);
            $cuis = $estado['cuis'][(string) $pv] ?? null;

            if ($cuis === null && ! $ensayo) {
                $this->warn("PV {$pv} no tiene CUIS en estado, consultando...");
                $respCuis = $cliente->codigos($contribuyente)->cuis($punto);
                $cuis = $respCuis->codigo;
                $estado['cuis'][(string) $pv] = $cuis;
            }

            $cuis = $cuis ?? 'CUIS-ENSAYO';

            // 17 catálogos + fechaHora
            $casosEtapa = [];
            foreach ($catalogos as $catItem) {
                $casosEtapa[] = ['nombre' => $catItem->name, 'catalogo' => $catItem];
            }

            $casosEtapa[] = ['nombre' => 'FechaHora', 'catalogo' => null];

            foreach ($casosEtapa as $item) {
                $nombreCaso = $item['nombre'];
                $cat = $item['catalogo'];

                if ($casoFiltro !== null && strcasecmp($nombreCaso, $casoFiltro) !== 0) {
                    continue;
                }

                $this->line("-> Etapa II: {$nombreCaso} para PV {$pv} ({$cantidad} veces)...");

                for ($i = 1; $i <= $cantidad; $i++) {
                    $caso = "{$nombreCaso} PV {$pv} #{$i}";
                    $fechaHora = now('America/La_Paz')->format('Y-m-d H:i:s');

                    if ($ensayo) {
                        $this->registrarEvidencia('II-sincronizacion.csv', [
                            $fechaHora, 'II', $caso, 0, $pv, $nombreCaso, '', '', '926', 'true', '', 'ENSAYO', 'ok', '-',
                        ]);
                        $aprobadas++;

                        continue;
                    }

                    $this->pausa();

                    try {
                        if ($cat === null) {
                            $resp = $cliente->sincronizacion($contribuyente)->fechaHora($punto, $cuis);
                            $ok = $resp->transaccion || $resp->fechaHora !== null;
                            $mensajes = $this->mensajesTexto($resp->mensajes);
                        } else {
                            $resp = $cliente->sincronizacion($contribuyente)->catalogo($cat, $punto, $cuis);
                            $ok = $resp->transaccion || count($resp->items) > 0;
                            $mensajes = $this->mensajesTexto($resp->mensajes);
                        }

                        if ($ok) {
                            $aprobadas++;
                        } else {
                            $fallidas++;
                        }

                        $this->registrarEvidencia('II-sincronizacion.csv', [
                            $fechaHora, 'II', $caso, 0, $pv, $nombreCaso, '', '',
                            $ok ? '926' : '902', $ok ? 'true' : 'false', '', $mensajes, $ok ? 'ok' : 'falla', '-',
                        ]);
                    } catch (\Throwable $e) {
                        $fallidas++;
                        $this->registrarEvidencia('II-sincronizacion.csv', [
                            $fechaHora, 'II', $caso, 0, $pv, $nombreCaso, '', '', 'ERROR', 'false', '', $e->getMessage(), 'falla', '-',
                        ]);
                    }

                }
            }
        }

        $this->actualizarSeguimiento('II', $aprobadas, $fallidas);

        return $fallidas === 0;
    }

    // =========================================================================
    // ETAPA III: Obtención de CUFD
    // =========================================================================
    /**
     * @param  array<string, mixed>  $estado
     */
    private function etapaCufd(ClienteSiat $cliente, Contribuyente $contribuyente, array &$estado): bool
    {
        $cantidad = $this->option('cantidad') !== null ? (int) $this->option('cantidad') : 100;
        $ensayo = (bool) $this->option('ensayo');
        $aprobadas = 0;
        $fallidas = 0;

        foreach ($this->puntosVenta() as $pv) {
            $punto = new PuntoEmision(0, $pv);
            $cuis = $estado['cuis'][(string) $pv] ?? null;

            if ($cuis === null && ! $ensayo) {
                $respCuis = $cliente->codigos($contribuyente)->cuis($punto);
                $cuis = $respCuis->codigo;
                $estado['cuis'][(string) $pv] = $cuis;
            }

            $cuis = $cuis ?? 'CUIS-ENSAYO';

            $this->line("-> Etapa III: CUFD para Sucursal 0 / PV {$pv} ({$cantidad} llamadas)...");

            for ($i = 1; $i <= $cantidad; $i++) {
                $caso = "PV {$pv} llamada {$i}";
                $fechaHora = now('America/La_Paz')->format('Y-m-d H:i:s');

                if ($ensayo) {
                    $this->registrarEvidencia('III-cufd.csv', [
                        $fechaHora, 'III', $caso, 0, $pv, 'cufd', '', 'CUFD-ENSAYO', '980', 'true', '', 'ENSAYO', 'ok', '-',
                    ]);
                    $aprobadas++;

                    continue;
                }

                $this->pausa();

                try {
                    $respuesta = $cliente->codigos($contribuyente)->cufd($punto, $cuis);
                    $mensajes = $this->mensajesTexto($respuesta->mensajes);
                    $cufdObj = $respuesta->cufd;
                    $ok = $respuesta->transaccion && $cufdObj !== null;

                    if ($ok) {
                        $aprobadas++;
                        $estado['cufd'][(string) $pv] = [
                            'codigo' => $cufdObj->codigo,
                            'codigoControl' => $cufdObj->codigoControl,
                            'codigoInterno' => $cufdObj->codigoInterno,
                            'direccion' => $cufdObj->direccion,
                            'fechaVigencia' => $cufdObj->fechaVigencia?->format('Y-m-d H:i:s'),
                        ];
                    } else {
                        $fallidas++;
                    }

                    $this->registrarEvidencia('III-cufd.csv', [
                        $fechaHora, 'III', $caso, 0, $pv, 'cufd', '', $cufdObj->codigo ?? '',
                        $ok ? '980' : '902', $ok ? 'true' : 'false', '', $mensajes, $ok ? 'ok' : 'falla', '-',
                    ]);
                    $this->registrarRespuestaBruta('III-cufd', [
                        'fecha_hora' => $fechaHora, 'pv' => $pv, 'llamada' => $i, 'respuesta' => $respuesta,
                    ]);
                } catch (\Throwable $e) {
                    $fallidas++;
                    $this->registrarEvidencia('III-cufd.csv', [
                        $fechaHora, 'III', $caso, 0, $pv, 'cufd', '', '', 'ERROR', 'false', '', $e->getMessage(), 'falla', '-',
                    ]);
                }
            }
        }

        $this->actualizarSeguimiento('III', $aprobadas, $fallidas);

        return $fallidas === 0;
    }

    // =========================================================================
    // ETAPA IV: Emisión Individual
    // =========================================================================
    /**
     * @param  array<string, mixed>  $estado
     */
    private function etapaEmision(ClienteSiat $cliente, Contribuyente $contribuyente, array &$estado): bool
    {
        $esV2 = (bool) $this->option('v2');
        $cantidadTotal = $this->option('cantidad') !== null ? (int) $this->option('cantidad') : ($esV2 ? 250 : 500);
        $ensayo = (bool) $this->option('ensayo');
        $pvs = $this->puntosVenta();
        $porPv = (int) ceil($cantidadTotal / count($pvs));
        $aprobadas = 0;
        $fallidas = 0;

        foreach ($pvs as $pv) {
            $punto = new PuntoEmision(0, $pv);
            $cuis = $estado['cuis'][(string) $pv] ?? null;

            if ($cuis === null && ! $ensayo) {
                $cuis = $cliente->codigos($contribuyente)->cuis($punto)->codigo;
                $estado['cuis'][(string) $pv] = $cuis;
            }

            $cuis = $cuis ?? 'CUIS-ENSAYO';

            $cufdDatos = $estado['cufd'][(string) $pv] ?? null;
            if (! $ensayo) {
                $respCufd = $cliente->codigos($contribuyente)->cufd($punto, $cuis);
                if ($respCufd->cufd !== null) {
                    $cufdObj = $respCufd->cufd;
                    $cufdDatos = [
                        'codigo' => $cufdObj->codigo,
                        'codigoControl' => $cufdObj->codigoControl,
                        'codigoInterno' => $cufdObj->codigoInterno,
                        'direccion' => $cufdObj->direccion,
                    ];
                    $estado['cufd'][(string) $pv] = $cufdDatos;
                }
            }

            $cufdObj = new Cufd(
                codigo: $cufdDatos['codigo'] ?? 'BQUE+QytqQUDBKVUFOSVRPQkxVRFZNVFVJBMDAwMDAwM',
                codigoControl: $cufdDatos['codigoControl'] ?? 'A19E23EF34124CD',
                codigoInterno: $cufdDatos['codigoInterno'] ?? 'A',
                direccion: $cufdDatos['direccion'] ?? 'Av. Principal #100',
            );

            $this->line("-> Etapa IV: Emisión individual para PV {$pv} ({$porPv} facturas)...");

            for ($i = 1; $i <= $porPv && ($aprobadas + $fallidas) < $cantidadTotal; $i++) {
                // Reservar número en estado y guardar ANTES de enviar
                $estado['ultimo_numero'][(string) $pv] = ($estado['ultimo_numero'][(string) $pv] ?? self::NUMERO_INICIAL) + 1;
                $numero = $estado['ultimo_numero'][(string) $pv];
                $this->guardarEstado($estado);

                $fechaHora = now('America/La_Paz')->format('Y-m-d H:i:s');
                $caso = "PV {$pv} factura {$numero}";

                if ($esV2) {
                    $fechaSiat = FechaSiat::desde(now('America/La_Paz'));
                    $datosCuf = new DatosCuf(
                        nit: (int) config('siat.pruebas.nit'),
                        fecha: $fechaSiat,
                        sucursal: 0,
                        modalidad: Modalidad::Computarizada,
                        tipoEmision: TipoEmision::EnLinea,
                        tipoFactura: TipoFacturaDocumento::ConDerechoCreditoFiscal,
                        documentoSector: DocumentoSector::CompraVenta,
                        numeroFactura: $numero,
                        puntoVenta: $pv,
                    );
                    $cuf = GeneradorCuf::generar($datosCuf, $cufdObj->codigoControl);
                    $generadorV2 = new GeneradorXmlCompraVentaV2;
                    $xml = $generadorV2->generar(
                        nitEmisor: (int) config('siat.pruebas.nit'),
                        razonSocialEmisor: 'MOZISTOCK PRUEBAS',
                        municipio: 'La Paz',
                        telefono: null,
                        numeroFactura: $numero,
                        cuf: $cuf,
                        cufd: $cufdObj->codigo,
                        codigoSucursal: 0,
                        direccion: $cufdObj->direccion ?? 'Av. Principal #100',
                        codigoPuntoVenta: $pv,
                        fechaEmision: $fechaSiat->xml(),
                        nombreRazonSocial: 'CONTROL TRIBUTARIO',
                        codigoTipoDocumentoIdentidad: 5,
                        numeroDocumento: '99002',
                        complemento: null,
                        codigoCliente: '99002',
                        codigoMetodoPago: 1,
                        numeroTarjeta: null,
                        montoTotal: '100.00',
                        montoTotalSujetoIva: '100.00',
                        codigoMoneda: 1,
                        tipoCambio: '1',
                        montoTotalMoneda: '100.00',
                        montoGiftCard: null,
                        descuentoAdicional: '0.00',
                        codigoExcepcion: 1,
                        cafc: null,
                        leyenda: self::LEYENDA_DEFECTO,
                        usuario: 'mozistock-cert',
                        codigoDocumentoSector: 1,
                        detalles: [
                            [
                                'actividadEconomica' => self::ACTIVIDAD_DEFECTO,
                                'codigoProductoSin' => self::PRODUCTO_SIN_DEFECTO,
                                'codigoProducto' => 'PROD-01',
                                'descripcion' => 'SERVICIO DE PRUEBA CERTIFICACION V2',
                                'cantidad' => '1.00',
                                'unidadMedida' => self::UNIDAD_MEDIDA_DEFECTO,
                                'precioUnitario' => '100.00',
                                'montoDescuento' => null,
                                'subTotal' => '100.00',
                                'numeroSerie' => null,
                                'numeroImei' => null,
                            ],
                        ],
                    );
                    $archivo = Archivo::comprimir($xml);
                    $hashArchivo = Archivo::sha256($archivo);
                    $xmlPath = storage_path("app/private/siat-certificacion/xml/factura_v2_{$pv}_{$numero}.xml");
                    file_put_contents($xmlPath, $this->ocultar($xml));
                    $evidenciaRelativa = "xml/factura_v2_{$pv}_{$numero}.xml";
                } else {
                    $factura = $this->armarFactura($pv, $numero, $cufdObj, TipoEmision::EnLinea);
                    $preparada = $cliente->preparador()->preparar($factura);
                    $cuf = $preparada->cuf;
                    $archivo = $preparada->archivo;
                    $hashArchivo = $preparada->hashArchivo;
                    $xmlPath = storage_path("app/private/siat-certificacion/xml/factura_{$pv}_{$numero}.xml");
                    file_put_contents($xmlPath, $this->ocultar($preparada->xml));
                    $evidenciaRelativa = "xml/factura_{$pv}_{$numero}.xml";
                }

                if ($ensayo) {
                    $this->line("   [ENSAYO] Factura #{$numero} CUF {$cuf} -> Válida");
                    $this->registrarEvidencia('IV-emision.csv', [
                        $fechaHora, 'IV', $caso, 0, $pv, 'recepcionFactura', $numero, $cuf,
                        '908', 'true', '', 'ENSAYO XSD OK', 'ok', $evidenciaRelativa,
                    ]);
                    $aprobadas++;

                    continue;
                }

                $this->pausa();

                try {
                    $contexto = new ContextoDocumento($punto, $cuis, $cufdObj->codigo, TipoEmision::EnLinea);
                    if ($esV2) {
                        $respuesta = $cliente->compraVenta($contribuyente)->recepcionFacturaRaw($contexto, $archivo, $hashArchivo);
                    } else {
                        $respuesta = $cliente->compraVenta($contribuyente)->recepcionFactura($preparada, $cuis);
                    }

                    // Si responde pendiente (901), verificar estado hasta 4 veces
                    $intentos = 0;
                    while ($respuesta->pendiente() && $intentos++ < 4) {
                        sleep(2);
                        $respuesta = $cliente->compraVenta($contribuyente)->verificacionEstado($contexto, $cuf);
                    }

                    $mensajes = $this->mensajesTexto($respuesta->mensajes);
                    $ok = $respuesta->validada();

                    if ($ok) {
                        $aprobadas++;
                        $estado['facturas_emitidas'][] = [
                            'numero' => $numero,
                            'pv' => $pv,
                            'cuf' => $cuf,
                            'fecha' => $fechaHora,
                            'xml' => $evidenciaRelativa,
                            'estado' => $respuesta->codigoEstado,
                        ];
                        $this->line("   ✓ Factura #{$numero} validada (908) · CUF {$cuf}");
                    } else {
                        $fallidas++;
                        $this->warn("   ✗ Factura #{$numero} estado {$respuesta->codigoEstado}: {$mensajes}");
                    }

                    $this->registrarEvidencia('IV-emision.csv', [
                        $fechaHora, 'IV', $caso, 0, $pv, 'recepcionFactura', $numero, $cuf,
                        (string) $respuesta->codigoEstado, $respuesta->transaccion ? 'true' : 'false',
                        $respuesta->codigoRecepcion ?? '', $mensajes, $ok ? 'ok' : 'falla', $evidenciaRelativa,
                    ]);
                    $this->registrarRespuestaBruta('IV-emision', [
                        'numero' => $numero, 'cuf' => $cuf, 'respuesta' => $respuesta,
                    ]);
                } catch (\Throwable $e) {
                    $fallidas++;
                    $this->error("   ✗ Factura #{$numero} error: {$e->getMessage()}");
                    $this->registrarEvidencia('IV-emision.csv', [
                        $fechaHora, 'IV', $caso, 0, $pv, 'recepcionFactura', $numero, $cuf,
                        'ERROR', 'false', '', $e->getMessage(), 'falla', $evidenciaRelativa,
                    ]);
                }
            }
        }

        $this->actualizarSeguimiento('IV', $aprobadas, $fallidas);

        return $fallidas === 0;
    }

    // =========================================================================
    // ETAPA VII: Anulación
    // =========================================================================
    /**
     * @param  array<string, mixed>  $estado
     */
    private function etapaAnulacion(ClienteSiat $cliente, Contribuyente $contribuyente, array &$estado): bool
    {
        $cantidad = $this->option('cantidad') !== null ? (int) $this->option('cantidad') : 250;
        $ensayo = (bool) $this->option('ensayo');
        $anuladasPrevias = array_column($estado['facturas_anuladas'] ?? [], 'cuf');
        $candidatas = array_reverse(array_filter($estado['facturas_emitidas'] ?? [], fn ($f) => ! in_array($f['cuf'], $anuladasPrevias, true)));

        $aprobadas = 0;
        $fallidas = 0;

        $this->line("-> Etapa VII: Anulación de facturas (meta: {$cantidad})...");

        $procesadas = 0;
        foreach ($candidatas as $facturaEmitida) {
            if ($aprobadas >= $cantidad) {
                break;
            }

            $pv = $facturaEmitida['pv'] ?? 0;
            $numero = $facturaEmitida['numero'];
            $cuf = $facturaEmitida['cuf'];
            $caso = "PV {$pv} factura {$numero}";
            $fechaHora = now('America/La_Paz')->format('Y-m-d H:i:s');
            $procesadas++;

            if ($ensayo) {
                $this->registrarEvidencia('VII-anulacion.csv', [
                    $fechaHora, 'VII', $caso, 0, $pv, 'anulacionFactura', $numero, $cuf,
                    '905', 'true', '', 'ENSAYO ANULACION OK', 'ok', '-',
                ]);
                $aprobadas++;

                continue;
            }

            $punto = new PuntoEmision(0, $pv);
            $cuis = $this->obtenerCuis($cliente, $contribuyente, $punto, $estado, $ensayo);
            $cufdCodigo = $estado['cufd'][(string) $pv]['codigo'] ?? 'CUFD-DEFECTO';
            $contexto = new ContextoDocumento($punto, $cuis, $cufdCodigo);

            $this->pausa();

            $intentos = 0;
            $maxIntentos = 6;
            $ok = false;
            $mensajes = '';
            $respuesta = null;

            while ($intentos < $maxIntentos) {
                $intentos++;
                try {
                    $respuesta = $cliente->compraVenta($contribuyente)->anulacion($contexto, $cuf, 1);
                    $mensajes = $this->mensajesTexto($respuesta->mensajes);
                    $yaAnulada = str_contains($mensajes, 'YA SE ENCUENTRA ANULADA');
                    $ok = $respuesta->anulacionConfirmada() || $respuesta->codigoEstado === 905 || $yaAnulada;

                    if ($ok) {
                        $aprobadas++;
                        $estado['facturas_anuladas'][] = [
                            'cuf' => $cuf, 'numero' => $numero, 'pv' => $pv, 'motivo' => 1,
                        ];
                        $this->guardarEstado($estado);
                        $this->line("   ✓ Factura #{$numero} anulada (".($yaAnulada ? '906-confirmada-previa' : '905').')');
                        break;
                    }

                    if (($respuesta->codigoEstado === 906 || $respuesta->codigoEstado === 909 || str_contains($mensajes, '408') || str_contains($mensajes, '400') || str_contains($mensajes, 'RCV') || str_contains($mensajes, 'Demasiado tiempo de espera')) && $intentos < $maxIntentos) {
                        $this->warn("   ↻ Factura #{$numero} RCV 408, reintentando ({$intentos}/{$maxIntentos}) en 5s...");
                        sleep(5);

                        continue;
                    }

                    $fallidas++;
                    $this->warn("   ✗ Factura #{$numero} estado {$respuesta->codigoEstado}: {$mensajes}");
                    break;
                } catch (\Throwable $e) {
                    if ($intentos < $maxIntentos) {
                        $this->warn("   ↻ Factura #{$numero} excepción {$e->getMessage()}, reintentando ({$intentos}/{$maxIntentos}) en 5s...");
                        sleep(5);

                        continue;
                    }
                    $fallidas++;
                    $this->error("   ✗ Factura #{$numero} excepción: {$e->getMessage()}");
                    $mensajes = $e->getMessage();
                    break;
                }
            }

            $this->registrarEvidencia('VII-anulacion.csv', [
                $fechaHora, 'VII', $caso, 0, $pv, 'anulacionFactura', $numero, $cuf,
                $respuesta !== null ? (string) $respuesta->codigoEstado : 'ERROR',
                $respuesta !== null && $respuesta->transaccion ? 'true' : 'false',
                $respuesta !== null ? ($respuesta->codigoRecepcion ?? '') : '',
                $mensajes, $ok ? 'ok' : 'falla', '-',
            ]);

        }

        if ($procesadas === 0 && $ensayo) {
            $this->line('   [ENSAYO] Generando llamada simulada de anulación');
            $this->registrarEvidencia('VII-anulacion.csv', [
                now('America/La_Paz')->format('Y-m-d H:i:s'), 'VII', 'PV 0 ensayo', 0, 0, 'anulacionFactura',
                '700001', 'CUF-ENSAYO', '905', 'true', '', 'ENSAYO', 'ok', '-',
            ]);
            $aprobadas++;
        }

        $this->actualizarSeguimiento('VII', $aprobadas, $fallidas);

        return $aprobadas >= $cantidad || $fallidas === 0;
    }

    // =========================================================================
    // ETAPA XI: Reversión de Anulación
    // =========================================================================
    /**
     * @param  array<string, mixed>  $estado
     */
    private function etapaReversion(ClienteSiat $cliente, Contribuyente $contribuyente, array &$estado): bool
    {
        $cantidad = $this->option('cantidad') !== null ? (int) $this->option('cantidad') : 98;
        $ensayo = (bool) $this->option('ensayo');
        $revertidasPrevias = array_column($estado['facturas_revertidas'] ?? [], 'cuf');
        $candidatas = array_filter($estado['facturas_anuladas'] ?? [], fn ($f) => ! in_array($f['cuf'], $revertidasPrevias, true));

        $aprobadas = 0;
        $fallidas = 0;

        $this->line("-> Etapa XI: Reversión de anulación (meta: {$cantidad})...");

        $procesadas = 0;
        foreach ($candidatas as $facturaAnulada) {
            if ($aprobadas >= $cantidad) {
                break;
            }

            $pv = $facturaAnulada['pv'] ?? 0;
            $numero = $facturaAnulada['numero'];
            $cuf = $facturaAnulada['cuf'];
            $caso = "PV {$pv} factura {$numero}";
            $fechaHora = now('America/La_Paz')->format('Y-m-d H:i:s');
            $procesadas++;

            if ($ensayo) {
                $this->registrarEvidencia('XI-reversion.csv', [
                    $fechaHora, 'XI', $caso, 0, $pv, 'reversionAnulacionFactura', $numero, $cuf,
                    '907', 'true', '', 'ENSAYO REVERSION OK', 'ok', '-',
                ]);
                $aprobadas++;

                continue;
            }

            $punto = new PuntoEmision(0, $pv);
            $cuis = $this->obtenerCuis($cliente, $contribuyente, $punto, $estado, $ensayo);
            $cufdCodigo = $estado['cufd'][(string) $pv]['codigo'] ?? 'CUFD-DEFECTO';
            $contexto = new ContextoDocumento($punto, $cuis, $cufdCodigo);

            $this->pausa();

            $intentos = 0;
            $maxIntentos = 6;
            $ok = false;
            $mensajes = '';
            $respuesta = null;

            while ($intentos < $maxIntentos) {
                $intentos++;
                try {
                    $respuesta = $cliente->compraVenta($contribuyente)->reversionAnulacion($contexto, $cuf);
                    $mensajes = $this->mensajesTexto($respuesta->mensajes);
                    $yaRevertida = str_contains($mensajes, 'YA SE ENCUENTRA') || str_contains($mensajes, 'REVERTIDA');
                    $ok = $respuesta->reversionConfirmada() || $respuesta->codigoEstado === 907 || $yaRevertida;

                    if ($ok) {
                        $aprobadas++;
                        $estado['facturas_revertidas'][] = ['cuf' => $cuf, 'numero' => $numero, 'pv' => $pv];
                        $this->guardarEstado($estado);
                        $this->line("   ✓ Factura #{$numero} reversión confirmada (".($yaRevertida ? 'confirmada-previa' : '907').')');
                        break;
                    }

                    if (($respuesta->codigoEstado === 909 || $respuesta->codigoEstado === 906 || str_contains($mensajes, '408') || str_contains($mensajes, '400') || str_contains($mensajes, 'RCV') || str_contains($mensajes, 'Demasiado tiempo de espera')) && $intentos < $maxIntentos) {
                        $this->warn("   ↻ Factura #{$numero} RCV reintento ({$intentos}/{$maxIntentos}) en 5s...");
                        sleep(5);

                        continue;
                    }

                    $fallidas++;
                    $this->warn("   ✗ Factura #{$numero} estado {$respuesta->codigoEstado}: {$mensajes}");
                    break;
                } catch (\Throwable $e) {
                    if ($intentos < $maxIntentos) {
                        $this->warn("   ↻ Factura #{$numero} excepción {$e->getMessage()}, reintentando ({$intentos}/{$maxIntentos}) en 5s...");
                        sleep(5);

                        continue;
                    }
                    $fallidas++;
                    $this->error("   ✗ Factura #{$numero} excepción: {$e->getMessage()}");
                    $mensajes = $e->getMessage();
                    break;
                }
            }

            $this->registrarEvidencia('XI-reversion.csv', [
                $fechaHora, 'XI', $caso, 0, $pv, 'reversionAnulacionFactura', $numero, $cuf,
                $respuesta !== null ? (string) $respuesta->codigoEstado : 'ERROR',
                $respuesta !== null && $respuesta->transaccion ? 'true' : 'false',
                $respuesta !== null ? ($respuesta->codigoRecepcion ?? '') : '',
                $mensajes, $ok ? 'ok' : 'falla', '-',
            ]);

        }

        if ($procesadas === 0 && $ensayo) {
            $this->line('   [ENSAYO] Generando llamada simulada de reversión');
            $this->registrarEvidencia('XI-reversion.csv', [
                now('America/La_Paz')->format('Y-m-d H:i:s'), 'XI', 'PV 0 ensayo', 0, 0, 'reversionAnulacionFactura',
                '700001', 'CUF-ENSAYO', '907', 'true', '', 'ENSAYO', 'ok', '-',
            ]);
            $aprobadas++;
        }

        $this->actualizarSeguimiento('XI', $aprobadas, $fallidas);

        return $aprobadas >= $cantidad || $fallidas === 0;
    }

    // =========================================================================
    // ETAPA V: Eventos Significativos
    // =========================================================================
    /**
     * @param  array<string, mixed>  $estado
     */
    private function etapaEventos(ClienteSiat $cliente, Contribuyente $contribuyente, array &$estado): bool
    {
        $cantidad = $this->option('cantidad') !== null ? (int) $this->option('cantidad') : 5;
        $ensayo = (bool) $this->option('ensayo');
        $aprobadas = 0;
        $fallidas = 0;

        $esV2 = (bool) $this->option('v2');
        $eventosMotivos = [
            1 => 'CORTE DEL SERVICIO DE INTERNET',
            2 => 'INACCESIBILIDAD AL SERVICIO WEB DE LA ADMINISTRACIÓN TRIBUTARIA',
            3 => 'INGRESO A ZONAS SIN INTERNET POR DESPLIEGUE DE PUNTO DE VENTA EN VEHICULOS AUTOMOTORES',
            4 => 'VENTA EN LUGARES SIN INTERNET',
        ];

        if ($esV2) {
            $eventosMotivos[5] = 'CORTE DE SUMINISTRO DE ENERGIA ELECTRICA';
            $eventosMotivos[6] = 'VIRUS INFORMÁTICO O FALLA DE SOFTWARE';
            $eventosMotivos[7] = 'CAMBIO DE INFRAESTRUCTURA DEL SISTEMA INFORMÁTICO DE FACTURACIÓN O FALLA DE HARDWARE';
        }

        foreach ($this->puntosVenta() as $pv) {
            $punto = new PuntoEmision(0, $pv);
            $cuis = $this->obtenerCuis($cliente, $contribuyente, $punto, $estado, $ensayo);

            $cufdEventoObj = null;
            if (! $ensayo) {
                $rInit = $cliente->codigos($contribuyente)->cufd($punto, $cuis);
                $cufdEventoObj = $rInit->cufd;
                sleep(2);
            }

            foreach ($eventosMotivos as $codigoMotivo => $descripcionMotivo) {
                $this->line("-> Etapa V: Evento {$codigoMotivo} ({$descripcionMotivo}) para PV {$pv} ({$cantidad} veces)...");

                for ($i = 1; $i <= $cantidad; $i++) {
                    $caso = "Evento {$codigoMotivo} PV {$pv} llamada {$i}";
                    $fechaHora = now('America/La_Paz')->format('Y-m-d H:i:s');

                    if ($ensayo) {
                        $this->registrarEvidencia('V-eventos.csv', [
                            $fechaHora, 'V', $caso, 0, $pv, 'registroEventoSignificativo', '',
                            '', '991', 'true', 'COD-EVENTO-ENSAYO', 'ENSAYO REGISTRO EVENTO OK', 'ok', '-',
                        ]);
                        $aprobadas++;

                        continue;
                    }

                    $this->pausa();

                    try {
                        if ($cufdEventoObj === null) {
                            $rInit = $cliente->codigos($contribuyente)->cufd($punto, $cuis);
                            $cufdEventoObj = $rInit->cufd;
                            sleep(2);
                        }
                        $cufdEvento = $cufdEventoObj !== null ? $cufdEventoObj->codigo : 'CUFD-EVENTO';

                        $inicio = new DateTimeImmutable('now', new DateTimeZone('America/La_Paz'));
                        sleep(1);
                        $fin = new DateTimeImmutable('now', new DateTimeZone('America/La_Paz'));

                        $r2 = $cliente->codigos($contribuyente)->cufd($punto, $cuis);
                        $cufdNuevo = $r2->cufd !== null ? $r2->cufd->codigo : $cufdEvento;
                        if ($cufdNuevo === $cufdEvento) {
                            sleep(2);
                            $r2 = $cliente->codigos($contribuyente)->cufd($punto, $cuis);
                            $cufdNuevo = $r2->cufd !== null ? $r2->cufd->codigo : $cufdEvento;
                        }

                        if ($r2->cufd !== null) {
                            $estado['cufd'][(string) $pv] = [
                                'codigo' => $r2->cufd->codigo,
                                'codigoControl' => $r2->cufd->codigoControl,
                                'codigoInterno' => $r2->cufd->codigoInterno,
                                'direccion' => $r2->cufd->direccion,
                                'fechaVigencia' => $r2->cufd->fechaVigencia?->format('Y-m-d H:i:s'),
                            ];
                        }

                        $respuesta = $cliente->operaciones($contribuyente)->registroEventoSignificativo(
                            sucursal: 0,
                            puntoVenta: $pv,
                            cuis: $cuis,
                            cufd: $cufdNuevo,
                            cufdEvento: $cufdEvento,
                            codigoMotivoEvento: $codigoMotivo,
                            descripcion: $descripcionMotivo,
                            fechaHoraInicio: $inicio,
                            fechaHoraFin: $fin,
                        );

                        $ok = $respuesta->transaccion && $respuesta->codigoRecepcionEventoSignificativo !== null;
                        $mensajes = $this->mensajesTexto($respuesta->mensajes);
                        $codRecepcion = (string) ($respuesta->codigoRecepcionEventoSignificativo ?? '');

                        if ($ok) {
                            $aprobadas++;
                            $estado['eventos'][] = [
                                'codigoRecepcion' => $codRecepcion,
                                'codigoEvento' => $codigoMotivo,
                                'pv' => $pv,
                                'fechaInicio' => $inicio->format('Y-m-d H:i:s'),
                                'fechaFin' => $fin->format('Y-m-d H:i:s'),
                                'cufdEvento' => $cufdEvento,
                                'codigoControl' => $cufdEventoObj?->codigoControl,
                                'codigoInterno' => $cufdEventoObj?->codigoInterno,
                            ];
                            $this->guardarEstado($estado);
                            $this->line("   ✓ Evento {$codigoMotivo} registrado · Código recepción {$codRecepcion}");

                            if ($r2->cufd !== null) {
                                $cufdEventoObj = $r2->cufd;
                            }
                        } else {
                            $fallidas++;
                            $this->warn("   ✗ Evento {$codigoMotivo} rechazado: {$mensajes}");
                        }

                        $this->registrarEvidencia('V-eventos.csv', [
                            $fechaHora, 'V', $caso, 0, $pv, 'registroEventoSignificativo', '',
                            '', $ok ? '991' : '902', $respuesta->transaccion ? 'true' : 'false',
                            $codRecepcion, $mensajes, $ok ? 'ok' : 'falla', '-',
                        ]);
                    } catch (\Throwable $e) {
                        $fallidas++;
                        $this->error("   ✗ Evento {$codigoMotivo} excepción: {$e->getMessage()}");
                        $this->registrarEvidencia('V-eventos.csv', [
                            $fechaHora, 'V', $caso, 0, $pv, 'registroEventoSignificativo', '',
                            '', 'ERROR', 'false', '', $e->getMessage(), 'falla', '-',
                        ]);
                    }

                }
            }
        }

        $this->actualizarSeguimiento('V', $aprobadas, $fallidas);

        return $fallidas === 0;
    }

    // =========================================================================
    // ETAPA VI: Paquetes
    // =========================================================================
    /**
     * @param  array<string, mixed>  $estado
     */
    private function etapaPaquetes(ClienteSiat $cliente, Contribuyente $contribuyente, array &$estado): bool
    {
        $esV2 = (bool) $this->option('v2');
        $paquetesTotal = $this->option('cantidad') !== null ? (int) $this->option('cantidad') : ($esV2 ? 280 : 28);
        $ensayo = (bool) $this->option('ensayo');
        $aprobadas = 0;
        $fallidas = 0;

        $pvs = $this->puntosVenta();

        foreach ($pvs as $pv) {
            $punto = new PuntoEmision(0, $pv);
            $cuis = $this->obtenerCuis($cliente, $contribuyente, $punto, $estado, $ensayo);

            $cufdTransmision = $estado['cufd'][(string) $pv]['codigo'] ?? 'CUFD-PAQUETE';
            if (! $ensayo) {
                try {
                    $rCufd = $cliente->codigos($contribuyente)->cufd($punto, $cuis);
                    if ($rCufd->cufd !== null) {
                        $cufdTransmision = $rCufd->cufd->codigo;
                        $estado['cufd'][(string) $pv] = [
                            'codigo' => $rCufd->cufd->codigo,
                            'codigoControl' => $rCufd->cufd->codigoControl,
                            'codigoInterno' => $rCufd->cufd->codigoInterno,
                            'direccion' => $rCufd->cufd->direccion,
                            'fechaVigencia' => $rCufd->cufd->fechaVigencia?->format('Y-m-d H:i:s'),
                        ];
                    }
                } catch (\Throwable $e) {
                    $this->warn("   No se pudo refrescar CUFD para PV {$pv}: {$e->getMessage()}");
                }
            }

            // Filtrar todos los eventos registrados para este PV (solo motivos 1 a 4 que no requieren CAFC)
            $eventosDelPv = array_values(array_filter($estado['eventos'] ?? [], fn ($ev) => ($ev['pv'] ?? null) === $pv && ($ev['codigoEvento'] ?? 0) <= 4));
            if (empty($eventosDelPv)) {
                $eventosDelPv = array_values(array_filter($estado['eventos'] ?? [], fn ($ev) => ($ev['pv'] ?? null) === $pv));
            }
            if (empty($eventosDelPv)) {
                $eventosDelPv = [$estado['eventos'][0] ?? []];
            }

            // Según CasosDePruebaEmisionPorPaquetes.xlsx del SIN:
            // PV 1: CANTIDAD DE FACTURAS = igual a 500 (500 facturas)
            // PV 0: CANTIDAD DE FACTURAS = menor a 500 (e.g. 5 facturas)
            $facturasPorPaquete = $pv === 1 ? 500 : 5;

            $generadorXmlV2 = $esV2 ? new GeneradorXmlCompraVentaV2 : null;
            $nitEmisor = (int) config('siat.pruebas.nit');

            foreach ($eventosDelPv as $eventoPv) {
                if ($aprobadas >= $paquetesTotal) {
                    break;
                }

                $codigoEvento = $eventoPv['codigoRecepcion'] ?? 9973928;
                $motivo = $eventoPv['codigoEvento'] ?? 1;
                $fechaEmisionPaquete = isset($eventoPv['fechaInicio'])
                    ? new DateTimeImmutable((string) $eventoPv['fechaInicio'], new DateTimeZone('America/La_Paz'))
                    : new DateTimeImmutable('now', new DateTimeZone('America/La_Paz'));

                $cufdFacturas = new Cufd(
                    codigo: $eventoPv['cufdEvento'] ?? $cufdTransmision,
                    codigoControl: $eventoPv['codigoControl'] ?? $estado['cufd'][(string) $pv]['codigoControl'] ?? 'CONTROL123',
                    codigoInterno: $eventoPv['codigoInterno'] ?? $estado['cufd'][(string) $pv]['codigoInterno'] ?? 'C',
                    direccion: $estado['cufd'][(string) $pv]['direccion'] ?? 'Av. Principal #100',
                );

                $this->line("-> Etapa VI: Paquetes PV {$pv} ({$facturasPorPaquete} facturas/paquete) para Evento {$motivo} (recepción {$codigoEvento})...");

                for ($p = 1; $p <= 4; $p++) {
                    if ($aprobadas >= $paquetesTotal) {
                        break;
                    }

                    $fechaHora = now('America/La_Paz')->format('Y-m-d H:i:s');
                    $caso = "Paquete PV {$pv} Evento {$motivo} #{$p}";

                    $this->line("   Construyendo paquete de {$facturasPorPaquete} facturas fuera de línea...");
                    $startBuild = microtime(true);
                    $xmlMap = [];
                    for ($f = 1; $f <= $facturasPorPaquete; $f++) {
                        if (! $ensayo) {
                            $estado['ultimo_numero'][(string) $pv] = ($estado['ultimo_numero'][(string) $pv] ?? self::NUMERO_INICIAL) + 1;
                        }

                        $num = ($estado['ultimo_numero'][(string) $pv] ?? self::NUMERO_INICIAL) + ($ensayo ? $f : 0);

                        if ($esV2) {
                            $datosCuf = new DatosCuf(
                                nit: $nitEmisor,
                                fecha: FechaSiat::desde($fechaEmisionPaquete),
                                sucursal: 0,
                                modalidad: Modalidad::Computarizada,
                                tipoEmision: TipoEmision::FueraDeLinea,
                                tipoFactura: TipoFacturaDocumento::ConDerechoCreditoFiscal,
                                documentoSector: DocumentoSector::CompraVenta,
                                numeroFactura: $num,
                                puntoVenta: $pv,
                            );
                            $cuf = GeneradorCuf::generar($datosCuf, (string) ($eventoPv['codigoControl'] ?? $cufdFacturas->codigoControl));
                            $xml = $generadorXmlV2->generarRapido(
                                nitEmisor: $nitEmisor,
                                razonSocialEmisor: 'MOZISTOCK PRUEBAS',
                                municipio: 'La Paz',
                                telefono: '78082248',
                                numeroFactura: $num,
                                cuf: $cuf,
                                cufd: $cufdFacturas->codigo,
                                codigoSucursal: 0,
                                direccion: $cufdFacturas->direccion ?? 'Av. Principal #100',
                                codigoPuntoVenta: $pv,
                                fechaEmision: FechaSiat::desde($fechaEmisionPaquete)->xml(),
                                nombreRazonSocial: 'CONTROL TRIBUTARIO',
                                codigoTipoDocumentoIdentidad: 5,
                                numeroDocumento: '99002',
                                complemento: null,
                                codigoCliente: '99002',
                                codigoMetodoPago: 1,
                                numeroTarjeta: null,
                                montoTotal: '100.00',
                                montoTotalSujetoIva: '100.00',
                                codigoMoneda: 1,
                                tipoCambio: '1',
                                montoTotalMoneda: '100.00',
                                montoGiftCard: null,
                                descuentoAdicional: '0.00',
                                codigoExcepcion: 1,
                                cafc: null,
                                leyenda: self::LEYENDA_DEFECTO,
                                usuario: 'mozistock-cert',
                                codigoDocumentoSector: 1,
                                detalles: [
                                    [
                                        'actividadEconomica' => self::ACTIVIDAD_DEFECTO,
                                        'codigoProductoSin' => self::PRODUCTO_SIN_DEFECTO,
                                        'codigoProducto' => 'PROD-01',
                                        'descripcion' => 'SERVICIO DE PRUEBA CERTIFICACION V2',
                                        'cantidad' => '1.00',
                                        'unidadMedida' => self::UNIDAD_MEDIDA_DEFECTO,
                                        'precioUnitario' => '100.00',
                                        'montoDescuento' => null,
                                        'subTotal' => '100.00',
                                        'numeroSerie' => null,
                                        'numeroImei' => null,
                                    ],
                                ],
                            );
                            $xmlMap["factura_v2_{$pv}_{$num}.xml"] = $xml;
                        } else {
                            $factura = $this->armarFactura($pv, $num, $cufdFacturas, TipoEmision::FueraDeLinea, $fechaEmisionPaquete);
                            $preparada = $cliente->preparador()->preparar($factura);
                            $xmlMap["factura_{$pv}_{$num}.xml"] = $preparada->xml;
                        }
                    }

                    if (! $ensayo) {
                        $this->guardarEstado($estado);
                    }

                    $tarGz = Archivo::tarGz($xmlMap);
                    $hash = Archivo::sha256($tarGz);
                    $buildSec = round(microtime(true) - $startBuild, 2);
                    $tarKb = round(strlen($tarGz) / 1024, 1);
                    $this->line("   Empaquetado en {$buildSec}s ({$tarKb} KB, hash {$hash})");

                    if ($ensayo) {
                        $this->line("   [ENSAYO] Paquete PV {$pv} Evento {$motivo} #{$p} ({$facturasPorPaquete} facturas) · OK");
                        $this->registrarEvidencia('VI-paquetes.csv', [
                            $fechaHora, 'VI', $caso, 0, $pv, 'recepcionPaqueteFactura', '', '',
                            '901', 'true', 'REC-PAQ-ENSAYO', 'ENSAYO PAQUETE OK', 'ok', '-',
                        ]);
                        $this->registrarEvidencia('VI-paquetes.csv', [
                            $fechaHora, 'VI', $caso.' validacion', 0, $pv, 'validacionRecepcionPaqueteFactura', '', '',
                            '908', 'true', 'REC-PAQ-ENSAYO', 'ENSAYO VALIDACION OK', 'ok', '-',
                        ]);
                        $aprobadas += 2;

                        continue;
                    }

                    $contexto = new ContextoDocumento($punto, $cuis, $cufdTransmision, TipoEmision::FueraDeLinea);

                    $this->pausa();

                    try {
                        // Reintento en recepción si el SIN da error transitorio (408 RCV timeout o similar)
                        $intentosRec = 0;
                        $maxIntentosRec = 4;
                        $recepcion = null;
                        $okRecepcion = false;
                        $codRec = '';

                        while ($intentosRec < $maxIntentosRec) {
                            $intentosRec++;
                            try {
                                $recepcion = $cliente->compraVenta($contribuyente)->recepcionPaquete(
                                    contexto: $contexto,
                                    archivoTarGz: $tarGz,
                                    cantidadFacturas: $facturasPorPaquete,
                                    codigoEvento: (int) $codigoEvento,
                                );
                                $codRec = $recepcion->codigoRecepcion ?? '';
                                $okRecepcion = $recepcion->transaccion && $codRec !== '';
                                if ($okRecepcion) {
                                    break;
                                }
                                $msgRec = $this->mensajesTexto($recepcion->mensajes);
                                if ((str_contains($msgRec, '408') || str_contains($msgRec, 'espera') || str_contains($msgRec, 'RCV')) && $intentosRec < $maxIntentosRec) {
                                    $this->warn("   ↻ Recepción paquete con espera RCV (408), reintentando ({$intentosRec}/{$maxIntentosRec}) en 6s...");
                                    sleep(6);

                                    continue;
                                }
                                break;
                            } catch (\Throwable $e) {
                                if ($intentosRec < $maxIntentosRec) {
                                    $this->warn("   ↻ Excepción en recepción ({$e->getMessage()}), reintentando ({$intentosRec}/{$maxIntentosRec}) en 6s...");
                                    sleep(6);

                                    continue;
                                }
                                throw $e;
                            }
                        }

                        $this->registrarEvidencia('VI-paquetes.csv', [
                            $fechaHora, 'VI', $caso, 0, $pv, 'recepcionPaqueteFactura', '', '',
                            $recepcion !== null ? (string) $recepcion->codigoEstado : 'ERROR',
                            $recepcion !== null && $recepcion->transaccion ? 'true' : 'false',
                            $codRec, $recepcion !== null ? $this->mensajesTexto($recepcion->mensajes) : '', $okRecepcion ? 'ok' : 'falla', '-',
                        ]);

                        if (! $okRecepcion || $recepcion === null) {
                            $fallidas++;
                            $this->warn("   ✗ Recepción de paquete PV {$pv} falló: ".($recepcion !== null ? $this->mensajesTexto($recepcion->mensajes) : 'Sin respuesta'));

                            continue;
                        }

                        $aprobadas++;
                        $this->line("   ✓ Paquete PV {$pv} Evento {$motivo} #{$p} recibido con código {$codRec}");

                        // Pausa para dar tiempo al SIN a descomprimir e indexar las facturas
                        $this->line('   Esperando procesamiento del paquete en SIN...');
                        sleep($pv === 0 ? 2 : 5);

                        // Validar paquete con reintento si sigue en proceso (901) o demora RCV
                        $intentosVal = 0;
                        $maxIntentosVal = 8;
                        $okVal = false;
                        $validacion = null;
                        while ($intentosVal < $maxIntentosVal) {
                            $intentosVal++;
                            $this->pausa();
                            try {
                                $validacion = $cliente->compraVenta($contribuyente)->validacionRecepcionPaquete($contexto, $codRec);
                                $okVal = $validacion->validada() || $validacion->codigoEstado === 908;
                                if ($okVal) {
                                    break;
                                }

                                $msgVal = $this->mensajesTexto($validacion->mensajes);
                                if (($validacion->codigoEstado === 901 || str_contains($msgVal, '408') || str_contains($msgVal, 'espera') || str_contains($msgVal, 'PENDIENTE')) && $intentosVal < $maxIntentosVal) {
                                    $this->warn("   ↻ Validación paquete {$codRec} en proceso (estado {$validacion->codigoEstado}), reintentando ({$intentosVal}/{$maxIntentosVal}) en 6s...");
                                    sleep(6);

                                    continue;
                                }
                                break;
                            } catch (\Throwable $e) {
                                if ($intentosVal < $maxIntentosVal) {
                                    $this->warn("   ↻ Validación paquete excepción {$e->getMessage()}, reintentando ({$intentosVal}/{$maxIntentosVal}) en 6s...");
                                    sleep(6);

                                    continue;
                                }
                                break;
                            }
                        }

                        if ($okVal) {
                            $aprobadas++;
                            $this->line("   ✓ Paquete PV {$pv} Evento {$motivo} #{$p} validado (908)");
                        } else {
                            $fallidas++;
                            $this->warn("   ✗ Validación de paquete PV {$pv} falló: ".($validacion !== null ? $this->mensajesTexto($validacion->mensajes) : 'Sin respuesta'));
                        }

                        $this->registrarEvidencia('VI-paquetes.csv', [
                            $fechaHora, 'VI', $caso.' validacion', 0, $pv, 'validacionRecepcionPaqueteFactura', '', '',
                            $validacion !== null ? (string) $validacion->codigoEstado : 'ERROR',
                            $validacion !== null && $validacion->transaccion ? 'true' : 'false',
                            $codRec, $validacion !== null ? $this->mensajesTexto($validacion->mensajes) : '', $okVal ? 'ok' : 'falla', '-',
                        ]);
                    } catch (\Throwable $e) {
                        $fallidas++;
                        $this->error("   ✗ Paquete PV {$pv} excepción: {$e->getMessage()}");
                        $this->registrarEvidencia('VI-paquetes.csv', [
                            $fechaHora, 'VI', $caso, 0, $pv, 'recepcionPaqueteFactura', '', '',
                            'ERROR', 'false', '', $e->getMessage(), 'falla', '-',
                        ]);
                    }
                }
            }
        }

        $this->actualizarSeguimiento('VI', $aprobadas, $fallidas);

        return $fallidas === 0;
    }

    // =========================================================================
    // ETAPA IX: Masiva (Solo si aplica)
    // =========================================================================
    /**
     * @param  array<string, mixed>  $estado
     */
    private function etapaMasiva(ClienteSiat $cliente, Contribuyente $contribuyente, array &$estado): bool
    {
        $this->warn('La etapa IX (emisión masiva) no está declarada en la solicitud 9944. Solo se ejecuta si el portal Seguimiento la exige.');

        return true;
    }

    // =========================================================================
    // AUXILIARES DE FACTURA, ESTADO Y ARCHIVOS
    // =========================================================================

    private function armarFactura(int $pv, int $numero, Cufd $cufd, TipoEmision $tipoEmision, ?DateTimeImmutable $fechaEmision = null): FacturaCompraVenta
    {
        $fecha = $fechaEmision ?? new DateTimeImmutable('now', new DateTimeZone('America/La_Paz'));

        return new FacturaCompraVenta(
            nitEmisor: (int) config('siat.pruebas.nit'),
            razonSocialEmisor: 'MOZISTOCK PRUEBAS',
            municipio: 'La Paz',
            numeroFactura: $numero,
            punto: new PuntoEmision(0, $pv),
            direccion: $cufd->direccion ?? 'Av. Principal #100',
            fechaEmision: FechaSiat::desde($fecha),
            cufd: $cufd,
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
            leyenda: self::LEYENDA_DEFECTO,
            usuario: 'mozistock-cert',
            detalles: [
                new DetalleCompraVenta(
                    actividadEconomica: self::ACTIVIDAD_DEFECTO,
                    codigoProductoSin: self::PRODUCTO_SIN_DEFECTO,
                    codigoProducto: 'ITEM-1',
                    descripcion: 'Servicio de prueba de certificacion',
                    cantidad: '1.00',
                    unidadMedida: self::UNIDAD_MEDIDA_DEFECTO,
                    precioNetoUnitario: '100.00',
                    subTotal: '100.00',
                ),
            ],
            nombreRazonSocial: 'CONTROL TRIBUTARIO',
            codigoExcepcion: 1,
            tipoEmision: $tipoEmision,
        );
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    private function obtenerCuis(ClienteSiat $cliente, Contribuyente $contribuyente, PuntoEmision $punto, array &$estado, bool $ensayo): string
    {
        $pv = (string) $punto->puntoVenta;
        $cuis = $estado['cuis'][$pv] ?? $estado['cuis'][(int) $pv] ?? null;

        if (($cuis === null || $cuis === '' || $cuis === 'CUIS-ENSAYO') && ! $ensayo) {
            $respCuis = $cliente->codigos($contribuyente)->cuis($punto);
            $cuis = $respCuis->codigo;
            $estado['cuis'][$pv] = $cuis;
            $this->guardarEstado($estado);
        }

        return $cuis ?? 'CUIS-ENSAYO';
    }

    private function ocultar(string $texto): string
    {
        $token = (string) config('siat.pruebas.token');
        if ($token === '') {
            return $texto;
        }

        return str_replace($token, '[TOKEN OCULTO]', $texto);
    }

    private function asegurarDirectorios(): void
    {
        $rutas = [
            storage_path('app/private/siat-certificacion'),
            storage_path('app/private/siat-certificacion/xml'),
            storage_path('app/private/siat-certificacion/respuestas'),
            base_path('../docs/evidencias/siat-certificacion'),
        ];

        foreach ($rutas as $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

        }

    }

    private function rutaEstado(): string
    {
        $sufijo = $this->option('v2') ? '_v2' : '';
        $archivo = app()->environment('testing') ? 'estado_testing'.$sufijo.'.json' : 'estado'.$sufijo.'.json';

        return storage_path('app/private/siat-certificacion/'.$archivo);
    }

    /**
     * @return array<string, mixed>
     */
    private function cargarEstado(): array
    {
        $ruta = $this->rutaEstado();
        if (file_exists($ruta)) {
            $contenido = file_get_contents($ruta);
            $json = json_decode((string) $contenido, true);
            if (is_array($json)) {
                return $json;
            }

        }

        return [
            'ultimo_numero' => [
                '0' => self::NUMERO_INICIAL,
                '1' => self::NUMERO_INICIAL,
            ],
            'cuis' => [
                '0' => null,
                '1' => null,
            ],
            'cufd' => [
                '0' => null,
                '1' => null,
            ],
            'facturas_emitidas' => [],
            'facturas_anuladas' => [],
            'facturas_revertidas' => [],
            'eventos' => [],
            'paquetes' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    private function guardarEstado(array $estado): void
    {
        $ruta = $this->rutaEstado();
        $this->asegurarDirectorios();
        $json = json_encode($estado, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($ruta, $this->ocultar((string) $json));
    }

    /**
     * @param  list<mixed>  $fila
     */
    private function registrarEvidencia(string $archivoCsv, array $fila): void
    {
        if ((bool) $this->option('ensayo')) {
            return;
        }

        if ((bool) $this->option('v2')) {
            $archivoCsv = str_replace('.csv', '-v2.csv', $archivoCsv);
        }

        $ruta = base_path('../docs/evidencias/siat-certificacion/'.$archivoCsv);
        $existe = file_exists($ruta);
        $dir = dirname($ruta);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $handle = fopen($ruta, 'a');
        if ($handle === false) {
            return;
        }

        if (! $existe) {
            fputcsv($handle, [
                'fecha_hora', 'etapa', 'caso', 'sucursal', 'punto_venta', 'operacion',
                'numero_factura', 'cuf_o_codigo', 'codigo_estado', 'transaccion',
                'codigo_recepcion', 'mensajes', 'resultado', 'evidencia',
            ]);
        }

        $sanitizado = array_map(function ($col) {
            $str = (string) ($col ?? '');

            return $this->ocultar(str_replace(["\r", "\n"], ' ', $str));
        }, $fila);

        fputcsv($handle, $sanitizado);
        fclose($handle);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function registrarRespuestaBruta(string $etapa, array $datos): void
    {
        if ((bool) $this->option('ensayo')) {
            return;
        }

        if ((bool) $this->option('v2')) {
            $etapa = "{$etapa}-v2";
        }

        $dir = storage_path('app/private/siat-certificacion/respuestas');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $ruta = "{$dir}/{$etapa}.jsonl";
        $linea = $this->ocultar((string) json_encode($datos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).PHP_EOL;
        file_put_contents($ruta, $linea, FILE_APPEND);
    }

    private function actualizarSeguimiento(string $etapa, int $aprobadas, int $fallidas): void
    {
        if ((bool) $this->option('ensayo')) {
            return;
        }

        $archivo = (bool) $this->option('v2') ? 'seguimiento_v2.csv' : 'seguimiento.csv';
        $ruta = base_path('../docs/evidencias/siat-certificacion/'.$archivo);
        if (! file_exists($ruta)) {
            return;
        }

        $lineas = file($ruta);
        if ($lineas === false || $lineas === []) {
            return;
        }

        $filas = array_map(str_getcsv(...), $lineas);
        $header = array_shift($filas);
        $idxEtapa = array_search('etapa', $header, true);
        $idxAprobadas = array_search('aprobadas', $header, true);
        $idxFallidas = array_search('fallidas', $header, true);
        $idxRevision = array_search('ultima_revision', $header, true);

        if ($idxEtapa === false || $idxAprobadas === false || $idxFallidas === false) {
            return;
        }

        $fechaHoy = now('America/La_Paz')->format('Y-m-d H:i');

        foreach ($filas as &$fila) {
            if (isset($fila[$idxEtapa]) && strcasecmp($fila[$idxEtapa], $etapa) === 0) {
                $fila[$idxAprobadas] = (string) (((int) ($fila[$idxAprobadas] ?? 0)) + $aprobadas);
                $fila[$idxFallidas] = (string) (((int) ($fila[$idxFallidas] ?? 0)) + $fallidas);
                if ($idxRevision !== false) {
                    $fila[$idxRevision] = $fechaHoy;
                }
            }
        }

        unset($fila);

        $fp = fopen($ruta, 'w');
        if ($fp !== false) {
            fputcsv($fp, $header);
            foreach ($filas as $fila) {
                fputcsv($fp, $fila);
            }

            fclose($fp);
        }

    }

    /**
     * @param  list<Mensaje>  $mensajes
     */
    private function mensajesTexto(array $mensajes): string
    {
        if ($mensajes === []) {
            return '—';
        }

        return implode('; ', array_map(strval(...), $mensajes));
    }
}
