<?php

namespace App\Jobs\Siat;

use App\Domain\Facturacion\CodigosSiat;
use App\Domain\Facturacion\DeEmpresa;
use App\Domain\Facturacion\ErrorFacturacion;
use App\Models\Empresa;
use App\Models\SiatPuntoVenta;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use MoziStudios\Siat\Excepciones\SiatException;

/**
 * Renueva el CUFD de los puntos de venta activos de UNA empresa cuando falta o le quedan menos de
 * 2 horas. Sin usuario autenticado: la empresa llega en el trabajo y todas las consultas la usan de
 * forma explícita. Un punto que falla no detiene a los demás; el log no incluye el token.
 */
class RenovarCufdEmpresa implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $empresaId) {}

    public function handle(CodigosSiat $codigos): void
    {
        $empresa = Empresa::query()->find($this->empresaId);

        if ($empresa === null || ! $empresa->activa || ! $empresa->siat_habilitado) {
            return;
        }

        $puntos = DeEmpresa::consulta(SiatPuntoVenta::class, $empresa->id)->where('activo', true)->orderBy('id')->get();

        foreach ($puntos as $punto) {
            try {
                $codigos->renovarCufd($punto, soloSiHaceFalta: true);
            } catch (ErrorFacturacion|SiatException $error) {
                Log::warning('SIAT: no se renovó el CUFD', [
                    'empresa_id' => $empresa->id,
                    'siat_punto_venta_id' => $punto->id,
                    'error' => $error->getMessage(),
                ]);
            }
        }
    }
}
