<?php

namespace App\Domain\Identidad;

use App\Http\Controllers\ImpersonacionController;

/**
 * Indica si la sesión actual es una impersonación («Entrar como»). Fuera de una petición web
 * (consola, jobs) siempre es false.
 */
final class Impersonacion
{
    public static function activa(): bool
    {
        $peticion = request();

        return $peticion->hasSession() && $peticion->session()->has(ImpersonacionController::SESION);
    }
}
