<?php

namespace MoziStudios\Siat\Respuestas;

use DateTimeImmutable;

/** Hora oficial del SIN (sincronizarFechaHora), para detectar desfases del reloj del servidor. */
final class RespuestaFechaHora
{
    /**
     * @param  list<Mensaje>  $mensajes
     */
    public function __construct(
        public readonly bool $transaccion,
        public readonly array $mensajes,
        public readonly ?string $texto,
        public readonly ?DateTimeImmutable $fechaHora,
    ) {}

    public static function desde(object $respuesta): self
    {
        return new self(
            Lector::booleano($respuesta, 'transaccion'),
            Lector::mensajes($respuesta),
            Lector::texto($respuesta, 'fechaHora'),
            Lector::fecha($respuesta, 'fechaHora'),
        );
    }
}
