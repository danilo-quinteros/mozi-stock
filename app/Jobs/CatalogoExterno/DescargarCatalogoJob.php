<?php

namespace App\Jobs\CatalogoExterno;

use App\Domain\CatalogoExterno\Actions\DescargarCatalogoCentral;
use App\Models\EmpresaProveedorExterno;
use App\Models\ProveedorExterno;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class DescargarCatalogoJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $proveedorId) {}

    public function handle(DescargarCatalogoCentral $descargador): void
    {
        $proveedor = ProveedorExterno::query()->find($this->proveedorId);

        if ($proveedor === null || ! $proveedor->activo) {
            return;
        }

        try {
            $resultado = $descargador->ejecutar($proveedor);
            Log::info("Catálogo central descargado exitosamente para {$proveedor->nombre}", $resultado);

            // Tras la descarga exitosa, despachar sincronización a todas las empresas revendedoras vinculadas
            $vinculos = EmpresaProveedorExterno::withoutGlobalScopes()
                ->where('proveedor_externo_id', $proveedor->id)
                ->where('activo', true)
                ->get(['empresa_id']);

            foreach ($vinculos as $vinculo) {
                SincronizarEmpresaJob::dispatch($vinculo->empresa_id, $proveedor->id);
            }
        } catch (Throwable $e) {
            Log::error("Error al descargar catálogo central de {$proveedor->nombre}: {$e->getMessage()}");
        }
    }
}
