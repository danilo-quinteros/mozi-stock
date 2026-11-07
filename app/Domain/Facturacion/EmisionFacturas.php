<?php

namespace App\Domain\Facturacion;

use App\Domain\Auditoria\Auditor;
use App\Domain\Facturacion\Firma\FirmadorXmlDSig;
use App\Domain\Tenancy\Numerador;
use App\Enums\EstadoFactura;
use App\Enums\ModalidadFacturacion;
use App\Models\SiatConfiguracion;
use App\Models\SiatCufd;
use App\Models\SiatFactura;
use App\Models\SiatPuntoVenta;
use App\Models\SiatSucursal;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MoziStudios\Siat\Algoritmos\Archivo;
use MoziStudios\Siat\ClienteSiat;
use MoziStudios\Siat\Codigos\Cufd;
use MoziStudios\Siat\Configuracion\PuntoEmision;
use MoziStudios\Siat\Excepciones\DatosInvalidos;
use MoziStudios\Siat\Excepciones\ErrorComunicacion;
use MoziStudios\Siat\Excepciones\XmlInvalido;
use MoziStudios\Siat\Factura\DetalleCompraVenta;
use MoziStudios\Siat\Factura\FacturaCompraVenta;
use MoziStudios\Siat\Factura\FacturaPreparada;
use MoziStudios\Siat\Fecha\FechaSiat;
use MoziStudios\Siat\Respuestas\RespuestaRecepcion;
use MoziStudios\Siat\Servicios\ContextoDocumento;
use MoziStudios\Siat\Servicios\ServicioCompraVenta;

/**
 * Envío de facturas al SIN, siempre fuera de transacciones de venta, caja o stock y bajo un bloqueo
 * por factura. La factura se prepara UNA vez (número, fecha, CUFD, CUF y XML se guardan antes de
 * enviar); los reintentos reenvían ese mismo XML y, si ya se había intentado, primero consultan su
 * estado para no duplicarla. Nunca escribe en ventas, pagos_venta, movimientos_caja ni stock.
 */
final class EmisionFacturas
{
    /** Pausa antes de volver a consultar una factura que el SIN dejó pendiente de validación (901). */
    public const SEGUNDOS_VERIFICACION = 30;

    /** Estados con los que la verificación confirma que el SIN ya tiene la factura. */
    private const ESTADOS_CONOCIDOS = [
        RespuestaRecepcion::ESTADO_PENDIENTE,
        RespuestaRecepcion::ESTADO_OBSERVADA,
        RespuestaRecepcion::ESTADO_ANULACION_CONFIRMADA,
        RespuestaRecepcion::ESTADO_VALIDADA,
    ];

    public function __construct(
        private readonly ClienteSiat $cliente,
        private readonly CodigosSiat $codigos,
        private readonly Numerador $numerador,
        private readonly Auditor $auditor,
        private readonly FirmadorXmlDSig $firmador = new FirmadorXmlDSig,
    ) {}

    /**
     * Envía o verifica una factura pendiente. Devuelve true si el SIN todavía la está validando (901)
     * y conviene consultarla otra vez más tarde.
     *
     * @throws ErrorComunicacion sin respuesta del SIN: la factura sigue pendiente y el job reintenta
     */
    public function procesar(int $empresaId, int $facturaId): bool
    {
        $bloqueo = Cache::lock("siat:factura:{$facturaId}", 120);

        // Otro proceso ya la está enviando: ese proceso deja el resultado.
        if (! $bloqueo->get()) {
            return false;
        }

        try {
            $factura = DeEmpresa::consulta(SiatFactura::class, $empresaId)->find($facturaId);

            if ($factura === null || $factura->estado !== EstadoFactura::Pendiente) {
                return false;
            }

            return $this->enviar($factura);
        } finally {
            $bloqueo->release();
        }
    }

    /**
     * Servicio del contribuyente de la empresa y contexto (punto, CUIS, CUFD vigente) para operar
     * sobre una factura ya emitida: verificar, anular o revertir.
     *
     * @return array{0: ServicioCompraVenta, 1: ContextoDocumento}
     *
     * @throws ErrorFacturacion
     */
    public function operacion(SiatFactura $factura): array
    {
        [$punto] = $this->punto($factura);
        [$contribuyente, $emision] = $this->codigos->preparar($punto);
        $cuis = $this->codigos->cuis($punto);
        $cufd = $this->codigos->cufdVigente($punto) ?? $this->codigos->renovarCufd($punto);
        $cliente = $this->clientePara($factura->empresa_id);

        return [$cliente->compraVenta($contribuyente), new ContextoDocumento($emision, $cuis->codigo, $cufd->codigo)];
    }

    /**
     * Ejecuta $accion con la factura bloqueada (anular, revertir): espera hasta 20 s a que termine un envío.
     *
     * @template T
     *
     * @param  callable(): T  $accion
     * @return T
     */
    public function conFacturaBloqueada(int $facturaId, callable $accion): mixed
    {
        try {
            return Cache::lock("siat:factura:{$facturaId}", 120)->block(20, $accion);
        } catch (LockTimeoutException) {
            throw new ErrorFacturacion(__('La factura se está enviando al SIN. Intenta de nuevo en unos segundos.'));
        }
    }

    /**
     * @return list<array{codigo: int, descripcion: string}>
     */
    public static function mensajes(RespuestaRecepcion $respuesta): array
    {
        return array_map(fn ($m): array => ['codigo' => $m->codigo, 'descripcion' => $m->descripcion], $respuesta->mensajes);
    }

    private function enviar(SiatFactura $factura): bool
    {
        try {
            [$punto, $sucursal] = $this->punto($factura);
            [$contribuyente, $emision] = $this->codigos->preparar($punto);
            $cuis = $this->codigos->cuis($punto);
            $servicio = $this->clientePara($factura->empresa_id)->compraVenta($contribuyente);

            if ($factura->enviada_en !== null && $factura->cuf !== null) {
                // Ya se intentó enviar: si el SIN la tiene, solo se toma su estado (nunca se envía dos veces).
                $cufd = $this->codigos->cufdVigente($punto) ?? $this->codigos->renovarCufd($punto);
                $estado = $servicio->verificacionEstado(new ContextoDocumento($emision, $cuis->codigo, $cufd->codigo), $factura->cuf);

                if (in_array($estado->codigoEstado, self::ESTADOS_CONOCIDOS, true)) {
                    return $this->aplicar($factura, $estado);
                }
            }

            $preparada = $factura->xml === null ? $this->preparar($factura, $punto, $sucursal) : $this->reconstruir($factura, $punto, $sucursal);
            $factura->enviada_en = Carbon::now();
            $factura->intentos++;
            $factura->save();

            $respuesta = $servicio->recepcionFactura($preparada, $cuis->codigo);

            // Si el SIN rechaza porque el CUFD o CUIS enviado es inválido (1003 CUFD inválido, 1006 CUFD no corresponde, 913 CUIS inválido)
            // se invalida el código desactualizado, se solicita uno nuevo al SIN y se reintenta inmediatamente (1 solo reintento automático)
            $mensajesCodigos = array_map(fn ($m) => $m->codigo, $respuesta->mensajes);
            $esCufdInvalido = in_array(1003, $mensajesCodigos, true) || in_array(1006, $mensajesCodigos, true);
            $esCuisInvalido = in_array(913, $mensajesCodigos, true);

            if (($esCufdInvalido || $esCuisInvalido) && $factura->intentos <= 2) {
                if ($esCuisInvalido) {
                    $cuis = $this->codigos->cuis($punto, forzar: true);
                }
                if ($esCufdInvalido) {
                    $this->codigos->invalidarCufdVigente($punto);
                    $this->codigos->renovarCufd($punto, forzar: true);
                }
                $factura->xml = null;
                $factura->cuf = null;
                $factura->siat_cufd_id = null;
                $preparada = $this->preparar($factura, $punto, $sucursal);
                $factura->intentos++;
                $factura->save();

                $respuesta = $servicio->recepcionFactura($preparada, $cuis->codigo);
            }

            return $this->aplicar($factura, $respuesta);
        } catch (ErrorComunicacion $error) {
            $factura->motivo = Str::limit(__('Sin respuesta del SIN: :error. Se reintentará automáticamente.', ['error' => $error->getMessage()]), 1000, '');
            $factura->save();

            throw $error;
        } catch (ErrorFacturacion|DatosInvalidos|XmlInvalido $error) {
            $factura->estado = EstadoFactura::Bloqueada;
            $factura->motivo = Str::limit($error->getMessage(), 1000, '');
            $factura->save();
            $this->auditor->registrar('siat.factura.bloqueada', $factura, null, $factura->empresa_id, despues: ['motivo' => $factura->motivo]);

            return false;
        }
    }

    /**
     * Número, fecha, CUFD vigente, leyenda, CUF y XML; todo se guarda antes de enviar.
     */
    private function preparar(SiatFactura $factura, SiatPuntoVenta $punto, SiatSucursal $sucursal): FacturaPreparada
    {
        $configuracion = DeEmpresa::consulta(SiatConfiguracion::class, $factura->empresa_id)->firstOrFail();
        $modalidad = $configuracion->modalidad ?? ModalidadFacturacion::Computarizada;
        $cliente = $this->cliente->conModalidad($modalidad->siat());

        $cufd = $this->codigos->cufdVigente($punto) ?? $this->codigos->renovarCufd($punto);

        if ($factura->numero === null) {
            $factura->numero = DB::transaction(fn (): int => $this->numerador->siguiente($factura->empresa_id, 'F'.$punto->id));
            $factura->save();
        }

        $actividad = (string) ($factura->lineas[0]['actividad'] ?? '');
        $leyenda = CatalogoEmpresa::leyenda($factura->empresa_id, $actividad, $factura->numero)
            ?? throw new ErrorFacturacion(__('No hay leyendas del SIN para la actividad :actividad: sincroniza los catálogos.', ['actividad' => $actividad]));
        $fecha = FechaSiat::desde(new \DateTimeImmutable('now', new \DateTimeZone((string) config('mozistock.business_timezone'))));
        $documento = $this->documento($factura, $punto, $sucursal, $cufd, $fecha, $leyenda);
        $preparada = $cliente->preparador()->preparar($documento);

        // Si la empresa factura en modalidad electrónica, firmar con XMLDSig (Etapa VIII)
        if ($modalidad === ModalidadFacturacion::Electronica) {
            if (! $configuracion->tieneCertificado()) {
                throw new ErrorFacturacion(__('La empresa está configurada en modalidad electrónica pero no tiene cargado un certificado digital .p12.'));
            }
            if ($configuracion->certificadoVencido()) {
                throw new ErrorFacturacion(__('El certificado digital .p12 está vencido. Carga un certificado vigente en Datos fiscales.'));
            }

            $p12Path = (string) $configuracion->certificadoRutaAbsoluta();
            $p12Pass = (string) $configuracion->certificadoPasswordDescifrado();

            $xmlFirmado = $this->firmador->firmar($preparada->xml, $p12Path, $p12Pass);
            $archivoFirmado = Archivo::comprimir($xmlFirmado);
            $preparada = new FacturaPreparada($documento, $preparada->cuf, $xmlFirmado, $archivoFirmado, Archivo::sha256($archivoFirmado));
        }

        $factura->siat_cufd_id = $cufd->id;
        $factura->leyenda = $leyenda;
        $factura->fecha_emision = CodigosSiat::enZonaApp($fecha->momento());
        $factura->cuf = $preparada->cuf;
        $factura->xml = $preparada->xml;
        $factura->save();

        return $preparada;
    }

    /**
     * La misma factura ya preparada, para reenviarla: mismo XML, mismo CUF.
     */
    private function reconstruir(SiatFactura $factura, SiatPuntoVenta $punto, SiatSucursal $sucursal): FacturaPreparada
    {
        $cufd = DeEmpresa::consulta(SiatCufd::class, $factura->empresa_id)->findOrFail($factura->siat_cufd_id);
        $fecha = FechaSiat::desde(($factura->fecha_emision ?? throw new ErrorFacturacion(__('La factura preparada no tiene fecha de emisión.')))
            ->copy()->timezone((string) config('mozistock.business_timezone')));
        $documento = $this->documento($factura, $punto, $sucursal, $cufd, $fecha, (string) $factura->leyenda);
        $xml = (string) $factura->xml;
        $archivo = Archivo::comprimir($xml);

        return new FacturaPreparada($documento, (string) $factura->cuf, $xml, $archivo, Archivo::sha256($archivo));
    }

    private function documento(SiatFactura $factura, SiatPuntoVenta $punto, SiatSucursal $sucursal, SiatCufd $cufd, FechaSiat $fecha, string $leyenda): FacturaCompraVenta
    {
        $configuracion = DeEmpresa::consulta(SiatConfiguracion::class, $factura->empresa_id)->firstOrFail();
        $usuario = User::query()->whereKey($factura->emitida_por)->value('name');

        if ($factura->lineas === null || $factura->total_precio_neto === null || $factura->numero === null || $factura->metodo_pago === null) {
            throw new ErrorFacturacion(__('La factura no tiene montos calculados: reintenta para recalcularla.'));
        }

        $detalles = array_map(fn (array $l): DetalleCompraVenta => new DetalleCompraVenta(
            $l['actividad'], $l['producto_sin'], $l['codigo_producto'], $l['descripcion'], $l['cantidad'],
            $l['unidad_medida'], $l['precio_neto_unitario'], $l['sub_total'], $l['monto_descuento'],
        ), $factura->lineas);

        return new FacturaCompraVenta(
            nitEmisor: (int) $configuracion->nit,
            razonSocialEmisor: (string) $configuracion->razon_social,
            municipio: $sucursal->municipio,
            numeroFactura: $factura->numero,
            punto: new PuntoEmision($sucursal->codigo, $punto->codigo),
            direccion: (string) ($cufd->direccion ?? throw new ErrorFacturacion(__('El CUFD no trae la dirección de la sucursal: renueva el CUFD.'))),
            fechaEmision: $fecha,
            cufd: new Cufd($cufd->codigo, $cufd->codigo_control, $cufd->codigo_interno, $cufd->direccion),
            codigoTipoDocumentoIdentidad: $factura->tipo_documento,
            numeroDocumento: $factura->numero_documento,
            codigoCliente: $factura->codigo_cliente,
            codigoMetodoPago: $factura->metodo_pago,
            subTotalNeto: (string) $factura->sub_total_neto,
            totalPrecioNeto: $factura->total_precio_neto,
            iva: (string) $factura->iva,
            montoaPagar: $factura->total,
            // Bolivianos (código 1 de la paramétrica de monedas): MoziStock no vende en moneda extranjera (decisión 3).
            codigoMoneda: 1,
            tipoCambio: '1',
            montoTotalMoneda: $factura->total,
            leyenda: $leyenda,
            usuario: Str::limit(is_string($usuario) && $usuario !== '' ? $usuario : 'MoziStock', 100, ''),
            detalles: $detalles,
            telefono: $sucursal->telefono,
            nombreRazonSocial: $factura->razon_social,
            complemento: $factura->complemento,
            numeroTarjeta: $factura->numero_tarjeta,
            descuentoAdicional: $factura->descuento_adicional,
            codigoExcepcion: $factura->codigo_excepcion,
        );
    }

    /**
     * Guarda la respuesta del SIN. Devuelve true si sigue pendiente de validación (901).
     */
    private function aplicar(SiatFactura $factura, RespuestaRecepcion $respuesta): bool
    {
        $factura->codigo_estado = $respuesta->codigoEstado;
        $factura->codigo_recepcion = $respuesta->codigoRecepcion ?? $factura->codigo_recepcion;
        $factura->mensajes = self::mensajes($respuesta);
        $detalle = implode('; ', array_map(strval(...), $respuesta->mensajes));
        $codigo = $respuesta->codigoDescripcion ?? ($respuesta->codigoEstado === null ? '—' : (string) $respuesta->codigoEstado);

        [$estado, $motivo] = match ($respuesta->codigoEstado) {
            RespuestaRecepcion::ESTADO_VALIDADA => [EstadoFactura::Validada, null],
            RespuestaRecepcion::ESTADO_PENDIENTE => [EstadoFactura::Pendiente, __('El SIN está validando la factura.')],
            RespuestaRecepcion::ESTADO_OBSERVADA => [EstadoFactura::Observada, __('El SIN observó la factura: no es válida. :detalle', ['detalle' => $detalle])],
            RespuestaRecepcion::ESTADO_ANULACION_CONFIRMADA => [EstadoFactura::Anulada, __('El SIN informa que la factura está anulada.')],
            default => [EstadoFactura::Rechazada, __('El SIN rechazó la factura (:estado). :detalle', ['estado' => $codigo, 'detalle' => $detalle])],
        };

        $factura->estado = $estado;
        $factura->motivo = is_string($motivo) ? Str::limit(trim($motivo), 1000, '') : null;
        $factura->validada_en = $estado === EstadoFactura::Validada ? Carbon::now() : $factura->validada_en;

        if ($estado === EstadoFactura::Anulada) {
            $factura->venta_vigente = null;
            $factura->anulada_en ??= Carbon::now();
        }

        $factura->save();

        if ($estado !== EstadoFactura::Pendiente) {
            $this->auditor->registrar('siat.factura.'.$estado->value, $factura, null, $factura->empresa_id, despues: [
                'numero' => $factura->numero, 'cuf' => $factura->cuf, 'codigo_estado' => $factura->codigo_estado, 'mensajes' => $factura->mensajes,
            ]);
        }

        return $estado === EstadoFactura::Pendiente;
    }

    /**
     * Punto de venta y sucursal SIN de la factura, con la empresa explícita (en un job no hay contexto de empresa).
     *
     * @return array{0: SiatPuntoVenta, 1: SiatSucursal}
     */
    private function punto(SiatFactura $factura): array
    {
        $punto = $factura->siat_punto_venta_id === null ? null
            : DeEmpresa::consulta(SiatPuntoVenta::class, $factura->empresa_id)->find($factura->siat_punto_venta_id);
        $sucursal = $punto === null ? null : DeEmpresa::consulta(SiatSucursal::class, $factura->empresa_id)->find($punto->siat_sucursal_id);

        if ($punto === null || $sucursal === null) {
            throw new ErrorFacturacion(__('La factura no tiene un punto de venta del SIN.'));
        }

        return [$punto, $sucursal];
    }

    private function clientePara(int $empresaId): ClienteSiat
    {
        $configuracion = DeEmpresa::consulta(SiatConfiguracion::class, $empresaId)->first();
        $modalidadSiat = ($configuracion?->modalidad ?? ModalidadFacturacion::Computarizada)->siat();

        return $this->cliente->conModalidad($modalidadSiat);
    }
}
