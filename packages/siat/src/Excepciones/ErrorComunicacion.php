<?php

namespace MoziStudios\Siat\Excepciones;

/**
 * El SIN no respondió o respondió algo que no corresponde al WSDL (corte de internet, error 4xx/5xx,
 * SOAP Fault). Según el anexo, es la señal para evaluar el ingreso a contingencia: quien llama decide.
 */
final class ErrorComunicacion extends SiatException
{
    public function __construct(string $mensaje, public readonly ?string $codigoFalla = null)
    {
        parent::__construct($mensaje);
    }
}
