<?php

namespace MoziStudios\Siat\Factura;

use DOMDocument;
use DOMElement;
use DOMText;
use MoziStudios\Siat\Excepciones\DatosInvalidos;

/**
 * XML de facturaComputarizadaCompraVenta en el orden exacto del XSD V3. Los valores opcionales nulos
 * se escriben con xsi:nil="true" solo en los campos que el XSD declara nillable; DOM escapa el texto.
 */
final class GeneradorXmlCompraVenta
{
    private const XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    public function generar(FacturaCompraVenta $factura, string $cuf): string
    {
        $documento = new DOMDocument('1.0', 'UTF-8');
        $raiz = $documento->createElement('facturaComputarizadaCompraVenta');
        $raiz->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', self::XSI);
        $raiz->setAttributeNS(self::XSI, 'xsi:noNamespaceSchemaLocation', 'facturaComputarizadaCompraVenta.xsd');
        $documento->appendChild($raiz);

        $cabecera = $this->hijo($raiz, 'cabecera');
        $campos = [
            'nitEmisor' => $factura->nitEmisor,
            'razonSocialEmisor' => $factura->razonSocialEmisor,
            'municipio' => $factura->municipio,
            'telefono' => $factura->telefono,
            'numeroFactura' => $factura->numeroFactura,
            'cuf' => $cuf,
            'cufd' => $factura->cufd->codigo,
            'codigoSucursal' => $factura->punto->sucursal,
            'direccion' => $factura->direccion,
            'codigoPuntoVenta' => $factura->punto->puntoVenta,
            'fechaEmision' => $factura->fechaEmision->xml(),
            'nombreRazonSocial' => $factura->nombreRazonSocial,
            'codigoTipoDocumentoIdentidad' => $factura->codigoTipoDocumentoIdentidad,
            'numeroDocumento' => $factura->numeroDocumento,
            'complemento' => $factura->complemento,
            'codigoCliente' => $factura->codigoCliente,
            'codigoMetodoPago' => $factura->codigoMetodoPago,
            'numeroTarjeta' => $factura->numeroTarjeta,
            'subTotalNeto' => $factura->subTotalNeto,
            'totalPrecioNeto' => $factura->totalPrecioNeto,
            'iva' => $factura->iva,
            'montoaPagar' => $factura->montoaPagar,
            'codigoMoneda' => $factura->codigoMoneda,
            'tipoCambio' => $factura->tipoCambio,
            'montoTotalMoneda' => $factura->montoTotalMoneda,
            'montoGiftCard' => $factura->montoGiftCard,
            'descuentoAdicional' => $factura->descuentoAdicional,
            'codigoExcepcion' => $factura->codigoExcepcion,
            'leyenda' => $factura->leyenda,
            'usuario' => $factura->usuario,
            'codigoInterno' => $factura->codigoInterno,
            'codigoDocumentoSector' => 1,
        ];

        foreach ($campos as $nombre => $valor) {
            $this->campo($cabecera, $nombre, $valor);
        }

        foreach ($factura->detalles as $detalle) {
            $nodo = $this->hijo($raiz, 'detalle');
            $lineas = [
                'actividadEconomica' => $detalle->actividadEconomica,
                'codigoProductoSin' => $detalle->codigoProductoSin,
                'codigoProducto' => $detalle->codigoProducto,
                'descripcion' => $detalle->descripcion,
                'cantidad' => $detalle->cantidad,
                'unidadMedida' => $detalle->unidadMedida,
                'precioNetoUnitario' => $detalle->precioNetoUnitario,
                'montoDescuento' => $detalle->montoDescuento,
                'subTotal' => $detalle->subTotal,
                'numeroSerie' => $detalle->numeroSerie,
                'numeroImei' => $detalle->numeroImei,
            ];

            foreach ($lineas as $nombre => $valor) {
                $this->campo($nodo, $nombre, $valor);
            }
        }

        $xml = $documento->saveXML();

        if ($xml === false) {
            throw new DatosInvalidos('No se pudo generar el XML de la factura.');
        }

        return $xml;
    }

    private function hijo(DOMElement $padre, string $nombre): DOMElement
    {
        $elemento = $padre->ownerDocument?->createElement($nombre);

        if (! $elemento instanceof DOMElement) {
            throw new DatosInvalidos("No se pudo crear el elemento {$nombre}.");
        }

        $padre->appendChild($elemento);

        return $elemento;
    }

    private function campo(DOMElement $padre, string $nombre, string|int|null $valor): void
    {
        $elemento = $this->hijo($padre, $nombre);

        if ($valor === null) {
            $elemento->setAttributeNS(self::XSI, 'xsi:nil', 'true');

            return;
        }

        $elemento->appendChild(new DOMText((string) $valor));
    }
}
