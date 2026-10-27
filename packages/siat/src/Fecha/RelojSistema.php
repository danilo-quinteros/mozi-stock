<?php

namespace MoziStudios\Siat\Fecha;

use DateTimeImmutable;
use DateTimeZone;

/** Hora del servidor en la zona del SIN (America/La_Paz por defecto). */
final class RelojSistema implements Reloj
{
    public function __construct(private readonly string $zona = 'America/La_Paz') {}

    public function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone($this->zona));
    }
}
