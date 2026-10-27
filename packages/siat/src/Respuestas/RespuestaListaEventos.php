<?php

namespace MoziStudios\Siat\Respuestas;

/** Respuesta a registroEventoSignificativo y consultaEventoSignificativo. */
final class RespuestaListaEventos
{
    /**
     * @param  list<Mensaje>  $mensajes
     * @param  list<EventoSignificativoSin>  $eventos
     */
    public function __construct(
        public readonly bool $transaccion,
        public readonly ?int $codigoRecepcionEventoSignificativo,
        public readonly array $mensajes,
        public readonly array $eventos,
    ) {}

    public static function desde(object $respuesta): self
    {
        $eventos = [];

        foreach (Lector::lista($respuesta, 'listaCodigos') as $fila) {
            $eventos[] = new EventoSignificativoSin(
                Lector::entero($fila, 'codigoEvento'),
                Lector::entero($fila, 'codigoRecepcionEventoSignificativo'),
                Lector::texto($fila, 'descripcion') ?? '',
                Lector::texto($fila, 'fechaInicio'),
                Lector::texto($fila, 'fechaFin'),
            );
        }

        return new self(
            Lector::booleano($respuesta, 'transaccion'),
            Lector::entero($respuesta, 'codigoRecepcionEventoSignificativo'),
            Lector::mensajes($respuesta),
            $eventos,
        );
    }
}
