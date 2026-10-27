<?php

namespace MoziStudios\Siat\Factura;

use MoziStudios\Siat\Algoritmos\Archivo;
use MoziStudios\Siat\Configuracion\ConfiguracionSistema;
use MoziStudios\Siat\Constantes\DocumentoSector;
use MoziStudios\Siat\Constantes\TipoFacturaDocumento;
use MoziStudios\Siat\Cuf\DatosCuf;
use MoziStudios\Siat\Cuf\GeneradorCuf;
use MoziStudios\Siat\Excepciones\DatosInvalidos;

/**
 * Pasos del anexo previos al envío: CUF con la misma fecha del XML, XML, validación XSD, GZIP y SHA-256.
 * No toca la red ni guarda nada: el resultado es determinista para los mismos datos.
 */
final class PreparadorFactura
{
    public function __construct(
        private readonly ConfiguracionSistema $configuracion,
        private readonly ValidadorXsd $validador,
        private readonly GeneradorXmlCompraVenta $generador = new GeneradorXmlCompraVenta,
    ) {}

    /**
     * @param  bool  $verificarMontos  false solo para pruebas controladas en el ambiente piloto
     *
     * @throws DatosInvalidos si los montos no cumplen las fórmulas oficiales
     */
    public function preparar(FacturaCompraVenta $factura, bool $verificarMontos = true): FacturaPreparada
    {
        if ($verificarMontos && ($diferencias = VerificadorMontos::diferencias($factura)) !== []) {
            throw new DatosInvalidos('Los montos no cumplen las fórmulas del SIN: '.implode(' ', $diferencias));
        }

        $cuf = GeneradorCuf::generar(new DatosCuf(
            nit: $factura->nitEmisor,
            fecha: $factura->fechaEmision,
            sucursal: $factura->punto->sucursal,
            modalidad: $this->configuracion->modalidad,
            tipoEmision: $factura->tipoEmision,
            tipoFactura: TipoFacturaDocumento::ConDerechoCreditoFiscal,
            documentoSector: DocumentoSector::CompraVenta,
            numeroFactura: $factura->numeroFactura,
            puntoVenta: $factura->punto->puntoVenta,
        ), $factura->cufd->codigoControl);

        $xml = $this->generador->generar($factura, $cuf);
        $this->validador->validar($xml);
        $archivo = Archivo::comprimir($xml);

        return new FacturaPreparada($factura, $cuf, $xml, $archivo, Archivo::sha256($archivo));
    }
}
