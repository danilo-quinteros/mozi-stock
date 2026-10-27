<?php

namespace MoziStudios\Siat\Respuestas;

use DateTimeImmutable;

/**
 * Respuesta de cuis. El SIN puede devolver un CUIS aunque `transaccion` sea false (por ejemplo,
 * cuando ya existe uno vigente): se entrega tal cual y quien integra decide con los mensajes.
 */
final class RespuestaCuis
{
    /**
     * @param  list<Mensaje>  $mensajes
     */
    public function __construct(
        public readonly bool $transaccion,
        public readonly array $mensajes,
        public readonly ?string $codigo,
        public readonly ?DateTimeImmutable $fechaVigencia,
    ) {}

    public static function desde(object $respuesta): self
    {
        return new self(
            Lector::booleano($respuesta, 'transaccion'),
            Lector::mensajes($respuesta),
            Lector::texto($respuesta, 'codigo'),
            Lector::fecha($respuesta, 'fechaVigencia'),
        );
    }
}
