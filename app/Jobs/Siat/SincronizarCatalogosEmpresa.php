<?php

namespace App\Jobs\Siat;

use App\Domain\Facturacion\ErrorFacturacion;
use App\Domain\Facturacion\SincronizarCatalogos;
use App\Models\Empresa;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use MoziStudios\Siat\Excepciones\SiatException;

/**
 * Sincroniza los catálogos del SIN de UNA empresa (resuelta en el trabajo, sin usuario).
 */
class SincronizarCatalogosEmpresa implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $empresaId) {}

    public function handle(SincronizarCatalogos $sincronizar): void
    {
        $empresa = Empresa::query()->find($this->empresaId);

        if ($empresa === null || ! $empresa->activa || ! $empresa->siat_habilitado) {
            return;
        }

        try {
            $resultado = $sincronizar->ejecutar($empresa);
        } catch (ErrorFacturacion|SiatException $error) {
            Log::warning('SIAT: no se sincronizaron los catálogos', ['empresa_id' => $empresa->id, 'error' => $error->getMessage()]);

            return;
        }

        if ($resultado['fallidos'] !== []) {
            Log::warning('SIAT: catálogos con error', ['empresa_id' => $empresa->id, 'catalogos' => $resultado['fallidos']]);
        }
    }
}
