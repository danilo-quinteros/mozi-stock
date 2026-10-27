<?php

namespace MoziStudios\Siat\Factura;

use DOMDocument;
use DOMElement;
use DOMText;
use MoziStudios\Siat\Excepciones\DatosInvalidos;

/**
 * Generador XML de facturaComputarizadaCompraVenta para la versión anterior (V2 del SIN),
 * sin desglose de IVA neto ni codigoInterno.
 */
final class GeneradorXmlCompraVentaV2
{
    private const XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    /**
     * @param array<int, array{
     *     actividadEconomica: string,
     *     codigoProductoSin: int|string,
     *     codigoProducto: string,
     *     descripcion: string,
     *     cantidad: string,
     *     unidadMedida: int,
     *     precioUnitario: string,
     *     montoDescuento: ?string,
     *     subTotal: string,
     *     numeroSerie: ?string,
     *     numeroImei: ?string
     * }> $detalles
     */
    public function generar(
        int $nitEmisor,
        string $razonSocialEmisor,
        string $municipio,
        ?string $telefono,
        int $numeroFactura,
        string $cuf,
        string $cufd,
        int $codigoSucursal,
        string $direccion,
        int $codigoPuntoVenta,
        string $fechaEmision,
        ?string $nombreRazonSocial,
        int $codigoTipoDocumentoIdentidad,
        string $numeroDocumento,
        ?string $complemento,
        string $codigoCliente,
        int $codigoMetodoPago,
        ?string $numeroTarjeta,
        string $montoTotal,
        string $montoTotalSujetoIva,
        int $codigoMoneda,
        string $tipoCambio,
        string $montoTotalMoneda,
        ?string $montoGiftCard,
        string $descuentoAdicional,
        ?int $codigoExcepcion,
        ?string $cafc,
        string $leyenda,
        string $usuario,
        int $codigoDocumentoSector,
        array $detalles
    ): string {
        $documento = new DOMDocument('1.0', 'UTF-8');
        $raiz = $documento->createElement('facturaComputarizadaCompraVenta');
        $raiz->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', self::XSI);
        $raiz->setAttributeNS(self::XSI, 'xsi:noNamespaceSchemaLocation', 'facturaComputarizadaCompraVenta.xsd');
        $documento->appendChild($raiz);

        $cabecera = $this->hijo($raiz, 'cabecera');
        $campos = [
            'nitEmisor' => $nitEmisor,
            'razonSocialEmisor' => $razonSocialEmisor,
            'municipio' => $municipio,
            'telefono' => $telefono,
            'numeroFactura' => $numeroFactura,
            'cuf' => $cuf,
            'cufd' => $cufd,
            'codigoSucursal' => $codigoSucursal,
            'direccion' => $direccion,
            'codigoPuntoVenta' => $codigoPuntoVenta,
            'fechaEmision' => $fechaEmision,
            'nombreRazonSocial' => $nombreRazonSocial,
            'codigoTipoDocumentoIdentidad' => $codigoTipoDocumentoIdentidad,
            'numeroDocumento' => $numeroDocumento,
            'complemento' => $complemento,
            'codigoCliente' => $codigoCliente,
            'codigoMetodoPago' => $codigoMetodoPago,
            'numeroTarjeta' => $numeroTarjeta,
            'montoTotal' => $montoTotal,
            'montoTotalSujetoIva' => $montoTotalSujetoIva,
            'codigoMoneda' => $codigoMoneda,
            'tipoCambio' => $tipoCambio,
            'montoTotalMoneda' => $montoTotalMoneda,
            'montoGiftCard' => $montoGiftCard,
            'descuentoAdicional' => $descuentoAdicional,
            'codigoExcepcion' => $codigoExcepcion,
            'cafc' => $cafc,
            'leyenda' => $leyenda,
            'usuario' => $usuario,
            'codigoDocumentoSector' => $codigoDocumentoSector,
        ];

        foreach ($campos as $nombre => $valor) {
            $this->campo($cabecera, $nombre, $valor);
        }

        foreach ($detalles as $detalle) {
            $nodo = $this->hijo($raiz, 'detalle');
            $lineas = [
                'actividadEconomica' => $detalle['actividadEconomica'],
                'codigoProductoSin' => $detalle['codigoProductoSin'],
                'codigoProducto' => $detalle['codigoProducto'],
                'descripcion' => $detalle['descripcion'],
                'cantidad' => $detalle['cantidad'],
                'unidadMedida' => $detalle['unidadMedida'],
                'precioUnitario' => $detalle['precioUnitario'],
                'montoDescuento' => $detalle['montoDescuento'],
                'subTotal' => $detalle['subTotal'],
                'numeroSerie' => $detalle['numeroSerie'],
                'numeroImei' => $detalle['numeroImei'],
            ];

            foreach ($lineas as $nombre => $valor) {
                $this->campo($nodo, $nombre, $valor);
            }
        }

        $xml = $documento->saveXML();

        if ($xml === false) {
            throw new DatosInvalidos('No se pudo generar el XML de la factura V2.');
        }

        return $xml;
    }

    /**
     * Generación ultrarrápida de XML por plantilla de texto, ideal para paquetes de 500 facturas.
     *
     * @param  array<int, array<string, mixed>>  $detalles
     */
    public function generarRapido(
        int $nitEmisor,
        string $razonSocialEmisor,
        string $municipio,
        ?string $telefono,
        int $numeroFactura,
        string $cuf,
        string $cufd,
        int $codigoSucursal,
        string $direccion,
        int $codigoPuntoVenta,
        string $fechaEmision,
        ?string $nombreRazonSocial,
        int $codigoTipoDocumentoIdentidad,
        string $numeroDocumento,
        ?string $complemento,
        string $codigoCliente,
        int $codigoMetodoPago,
        ?string $numeroTarjeta,
        string $montoTotal,
        string $montoTotalSujetoIva,
        int $codigoMoneda,
        string $tipoCambio,
        string $montoTotalMoneda,
        ?string $montoGiftCard,
        string $descuentoAdicional,
        ?int $codigoExcepcion,
        ?string $cafc,
        string $leyenda,
        string $usuario,
        int $codigoDocumentoSector,
        array $detalles
    ): string {
        $tel = $telefono !== null ? "<telefono>{$telefono}</telefono>" : '<telefono xsi:nil="true"/>';
        $comp = $complemento !== null ? "<complemento>{$complemento}</complemento>" : '<complemento xsi:nil="true"/>';
        $tarj = $numeroTarjeta !== null ? "<numeroTarjeta>{$numeroTarjeta}</numeroTarjeta>" : '<numeroTarjeta xsi:nil="true"/>';
        $gift = $montoGiftCard !== null ? "<montoGiftCard>{$montoGiftCard}</montoGiftCard>" : '<montoGiftCard xsi:nil="true"/>';
        $exc = $codigoExcepcion !== null ? "<codigoExcepcion>{$codigoExcepcion}</codigoExcepcion>" : '<codigoExcepcion xsi:nil="true"/>';
        $caf = $cafc !== null ? "<cafc>{$cafc}</cafc>" : '<cafc xsi:nil="true"/>';
        $razon = htmlspecialchars($nombreRazonSocial ?? '', ENT_XML1, 'UTF-8');
        $ley = htmlspecialchars($leyenda, ENT_XML1, 'UTF-8');
        $dir = htmlspecialchars($direccion, ENT_XML1, 'UTF-8');

        $detallesXml = '';
        foreach ($detalles as $d) {
            $desc = htmlspecialchars((string) $d['descripcion'], ENT_XML1, 'UTF-8');
            $descMonto = isset($d['montoDescuento']) && $d['montoDescuento'] !== null ? "<montoDescuento>{$d['montoDescuento']}</montoDescuento>" : '<montoDescuento xsi:nil="true"/>';
            $serie = isset($d['numeroSerie']) && $d['numeroSerie'] !== null ? "<numeroSerie>{$d['numeroSerie']}</numeroSerie>" : '<numeroSerie xsi:nil="true"/>';
            $imei = isset($d['numeroImei']) && $d['numeroImei'] !== null ? "<numeroImei>{$d['numeroImei']}</numeroImei>" : '<numeroImei xsi:nil="true"/>';
            $detallesXml .= "<detalle><actividadEconomica>{$d['actividadEconomica']}</actividadEconomica><codigoProductoSin>{$d['codigoProductoSin']}</codigoProductoSin><codigoProducto>{$d['codigoProducto']}</codigoProducto><descripcion>{$desc}</descripcion><cantidad>{$d['cantidad']}</cantidad><unidadMedida>{$d['unidadMedida']}</unidadMedida><precioUnitario>{$d['precioUnitario']}</precioUnitario>{$descMonto}<subTotal>{$d['subTotal']}</subTotal>{$serie}{$imei}</detalle>";
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<facturaComputarizadaCompraVenta xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xsi:noNamespaceSchemaLocation=\"facturaComputarizadaCompraVenta.xsd\"><cabecera><nitEmisor>{$nitEmisor}</nitEmisor><razonSocialEmisor>{$razonSocialEmisor}</razonSocialEmisor><municipio>{$municipio}</municipio>{$tel}<numeroFactura>{$numeroFactura}</numeroFactura><cuf>{$cuf}</cuf><cufd>{$cufd}</cufd><codigoSucursal>{$codigoSucursal}</codigoSucursal><direccion>{$dir}</direccion><codigoPuntoVenta>{$codigoPuntoVenta}</codigoPuntoVenta><fechaEmision>{$fechaEmision}</fechaEmision><nombreRazonSocial>{$razon}</nombreRazonSocial><codigoTipoDocumentoIdentidad>{$codigoTipoDocumentoIdentidad}</codigoTipoDocumentoIdentidad><numeroDocumento>{$numeroDocumento}</numeroDocumento>{$comp}<codigoCliente>{$codigoCliente}</codigoCliente><codigoMetodoPago>{$codigoMetodoPago}</codigoMetodoPago>{$tarj}<montoTotal>{$montoTotal}</montoTotal><montoTotalSujetoIva>{$montoTotalSujetoIva}</montoTotalSujetoIva><codigoMoneda>{$codigoMoneda}</codigoMoneda><tipoCambio>{$tipoCambio}</tipoCambio><montoTotalMoneda>{$montoTotalMoneda}</montoTotalMoneda>{$gift}<descuentoAdicional>{$descuentoAdicional}</descuentoAdicional>{$exc}{$caf}<leyenda>{$ley}</leyenda><usuario>{$usuario}</usuario><codigoDocumentoSector>{$codigoDocumentoSector}</codigoDocumentoSector></cabecera>{$detallesXml}</facturaComputarizadaCompraVenta>";
    }

    private function hijo(DOMElement $padre, string $nombre): DOMElement
    {
        $elemento = $padre->ownerDocument?->createElement($nombre);

        if (! $elemento instanceof DOMElement) {
            throw new DatosInvalidos("No se pudo crear el elemento <{$nombre}>.");
        }

        $padre->appendChild($elemento);

        return $elemento;
    }

    private function campo(DOMElement $padre, string $nombre, mixed $valor): void
    {
        $doc = $padre->ownerDocument;

        if ($valor === null) {
            $nulo = $doc?->createElement($nombre);
            if ($nulo instanceof DOMElement) {
                $nulo->setAttributeNS(self::XSI, 'xsi:nil', 'true');
                $padre->appendChild($nulo);
            }

            return;
        }

        $elemento = $doc?->createElement($nombre);
        if (! $elemento instanceof DOMElement) {
            throw new DatosInvalidos("No se pudo crear el campo <{$nombre}>.");
        }

        $elemento->appendChild(new DOMText((string) $valor));
        $padre->appendChild($elemento);
    }
}
