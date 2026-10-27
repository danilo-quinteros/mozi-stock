<?php

namespace App\Domain\Facturacion;

use MoziStudios\Siat\Respuestas\Mensaje;
use RuntimeException;

/**
 * Una acción fiscal no se pudo completar: falta configuración, la empresa no está habilitada o el
 * SIN rechazó la solicitud. El mensaje es apto para mostrarse al usuario; nunca incluye el token.
 */
final class ErrorFacturacion extends RuntimeException
{
    /**
     * @param  list<Mensaje>  $mensajesSin
     */
    public function __construct(string $mensaje, public readonly array $mensajesSin = [])
    {
        parent::__construct($mensaje);
    }

    /**
     * @param  list<Mensaje>  $mensajes
     */
    public static function delSin(string $resumen, array $mensajes): self
    {
        $detalle = implode('; ', array_map(strval(...), $mensajes));

        return new self($detalle === '' ? $resumen : "{$resumen} {$detalle}", $mensajes);
    }
}
