<?php

namespace App\Jobs\Siat;

use App\Domain\Facturacion\EmisionFacturas;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Envía al SIN una factura pendiente, con la empresa explícita (los jobs no tienen contexto de
 * empresa). Se despacha después del commit de la venta. Sin respuesta del SIN reintenta con espera;
 * si el SIN la deja pendiente de validación (901), vuelve a consultarla más tarde.
 */
class EnviarFactura implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public function __construct(public readonly int $empresaId, public readonly int $facturaId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300, 900, 1800];
    }

    public function handle(EmisionFacturas $emision): void
    {
        if ($emision->procesar($this->empresaId, $this->facturaId)) {
            $this->release(EmisionFacturas::SEGUNDOS_VERIFICACION);
        }
    }
}
