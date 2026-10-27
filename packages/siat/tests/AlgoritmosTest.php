<?php

namespace MoziStudios\Siat\Tests;

use MoziStudios\Siat\Algoritmos\Archivo;
use MoziStudios\Siat\Algoritmos\Base16;
use MoziStudios\Siat\Algoritmos\Decimal;
use MoziStudios\Siat\Algoritmos\Modulo11;
use MoziStudios\Siat\Excepciones\DatosInvalidos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AlgoritmosTest extends TestCase
{
    /** Cadena de 53 dígitos del ejemplo oficial «Generación CUF». */
    private const CADENA_ANEXO = '00001234567892019011316372123100001110100000000010000';

    public function test_modulo11_del_ejemplo_oficial_agrega_1_cuando_el_resto_es_10(): void
    {
        $this->assertSame('1', Modulo11::digito(self::CADENA_ANEXO));
    }

    public function test_modulo11_rechaza_caracteres_no_numericos(): void
    {
        $this->expectException(DatosInvalidos::class);
        Modulo11::digito('12A4');
    }

    public function test_base16_del_ejemplo_oficial(): void
    {
        $this->assertSame('8727F63A15F8976591FDDE5B387C5D015A29E06A1', Base16::codificar(self::CADENA_ANEXO.'1'));
    }

    public function test_base16_ida_y_vuelta_con_ceros_a_la_izquierda(): void
    {
        $this->assertSame('1234567890123456789012345678901234567890', Base16::decodificar(Base16::codificar('0001234567890123456789012345678901234567890')));
        $this->assertSame('0', Base16::codificar('0000'));
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function redondeos(): array
    {
        return [
            'ejemplo del anexo hacia abajo' => ['3.14159', 2, '3.14'],
            'ejemplo del anexo hacia arriba' => ['3.14559', 2, '3.15'],
            'mitad exacta sube' => ['0.005', 2, '0.01'],
            'caso que falla con float' => ['2.675', 2, '2.68'],
            'iva del ejemplo oficial' => ['18.4756', 2, '18.48'],
            'iva de Bs 0,88' => ['0.1144', 2, '0.11'],
            'negativo se aleja de cero' => ['-1.005', 2, '-1.01'],
            'cero' => ['0', 2, '0.00'],
            'sin decimales' => ['7.5', 0, '8'],
        ];
    }

    #[DataProvider('redondeos')]
    public function test_redondeo_half_up_sin_float(string $valor, int $decimales, string $esperado): void
    {
        $this->assertSame($esperado, Decimal::redondear($valor, $decimales));
    }

    public function test_normalizar_acepta_hasta_dos_decimales_y_completa_la_escala(): void
    {
        $this->assertSame('160.60', Decimal::normalizar('160.6', 2, 'monto'));
        $this->assertSame('200.00', Decimal::normalizar(200, 2, 'cantidad'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function montosInvalidos(): array
    {
        return [
            'coma decimal' => ['1,50'],
            'negativo' => ['-1'],
            'tres decimales' => ['1.255'],
            'texto' => ['abc'],
            'vacío' => [''],
        ];
    }

    #[DataProvider('montosInvalidos')]
    public function test_normalizar_rechaza_formatos_no_permitidos(string $valor): void
    {
        $this->expectException(DatosInvalidos::class);
        Decimal::normalizar($valor, 2, 'monto');
    }

    public function test_gzip_ida_y_vuelta_y_sha256_en_minusculas(): void
    {
        $xml = '<?xml version="1.0"?><a>ñandú &amp; cía</a>';
        $comprimido = Archivo::comprimir($xml);

        $this->assertSame("\x1f\x8b", substr($comprimido, 0, 2));
        $this->assertSame($xml, Archivo::descomprimir($comprimido));
        $this->assertSame('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', Archivo::sha256('abc'));
    }

    public function test_tar_y_tar_gz_empaquetan_archivos_y_permiten_desglose(): void
    {
        $archivos = [
            'factura_1.xml' => '<?xml version="1.0"?><factura>1</factura>',
            'factura_2.xml' => '<?xml version="1.0"?><factura>2</factura>',
        ];

        $tar = Archivo::tar($archivos);
        $this->assertNotEmpty($tar);

        $desglosado = Archivo::desglosarTar($tar);
        $this->assertSame($archivos, $desglosado);

        $tarGz = Archivo::tarGz($archivos);
        $this->assertSame("\x1f\x8b", substr($tarGz, 0, 2));
        $this->assertSame($tar, Archivo::descomprimir($tarGz));
        $this->assertSame(hash('sha256', $tarGz), Archivo::sha256($tarGz));
    }

    public function test_tar_vacio_lanza_excepcion(): void
    {
        $this->expectException(DatosInvalidos::class);
        Archivo::tar([]);
    }
}
