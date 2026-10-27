<?php

namespace MoziStudios\Siat\Tests;

use DateTimeImmutable;
use DateTimeZone;
use MoziStudios\Siat\Constantes\DocumentoSector;
use MoziStudios\Siat\Constantes\Modalidad;
use MoziStudios\Siat\Constantes\TipoEmision;
use MoziStudios\Siat\Constantes\TipoFacturaDocumento;
use MoziStudios\Siat\Cuf\DatosCuf;
use MoziStudios\Siat\Cuf\GeneradorCuf;
use MoziStudios\Siat\Excepciones\DatosInvalidos;
use MoziStudios\Siat\Fecha\FechaSiat;
use PHPUnit\Framework\TestCase;

final class CufTest extends TestCase
{
    private static function datos(int $nit = 123456789, int $numero = 1, int $sucursal = 0): DatosCuf
    {
        return new DatosCuf(
            nit: $nit,
            fecha: FechaSiat::desde(new DateTimeImmutable('2019-01-13 16:37:21.231')),
            sucursal: $sucursal,
            modalidad: Modalidad::Electronica,
            tipoEmision: TipoEmision::EnLinea,
            tipoFactura: TipoFacturaDocumento::ConDerechoCreditoFiscal,
            documentoSector: DocumentoSector::CompraVenta,
            numeroFactura: $numero,
            puntoVenta: 0,
        );
    }

    public function test_reproduce_el_ejemplo_oficial_del_anexo(): void
    {
        $this->assertSame('00001234567892019011316372123100001110100000000010000', GeneradorCuf::cadena(self::datos()));
        $this->assertSame(
            '8727F63A15F8976591FDDE5B387C5D015A29E06A1A19E23EF34124CD',
            GeneradorCuf::generar(self::datos(), 'A19E23EF34124CD'),
        );
    }

    public function test_la_fecha_conserva_milisegundos_y_zona_del_emisor(): void
    {
        $fecha = FechaSiat::desde(new DateTimeImmutable('2026-10-24 23:59:59.999876', new DateTimeZone('America/La_Paz')));

        $this->assertSame('2026-10-24T23:59:59.999', $fecha->xml());
        $this->assertSame('20261024235959999', $fecha->cuf());
        $this->assertSame('America/La_Paz', $fecha->momento()->getTimezone()->getName());
    }

    public function test_rechaza_campos_que_no_caben_en_su_longitud(): void
    {
        $this->expectException(DatosInvalidos::class);
        GeneradorCuf::cadena(self::datos(numero: 12345678901));
    }

    public function test_rechaza_nit_de_mas_de_13_digitos(): void
    {
        $this->expectException(DatosInvalidos::class);
        GeneradorCuf::cadena(self::datos(nit: 12345678901234));
    }

    public function test_rechaza_codigo_de_control_vacio(): void
    {
        $this->expectException(DatosInvalidos::class);
        GeneradorCuf::generar(self::datos(), '');
    }
}
