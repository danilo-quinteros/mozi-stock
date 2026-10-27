<?php

namespace MoziStudios\Siat\Respuestas;

/** Evento significativo retornado por la consulta o registro del SIN. */
final class EventoSignificativoSin
{
    public function __construct(
        public readonly ?int $codigoEvento,
        public readonly ?int $codigoRecepcionEventoSignificativo,
        public readonly string $descripcion,
        public readonly ?string $fechaInicio,
        public readonly ?string $fechaFin,
    ) {}
}
