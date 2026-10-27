<?php

namespace MoziStudios\Siat\Soap;

use MoziStudios\Siat\Configuracion\ConfiguracionSistema;
use MoziStudios\Siat\Configuracion\TokenDelegado;
use MoziStudios\Siat\Excepciones\ErrorComunicacion;
use SoapClient;
use SoapFault;

/**
 * Transporte real con la extensión SOAP de PHP. El token viaja solo en la cabecera HTTP
 * `apikey: TokenApi …` de cada solicitud; se crea un cliente por llamada para no mezclar tokens de
 * empresas distintas. El WSDL se cachea (memoria, o disco si se indica un directorio propio).
 * Los errores se reportan sin el token y sin encadenar el SoapFault original.
 */
final class SoapTransporte implements Transporte
{
    public function __construct(
        private readonly ?ObservadorLlamadas $observador = null,
        private readonly ?string $directorioCacheWsdl = null,
    ) {}

    public function llamar(
        ConfiguracionSistema $configuracion,
        Servicio $servicio,
        string $operacion,
        array $parametros,
        TokenDelegado $token,
    ): object {
        $inicio = hrtime(true);
        $timeoutAnterior = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string) $configuracion->segundosRespuesta);

        // SoapClient emite warnings de red antes del SoapFault; los frameworks los convierten en excepciones
        // que escaparían de este manejo. Se capturan aquí y se informan con la falla, sin el token.
        $avisos = [];
        set_error_handler(static function (int $nivel, string $mensaje) use (&$avisos): bool {
            $avisos[] = $mensaje;

            return true;
        }, E_WARNING | E_NOTICE);

        try {
            $respuesta = $this->cliente($configuracion, $servicio, $token)->__soapCall($operacion, [$parametros]);
        } catch (SoapFault $falla) {
            $mensaje = str_replace($token->valor(), '[oculto]', implode(' | ', [$falla->getMessage(), ...array_slice($avisos, 0, 1)]));
            $this->notificar($servicio, $operacion, $inicio, $mensaje);

            throw new ErrorComunicacion("El SIN no respondió correctamente a {$servicio->value}.{$operacion}: {$mensaje}", $falla->faultcode);
        } finally {
            restore_error_handler();
            ini_set('default_socket_timeout', $timeoutAnterior);
        }

        if (! is_object($respuesta)) {
            $this->notificar($servicio, $operacion, $inicio, 'respuesta vacía');

            throw new ErrorComunicacion("El SIN devolvió una respuesta vacía en {$servicio->value}.{$operacion}.");
        }

        $this->notificar($servicio, $operacion, $inicio, null);

        return $respuesta;
    }

    private function cliente(ConfiguracionSistema $configuracion, Servicio $servicio, TokenDelegado $token): SoapClient
    {
        $cache = WSDL_CACHE_MEMORY;

        if ($this->directorioCacheWsdl !== null && is_dir($this->directorioCacheWsdl) && is_writable($this->directorioCacheWsdl)) {
            ini_set('soap.wsdl_cache_dir', $this->directorioCacheWsdl);
            $cache = WSDL_CACHE_BOTH;
        }

        return new SoapClient($configuracion->urlServicio($servicio).'?wsdl', [
            'stream_context' => stream_context_create([
                'http' => [
                    'header' => 'apikey: TokenApi '.$token->valor(),
                    'timeout' => $configuracion->segundosRespuesta,
                ],
            ]),
            'connection_timeout' => $configuracion->segundosConexion,
            'cache_wsdl' => $cache,
            'compression' => SOAP_COMPRESSION_ACCEPT,
            'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
            'exceptions' => true,
            'trace' => false,
            'user_agent' => 'MoziStudios-Siat/0.1',
        ]);
    }

    private function notificar(Servicio $servicio, string $operacion, int|float $inicio, ?string $error): void
    {
        $this->observador?->registrar(new LlamadaSiat(
            $servicio,
            $operacion,
            (int) ((hrtime(true) - $inicio) / 1_000_000),
            $error === null,
            $error,
        ));
    }
}
