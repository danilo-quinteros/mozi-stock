<?php

namespace MoziStudios\Siat\Tests;

use DOMDocument;
use DOMXPath;
use MoziStudios\Siat\Algoritmos\Archivo;
use MoziStudios\Siat\Codigos\Cufd;
use MoziStudios\Siat\Constantes\DocumentoSector;
use MoziStudios\Siat\Constantes\Modalidad;
use MoziStudios\Siat\Constantes\TipoEmision;
use MoziStudios\Siat\Constantes\TipoFacturaDocumento;
use MoziStudios\Siat\Cuf\DatosCuf;
use MoziStudios\Siat\Cuf\GeneradorCuf;
use MoziStudios\Siat\Excepciones\DatosInvalidos;
use MoziStudios\Siat\Excepciones\XmlInvalido;
use MoziStudios\Siat\Factura\DetalleCompraVenta;
use MoziStudios\Siat\Factura\GeneradorXmlCompraVenta;
use MoziStudios\Siat\Factura\NumeroTarjeta;
use MoziStudios\Siat\Factura\PreparadorFactura;
use MoziStudios\Siat\Factura\ValidadorXsd;
use MoziStudios\Siat\Factura\VerificadorMontos;
use PHPUnit\Framework\TestCase;

final class FacturaXmlTest extends TestCase
{
    private static function preparador(): PreparadorFactura
    {
        return new PreparadorFactura(Fabrica::configuracion(), ValidadorXsd::compraVentaComputarizada());
    }

    private static function xpath(string $xml): DOMXPath
    {
        $documento = new DOMDocument;
        $documento->loadXML($xml);

        return new DOMXPath($documento);
    }

    private static function valor(DOMXPath $xpath, string $ruta): string
    {
        return (string) $xpath->evaluate("string({$ruta})");
    }

    public function test_el_ejemplo_oficial_del_anexo_cumple_el_xsd_empaquetado(): void
    {
        $xml = (string) file_get_contents(__DIR__.'/fixtures/facturaComputarizadaCompraVenta.xml');

        ValidadorXsd::compraVentaComputarizada()->validar($xml);
        $this->addToAssertionCount(1);
    }

    public function test_el_xml_generado_con_los_datos_del_ejemplo_cumple_el_xsd(): void
    {
        $xml = (new GeneradorXmlCompraVenta)->generar(Fabrica::factura(), 'CUFDEPRUEBA123');

        ValidadorXsd::compraVentaComputarizada()->validar($xml);

        $xpath = self::xpath($xml);
        $this->assertSame('2027-06-01T09:19:48.675', self::valor($xpath, '/facturaComputarizadaCompraVenta/cabecera/fechaEmision'));
        $this->assertSame('A', self::valor($xpath, '//cabecera/codigoInterno'));
        $this->assertSame('1', self::valor($xpath, '//cabecera/codigoDocumentoSector'));
        $this->assertSame('160.60', self::valor($xpath, '//cabecera/montoaPagar'));
        $this->assertSame('100.00', self::valor($xpath, '//cabecera/montoGiftCard'));
        $this->assertSame('200.00', self::valor($xpath, '//detalle[1]/cantidad'));
        $this->assertEquals(4, $xpath->evaluate('count(//detalle)'));
    }

    public function test_el_orden_de_la_cabecera_sigue_al_xsd_v3(): void
    {
        $xml = (new GeneradorXmlCompraVenta)->generar(Fabrica::factura(), 'CUF');
        $nombres = [];

        foreach (self::xpath($xml)->query('//cabecera/*') ?: [] as $nodo) {
            $nombres[] = $nodo->nodeName;
        }

        $this->assertSame(['subTotalNeto', 'totalPrecioNeto', 'iva', 'montoaPagar'], array_values(array_intersect($nombres, ['subTotalNeto', 'totalPrecioNeto', 'iva', 'montoaPagar'])));
        $this->assertSame(['usuario', 'codigoInterno', 'codigoDocumentoSector'], array_slice($nombres, -3));
    }

    public function test_los_montos_del_ejemplo_cumplen_las_formulas_oficiales(): void
    {
        $this->assertSame([], VerificadorMontos::diferencias(Fabrica::factura()));
    }

    public function test_detecta_iva_y_subtotales_que_no_cuadran(): void
    {
        $diferencias = VerificadorMontos::diferencias(Fabrica::factura(['iva' => '18.47', 'montoaPagar' => '160.59', 'montoTotalMoneda' => '160.59']));

        $this->assertContains('iva 18.47, esperado 18.48.', $diferencias);

        $detalles = Fabrica::detalles();
        $detalles[0] = new DetalleCompraVenta('4752100', 1001912, 'GA-CL-013', 'GANCHO', '200', 1, '0.54', '100.49', '7.52');
        $this->assertContains('Línea 1: subTotal 100.49, esperado 100.48.', VerificadorMontos::diferencias(Fabrica::factura(['detalles' => $detalles])));
    }

    /**
     * Precio final Bs 1,00 con IVA transparente: 0,88 + 0,11 = 0,99 y 0,89 + 0,12 = 1,01.
     * Ninguna base de 2 decimales da exactamente 1,00 con las fórmulas oficiales (decisión D1 abierta).
     */
    public function test_un_precio_final_de_un_boliviano_no_cuadra_con_las_formulas(): void
    {
        $this->assertSame([], VerificadorMontos::diferencias(Fabrica::facturaSimple('0.88', '0.11', '0.99')));
        $this->assertSame([], VerificadorMontos::diferencias(Fabrica::facturaSimple('0.89', '0.12', '1.01')));
        $this->assertContains('iva 0.12, esperado 0.11.', VerificadorMontos::diferencias(Fabrica::facturaSimple('0.88', '0.12', '1.00')));
    }

    public function test_escapa_caracteres_especiales_y_marca_nulos_con_xsi_nil(): void
    {
        $factura = Fabrica::factura([
            'nombreRazonSocial' => 'Pérez & Hijos <SRL>',
            'telefono' => null,
            'complemento' => null,
            'montoGiftCard' => null,
            'descuentoAdicional' => null,
            'subTotalNeto' => '262.12',
            'totalPrecioNeto' => '262.12',
            'iva' => '34.08',
            'montoaPagar' => '296.20',
            'montoTotalMoneda' => '296.20',
        ]);
        $xml = (new GeneradorXmlCompraVenta)->generar($factura, 'CUF');

        $this->assertStringContainsString('<nombreRazonSocial>Pérez &amp; Hijos &lt;SRL&gt;</nombreRazonSocial>', $xml);
        $this->assertStringContainsString('<telefono xsi:nil="true"/>', $xml);
        $this->assertStringContainsString('<numeroTarjeta xsi:nil="true"/>', $xml);
        $this->assertStringContainsString('<complemento>0</complemento>', $xml);
        $this->assertSame([], VerificadorMontos::diferencias($factura));
        ValidadorXsd::compraVentaComputarizada()->validar($xml);
    }

    public function test_el_xsd_rechaza_un_xml_alterado(): void
    {
        $xml = (new GeneradorXmlCompraVenta)->generar(Fabrica::factura(), 'CUF');
        $alterado = str_replace('<iva>18.48</iva>', '<iva>18.4756</iva>', $xml);

        try {
            ValidadorXsd::compraVentaComputarizada()->validar($alterado);
            $this->fail('El XSD debía rechazar un iva de 4 decimales.');
        } catch (XmlInvalido $error) {
            $this->assertNotEmpty($error->errores);
            $this->assertStringContainsString('iva', implode(' ', $error->errores));
        }
    }

    public function test_el_validador_rechaza_xml_vacio_o_ilegible(): void
    {
        foreach (['', '<sin-cerrar>'] as $xml) {
            try {
                ValidadorXsd::compraVentaComputarizada()->validar($xml);
                $this->fail('Debía rechazar un XML vacío o mal formado.');
            } catch (XmlInvalido $error) {
                $this->assertNotEmpty($error->errores);
            }
        }
    }

    public function test_el_preparador_usa_la_misma_fecha_en_cuf_y_xml_y_comprime_el_xml_validado(): void
    {
        $preparada = self::preparador()->preparar(Fabrica::factura());
        $xpath = self::xpath($preparada->xml);

        $this->assertSame($preparada->cuf, self::valor($xpath, '//cabecera/cuf'));
        $this->assertStringEndsWith('A19E23EF34124CD', $preparada->cuf);
        $this->assertSame($preparada->xml, Archivo::descomprimir($preparada->archivo));
        $this->assertSame(hash('sha256', $preparada->archivo), $preparada->hashArchivo);
        $this->assertSame($preparada->hashArchivo, strtolower($preparada->hashArchivo));

        // El CUF lleva la fecha de emisión del XML: se reconstruye y se compara.
        $this->assertSame(
            GeneradorCuf::generar(new DatosCuf(
                nit: 123451020,
                fecha: Fabrica::fecha(),
                sucursal: 1,
                modalidad: Modalidad::Computarizada,
                tipoEmision: TipoEmision::EnLinea,
                tipoFactura: TipoFacturaDocumento::ConDerechoCreditoFiscal,
                documentoSector: DocumentoSector::CompraVenta,
                numeroFactura: 2377,
                puntoVenta: 0,
            ), 'A19E23EF34124CD'),
            $preparada->cuf,
        );
    }

    public function test_el_preparador_es_determinista(): void
    {
        $this->assertSame(self::preparador()->preparar(Fabrica::factura())->hashArchivo, self::preparador()->preparar(Fabrica::factura())->hashArchivo);
    }

    public function test_el_preparador_rechaza_montos_que_no_cuadran_salvo_prueba_controlada(): void
    {
        $factura = Fabrica::facturaSimple('0.88', '0.12', '1.00');

        try {
            self::preparador()->preparar($factura);
            $this->fail('Debía rechazar montos que no cumplen las fórmulas.');
        } catch (DatosInvalidos $error) {
            $this->assertStringContainsString('iva 0.12, esperado 0.11.', $error->getMessage());
        }

        // Experimento de piloto (decisión D1): se permite preparar sin verificar para ver qué responde el SIN.
        $this->assertStringContainsString('<montoaPagar>1.00</montoaPagar>', self::preparador()->preparar($factura, false)->xml);
    }

    public function test_exige_el_codigo_interno_del_cufd_v3(): void
    {
        $this->expectException(DatosInvalidos::class);
        $this->expectExceptionMessage('codigoInterno');
        Fabrica::factura(['cufd' => new Cufd('CUFD', 'CONTROL')]);
    }

    public function test_codigos_especiales_van_con_nit_y_excepcion(): void
    {
        $factura = Fabrica::factura(['codigoTipoDocumentoIdentidad' => 5, 'numeroDocumento' => '99002', 'complemento' => null, 'codigoExcepcion' => 1]);
        $this->assertSame('99002', $factura->numeroDocumento);

        $this->expectException(DatosInvalidos::class);
        Fabrica::factura(['codigoTipoDocumentoIdentidad' => 1, 'numeroDocumento' => '99003', 'codigoExcepcion' => 1]);
    }

    public function test_codigo_especial_sin_excepcion_se_rechaza(): void
    {
        $this->expectException(DatosInvalidos::class);
        Fabrica::factura(['codigoTipoDocumentoIdentidad' => 5, 'numeroDocumento' => '99001', 'codigoExcepcion' => 0]);
    }

    public function test_ci_o_nit_deben_ser_numericos(): void
    {
        $this->expectException(DatosInvalidos::class);
        Fabrica::factura(['codigoTipoDocumentoIdentidad' => 1, 'numeroDocumento' => '98765-4']);
    }

    public function test_fuera_de_linea_a_un_nit_exige_excepcion_1(): void
    {
        $factura = Fabrica::factura(['codigoTipoDocumentoIdentidad' => 5, 'numeroDocumento' => '1020703023', 'tipoEmision' => TipoEmision::FueraDeLinea, 'codigoExcepcion' => 1]);
        $this->assertSame(TipoEmision::FueraDeLinea, $factura->tipoEmision);

        $this->expectException(DatosInvalidos::class);
        Fabrica::factura(['codigoTipoDocumentoIdentidad' => 5, 'numeroDocumento' => '1020703023', 'tipoEmision' => TipoEmision::FueraDeLinea]);
    }

    public function test_tarjeta_solo_ofuscada(): void
    {
        $this->assertSame('4797000000007896', NumeroTarjeta::ofuscado('4797', '7896'));
        $this->assertSame('4797000000007896', Fabrica::factura(['codigoMetodoPago' => 2, 'numeroTarjeta' => NumeroTarjeta::ofuscado('4797', '7896')])->numeroTarjeta);

        $this->expectException(DatosInvalidos::class);
        Fabrica::factura(['codigoMetodoPago' => 2, 'numeroTarjeta' => '4797123456787896']);
    }

    public function test_rechaza_caracteres_de_control_y_textos_obligatorios_vacios(): void
    {
        foreach ([['nombreRazonSocial' => "Juan\x07"], ['leyenda' => '   '], ['razonSocialEmisor' => "\xC3\x28"]] as $cambio) {
            try {
                Fabrica::factura($cambio);
                $this->fail('Debía rechazar '.json_encode(array_keys($cambio)));
            } catch (DatosInvalidos) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_limita_las_lineas_de_detalle(): void
    {
        $this->expectException(DatosInvalidos::class);
        Fabrica::factura(['detalles' => []]);
    }

    public function test_cantidad_y_precio_admiten_como_maximo_dos_decimales(): void
    {
        $this->expectException(DatosInvalidos::class);
        new DetalleCompraVenta('4752100', 1001912, 'P1', 'Producto', '1.255', 1, '1.00', '1.26');
    }
}
