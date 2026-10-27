<?php

namespace MoziStudios\Siat\Respuestas;

use MoziStudios\Siat\Codigos\Cufd;

/** Respuesta de cufd. `cufd` es null cuando el SIN no entregó código y código de control. */
final class RespuestaCufd
{
    /**
     * @param  list<Mensaje>  $mensajes
     */
    public function __construct(
        public readonly bool $transaccion,
        public readonly array $mensajes,
        public readonly ?Cufd $cufd,
    ) {}

    public static function desde(object $respuesta): self
    {
        $codigo = Lector::texto($respuesta, 'codigo');
        $control = Lector::texto($respuesta, 'codigoControl');

        return new self(
            Lector::booleano($respuesta, 'transaccion'),
            Lector::mensajes($respuesta),
            $codigo === null || $control === null ? null : new Cufd(
                $codigo,
                $control,
                Lector::texto($respuesta, 'codigoInterno'),
                Lector::texto($respuesta, 'direccion'),
                Lector::fecha($respuesta, 'fechaVigencia'),
            ),
        );
    }
}
