<?php

namespace MoziStudios\Siat\Testing;

use LogicException;
use MoziStudios\Siat\Configuracion\ConfiguracionSistema;
use MoziStudios\Siat\Configuracion\TokenDelegado;
use MoziStudios\Siat\Soap\Servicio;
use MoziStudios\Siat\Soap\Transporte;
use Throwable;

/**
 * Transporte sin red para pruebas de quien integra: devuelve, en orden, las respuestas preparadas
 * (o lanza las excepciones preparadas) y registra cada llamada. Del token guarda solo el sufijo.
 */
final class TransporteFalso implements Transporte
{
    /** @var list<array{servicio: Servicio, operacion: string, parametros: array<string, mixed>, token: string}> */
    public array $llamadas = [];

    /** @var list<object|Throwable> */
    private array $respuestas = [];

    /**
     * @param  array<string, mixed>|object|Throwable  $respuesta  arreglo asociativo con la forma del WSDL, objeto o excepción
     */
    public function responder(array|object $respuesta): self
    {
        $this->respuestas[] = is_array($respuesta) ? self::objeto($respuesta) : $respuesta;

        return $this;
    }

    public function llamar(
        ConfiguracionSistema $configuracion,
        Servicio $servicio,
        string $operacion,
        array $parametros,
        TokenDelegado $token,
    ): object {
        $this->llamadas[] = ['servicio' => $servicio, 'operacion' => $operacion, 'parametros' => $parametros, 'token' => $token->sufijo()];
        $respuesta = array_shift($this->respuestas) ?? throw new LogicException("No hay respuesta preparada para {$operacion}.");

        if ($respuesta instanceof Throwable) {
            throw $respuesta;
        }

        return $respuesta;
    }

    /**
     * Convierte un arreglo asociativo en el árbol de stdClass que entregaría SoapClient (las listas siguen siendo arreglos).
     *
     * @param  array<string, mixed>  $datos
     */
    public static function objeto(array $datos): object
    {
        $objeto = json_decode((string) json_encode($datos, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        if (! is_object($objeto)) {
            throw new LogicException('La respuesta preparada debe ser un arreglo asociativo.');
        }

        return $objeto;
    }
}
