<?php

namespace MoziStudios\Siat\Respuestas;

/** Respuesta de registroPuntoVenta y cierrePuntoVenta: el código del punto de venta afectado. */
final class RespuestaPuntoVenta
{
    /**
     * @param  list<Mensaje>  $mensajes
     */
    public function __construct(
        public readonly bool $transaccion,
        public readonly array $mensajes,
        public readonly ?int $codigoPuntoVenta,
    ) {}

    public static function desde(object $respuesta): self
    {
        return new self(
            Lector::booleano($respuesta, 'transaccion'),
            Lector::mensajes($respuesta),
            Lector::entero($respuesta, 'codigoPuntoVenta'),
        );
    }
}
