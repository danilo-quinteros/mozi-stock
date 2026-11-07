<?php

namespace MoziStudios\Siat\Factura;

use DOMDocument;
use LibXMLError;
use MoziStudios\Siat\Excepciones\DatosInvalidos;
use MoziStudios\Siat\Excepciones\XmlInvalido;

/**
 * Valida un XML contra el XSD oficial empaquetado en resources/xsd (copia del anexo técnico, sin acceso a red).
 */
final class ValidadorXsd
{
    public function __construct(private readonly string $rutaXsd)
    {
        if (! is_file($rutaXsd)) {
            throw new DatosInvalidos("No existe el XSD {$rutaXsd}.");
        }
    }

    public static function compraVentaComputarizada(): self
    {
        return new self(dirname(__DIR__, 2).'/resources/xsd/facturaComputarizadaCompraVenta.xsd');
    }

    public static function compraVentaElectronica(): self
    {
        return new self(dirname(__DIR__, 2).'/resources/xsd/facturaElectronicaCompraVenta.xsd');
    }

    /**
     * @throws XmlInvalido
     */
    public function validar(string $xml): void
    {
        if (trim($xml) === '') {
            throw new XmlInvalido(['XML vacío.']);
        }

        $anterior = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $documento = new DOMDocument;

            if (! $documento->loadXML($xml, LIBXML_NONET) || ! $documento->schemaValidate($this->rutaXsd, LIBXML_NONET)) {
                throw new XmlInvalido(array_map(
                    fn (LibXMLError $error): string => 'línea '.$error->line.': '.trim($error->message),
                    libxml_get_errors(),
                ) ?: ['XML ilegible.']);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }
    }
}
