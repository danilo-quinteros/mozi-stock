<?php

namespace MoziStudios\Siat\Respuestas;

/** Resultado de verificarComunicacion: el anexo recomienda pasar a fuera de línea si falla. */
final class RespuestaComunicacion
{
    /**
     * @param  list<Mensaje>  $mensajes
     */
    public function __construct(
        public readonly bool $transaccion,
        public readonly array $mensajes,
    ) {}

    public static function desde(object $respuesta): self
    {
        return new self(Lector::booleano($respuesta, 'transaccion'), Lector::mensajes($respuesta));
    }
}
