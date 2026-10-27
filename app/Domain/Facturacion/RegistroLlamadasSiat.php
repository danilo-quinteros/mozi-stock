<?php

namespace App\Domain\Facturacion;

use Illuminate\Support\Facades\Log;
use MoziStudios\Siat\Soap\LlamadaSiat;
use MoziStudios\Siat\Soap\ObservadorLlamadas;

/**
 * Deja constancia de cada llamada al SIN con metadatos únicamente: servicio, operación, duración y
 * resultado. Nunca registra el token, el XML ni los datos del comprador.
 */
final class RegistroLlamadasSiat implements ObservadorLlamadas
{
    public function registrar(LlamadaSiat $llamada): void
    {
        $contexto = [
            'servicio' => $llamada->servicio->value,
            'operacion' => $llamada->operacion,
            'milisegundos' => $llamada->milisegundos,
        ];

        if ($llamada->exitosa) {
            Log::debug('SIAT: llamada exitosa', $contexto);

            return;
        }

        Log::warning('SIAT: llamada fallida', $contexto + ['error' => $llamada->error]);
    }
}
