<?php

namespace MoziStudios\Siat\Fecha;

use DateTimeImmutable;

/** Fuente de la hora actual (fechaEnvio). Se reemplaza en pruebas o por una hora sincronizada con el SIN. */
interface Reloj
{
    public function ahora(): DateTimeImmutable;
}
