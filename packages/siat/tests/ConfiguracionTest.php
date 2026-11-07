<?php

namespace MoziStudios\Siat\Tests;

use ErrorException;
use LogicException;
use MoziStudios\Siat\Configuracion\ConfiguracionSistema;
use MoziStudios\Siat\Configuracion\Contribuyente;
use MoziStudios\Siat\Configuracion\PuntoEmision;
use MoziStudios\Siat\Configuracion\TokenDelegado;
use MoziStudios\Siat\Constantes\Ambiente;
use MoziStudios\Siat\Constantes\Modalidad;
use MoziStudios\Siat\Excepciones\DatosInvalidos;
use MoziStudios\Siat\Excepciones\ErrorComunicacion;
use MoziStudios\Siat\Soap\LlamadaSiat;
use MoziStudios\Siat\Soap\ObservadorLlamadas;
use MoziStudios\Siat\Soap\Servicio;
use MoziStudios\Siat\Soap\SoapTransporte;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class ConfiguracionTest extends TestCase
{
    public function test_el_token_no_aparece_en_volcados_ni_json_y_no_se_serializa(): void
    {
        $token = new TokenDelegado(Fabrica::TOKEN);
        $contribuyente = new Contribuyente(123451020, $token);

        $this->assertSame(Fabrica::TOKEN, $token->valor());
        $this->assertSame('…1234', $token->sufijo());

        ob_start();
        var_dump($contribuyente);
        $volcados = (string) ob_get_clean();
        $volcados .= print_r($contribuyente, true);
        $volcados .= var_export($contribuyente, true);
        $volcados .= (string) json_encode($contribuyente);

        $this->assertStringNotContainsString('SECRETO', $volcados);

        $this->expectException(LogicException::class);
        serialize($contribuyente);
    }

    public function test_el_token_rechaza_vacios_y_saltos_de_linea(): void
    {
        foreach (['', '   ', "abc\r\nX-Inyectada: 1", 'abc def'] as $valor) {
            try {
                new TokenDelegado($valor);
                $this->fail('Debía rechazar el token '.json_encode($valor));
            } catch (DatosInvalidos) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_configuracion_por_defecto_es_piloto_computarizada(): void
    {
        $configuracion = Fabrica::configuracion();

        $this->assertSame(Ambiente::Pruebas, $configuracion->ambiente);
        $this->assertSame(Modalidad::Computarizada, $configuracion->modalidad);
        $this->assertSame(
            'https://pilotosiatservicios.impuestos.gob.bo/sfd/ServicioFacturacionCompraVenta',
            $configuracion->urlServicio(Servicio::CompraVenta),
        );
    }

    public function test_acepta_modalidad_electronica(): void
    {
        $configuracion = new ConfiguracionSistema('ABC', modalidad: Modalidad::Electronica);
        $this->assertSame(Modalidad::Electronica, $configuracion->modalidad);
    }

    public function test_no_mezcla_ambiente_y_url(): void
    {
        $casos = [
            fn () => new ConfiguracionSistema('ABC', Ambiente::Produccion),
            fn () => new ConfiguracionSistema('ABC', Ambiente::Pruebas, urlBase: 'https://siatrest.impuestos.gob.bo/sfd/'),
            fn () => new ConfiguracionSistema('ABC', urlBase: 'http://pilotosiatservicios.impuestos.gob.bo/sfd/'),
            fn () => new ConfiguracionSistema('ABC-123'),
            fn () => new ConfiguracionSistema('ABC', segundosRespuesta: 0),
        ];

        foreach ($casos as $indice => $caso) {
            try {
                $caso();
                $this->fail("El caso {$indice} debía rechazarse.");
            } catch (DatosInvalidos) {
                $this->addToAssertionCount(1);
            }
        }

        $produccion = new ConfiguracionSistema('ABC', Ambiente::Produccion, urlBase: 'https://siatrest.impuestos.gob.bo/sfd');
        $this->assertSame('https://siatrest.impuestos.gob.bo/sfd/FacturacionCodigos', $produccion->urlServicio(Servicio::Codigos));
    }

    public function test_limites_de_nit_y_punto_de_emision(): void
    {
        foreach ([fn () => new Contribuyente(0, new TokenDelegado('x')), fn () => new PuntoEmision(10000), fn () => new PuntoEmision(0, -1)] as $caso) {
            try {
                $caso();
                $this->fail('Debía rechazarse.');
            } catch (DatosInvalidos) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Sin red: el host .invalid nunca resuelve. Comprueba que el error no lleva el token y que el
     * observador recibe la llamada fallida.
     */
    #[RequiresPhpExtension('soap')]
    public function test_el_transporte_soap_reporta_fallas_sin_el_token(): void
    {
        $observador = new class implements ObservadorLlamadas
        {
            /** @var list<LlamadaSiat> */
            public array $llamadas = [];

            public function registrar(LlamadaSiat $llamada): void
            {
                $this->llamadas[] = $llamada;
            }
        };
        $configuracion = new ConfiguracionSistema('ABC', urlBase: 'https://piloto.mozistock.invalid/sfd/', segundosConexion: 2, segundosRespuesta: 2);

        // Como Laravel: cualquier warning se convierte en excepción. El transporte debe absorberlo.
        set_error_handler(static fn (int $nivel, string $mensaje): never => throw new ErrorException($mensaje, 0, $nivel));

        try {
            (new SoapTransporte($observador))->llamar($configuracion, Servicio::Codigos, 'verificarComunicacion', [], new TokenDelegado(Fabrica::TOKEN));
            $this->fail('Debía fallar sin red.');
        } catch (ErrorComunicacion $error) {
            $this->assertStringNotContainsString('SECRETO', $error->getMessage());
            $this->assertStringContainsString('FacturacionCodigos.verificarComunicacion', $error->getMessage());
            $this->assertNull($error->getPrevious());
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $observador->llamadas);
        $this->assertFalse($observador->llamadas[0]->exitosa);
        $this->assertSame('verificarComunicacion', $observador->llamadas[0]->operacion);
    }
}
