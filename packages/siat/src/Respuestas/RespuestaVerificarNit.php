<?php

namespace MoziStudios\Siat\Respuestas;

/** Respuesta de verificarNit: el estado del NIT llega en los mensajes (paramétrica de mensajes). */
final class RespuestaVerificarNit
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
