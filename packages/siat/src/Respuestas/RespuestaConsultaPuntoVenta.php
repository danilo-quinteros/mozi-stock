<?php

namespace MoziStudios\Siat\Respuestas;

/** Puntos de venta que el SIN tiene registrados para una sucursal del contribuyente. */
final class RespuestaConsultaPuntoVenta
{
    /**
     * @param  list<Mensaje>  $mensajes
     * @param  list<PuntoVentaSin>  $puntos
     */
    public function __construct(
        public readonly bool $transaccion,
        public readonly array $mensajes,
        public readonly array $puntos,
    ) {}

    public static function desde(object $respuesta): self
    {
        $puntos = [];

        foreach (Lector::lista($respuesta, 'listaPuntosVentas') as $fila) {
            $codigo = Lector::entero($fila, 'codigoPuntoVenta');

            if ($codigo !== null) {
                $puntos[] = new PuntoVentaSin($codigo, Lector::texto($fila, 'nombrePuntoVenta') ?? '', Lector::texto($fila, 'tipoPuntoVenta') ?? '');
            }
        }

        return new self(Lector::booleano($respuesta, 'transaccion'), Lector::mensajes($respuesta), $puntos);
    }
}
