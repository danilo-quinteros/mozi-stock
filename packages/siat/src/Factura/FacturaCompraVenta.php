<?php

namespace MoziStudios\Siat\Factura;

use MoziStudios\Siat\Algoritmos\Decimal;
use MoziStudios\Siat\Codigos\Cufd;
use MoziStudios\Siat\Configuracion\PuntoEmision;
use MoziStudios\Siat\Constantes\TipoEmision;
use MoziStudios\Siat\Excepciones\DatosInvalidos;
use MoziStudios\Siat\Fecha\FechaSiat;

/**
 * Factura computarizada compra-venta (documento sector 1) según el XSD V3 con IVA transparente:
 * subTotalNeto, totalPrecioNeto, iva, montoaPagar y codigoInterno. Recibe montos ya calculados;
 * VerificadorMontos comprueba las fórmulas oficiales y el XSD se valida al preparar la factura.
 */
final class FacturaCompraVenta
{
    /** Paramétrica de tipos de documento de identidad (anexo): 1 = CI, 5 = NIT; ambos deben ser numéricos. */
    private const DOCUMENTOS_NUMERICOS = [1, 5];

    private const DOCUMENTO_NIT = 5;

    /** Códigos especiales: consulados (99001), control tributario (99002) y ventas menores del día (99003). */
    private const CODIGOS_ESPECIALES = ['99001', '99002', '99003'];

    public readonly string $razonSocialEmisor;

    public readonly string $municipio;

    public readonly ?string $telefono;

    public readonly string $direccion;

    public readonly ?string $nombreRazonSocial;

    public readonly string $numeroDocumento;

    public readonly string $complemento;

    public readonly string $codigoCliente;

    public readonly ?string $numeroTarjeta;

    public readonly string $subTotalNeto;

    public readonly string $totalPrecioNeto;

    public readonly string $iva;

    public readonly string $montoaPagar;

    public readonly string $tipoCambio;

    public readonly string $montoTotalMoneda;

    public readonly ?string $montoGiftCard;

    public readonly ?string $descuentoAdicional;

    public readonly string $leyenda;

    public readonly string $usuario;

    public readonly string $codigoInterno;

    /** @var list<DetalleCompraVenta> */
    public readonly array $detalles;

    /**
     * @param  list<DetalleCompraVenta>  $detalles
     * @param  string|null  $complemento  complemento SEGIP del CI; sin complemento se envía «0» (anexo «Emisión y envío»)
     * @param  int|null  $codigoExcepcion  0 por defecto; 1 solo para que el SIN no valide un NIT
     */
    public function __construct(
        public readonly int $nitEmisor,
        string $razonSocialEmisor,
        string $municipio,
        public readonly int $numeroFactura,
        public readonly PuntoEmision $punto,
        string $direccion,
        public readonly FechaSiat $fechaEmision,
        public readonly Cufd $cufd,
        public readonly int $codigoTipoDocumentoIdentidad,
        string $numeroDocumento,
        string $codigoCliente,
        public readonly int $codigoMetodoPago,
        string $subTotalNeto,
        string $totalPrecioNeto,
        string $iva,
        string $montoaPagar,
        public readonly int $codigoMoneda,
        string $tipoCambio,
        string $montoTotalMoneda,
        string $leyenda,
        string $usuario,
        array $detalles,
        ?string $telefono = null,
        ?string $nombreRazonSocial = null,
        ?string $complemento = null,
        ?string $numeroTarjeta = null,
        ?string $montoGiftCard = null,
        ?string $descuentoAdicional = null,
        public readonly ?int $codigoExcepcion = 0,
        public readonly TipoEmision $tipoEmision = TipoEmision::EnLinea,
    ) {
        $this->razonSocialEmisor = Texto::requerido($razonSocialEmisor, 'razonSocialEmisor');
        $this->municipio = Texto::requerido($municipio, 'municipio');
        $this->telefono = Texto::opcional($telefono, 'telefono');
        $this->direccion = Texto::requerido($direccion, 'direccion');
        $this->nombreRazonSocial = Texto::opcional($nombreRazonSocial, 'nombreRazonSocial');
        $this->numeroDocumento = Texto::requerido($numeroDocumento, 'numeroDocumento');
        $this->complemento = Texto::opcional($complemento, 'complemento') ?? '0';
        $this->codigoCliente = Texto::requerido($codigoCliente, 'codigoCliente');
        $this->numeroTarjeta = Texto::opcional($numeroTarjeta, 'numeroTarjeta');
        $this->subTotalNeto = Decimal::normalizar($subTotalNeto, 2, 'subTotalNeto');
        $this->totalPrecioNeto = Decimal::normalizar($totalPrecioNeto, 2, 'totalPrecioNeto');
        $this->iva = Decimal::normalizar($iva, 2, 'iva');
        $this->montoaPagar = Decimal::normalizar($montoaPagar, 2, 'montoaPagar');
        $this->tipoCambio = Decimal::normalizar($tipoCambio, 2, 'tipoCambio');
        $this->montoTotalMoneda = Decimal::normalizar($montoTotalMoneda, 2, 'montoTotalMoneda');
        $this->montoGiftCard = $montoGiftCard === null ? null : Decimal::normalizar($montoGiftCard, 2, 'montoGiftCard');
        $this->descuentoAdicional = $descuentoAdicional === null ? null : Decimal::normalizar($descuentoAdicional, 2, 'descuentoAdicional');
        $this->leyenda = Texto::requerido($leyenda, 'leyenda');
        $this->usuario = Texto::requerido($usuario, 'usuario');

        if ($cufd->codigoInterno === null || trim($cufd->codigoInterno) === '') {
            throw new DatosInvalidos('El CUFD no trae codigoInterno: solicita un CUFD con los servicios V3.');
        }

        $this->codigoInterno = trim($cufd->codigoInterno);

        if ($detalles === [] || count($detalles) > 500) {
            throw new DatosInvalidos('La factura debe tener entre 1 y 500 líneas de detalle.');
        }

        $this->detalles = $detalles;

        if ($this->numeroTarjeta !== null && preg_match('/^\d{4}0{8}\d{4}$/', $this->numeroTarjeta) !== 1) {
            throw new DatosInvalidos('numeroTarjeta debe ir ofuscado: 4 dígitos, 8 ceros y 4 dígitos (usa NumeroTarjeta::ofuscado).');
        }

        if (in_array($codigoTipoDocumentoIdentidad, self::DOCUMENTOS_NUMERICOS, true) && ! ctype_digit($this->numeroDocumento)) {
            throw new DatosInvalidos('Con CI o NIT el número de documento debe ser numérico.');
        }

        if (in_array($this->numeroDocumento, self::CODIGOS_ESPECIALES, true)
            && ($codigoTipoDocumentoIdentidad !== self::DOCUMENTO_NIT || $codigoExcepcion !== 1)) {
            throw new DatosInvalidos('Los códigos especiales 99001, 99002 y 99003 van con tipo de documento NIT y codigoExcepcion 1.');
        }

        if ($codigoExcepcion !== null && $codigoExcepcion !== 0 && $codigoExcepcion !== 1) {
            throw new DatosInvalidos('codigoExcepcion solo admite 0 o 1.');
        }

        // Fuera de línea el SIN no puede validar el NIT en el momento: el anexo exige excepción 1.
        if ($tipoEmision === TipoEmision::FueraDeLinea && $codigoTipoDocumentoIdentidad === self::DOCUMENTO_NIT && $codigoExcepcion !== 1) {
            throw new DatosInvalidos('Una factura fuera de línea a un NIT va con codigoExcepcion 1.');
        }
    }
}
