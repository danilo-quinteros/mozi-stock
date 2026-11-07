<?php

namespace App\Jobs\CatalogoExterno;

use App\Domain\CatalogoExterno\Actions\SincronizarEmpresa;
use App\Models\Empresa;
use App\Models\ProveedorExterno;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SincronizarEmpresaJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $empresaId,
        public readonly int $proveedorId,
    ) {}

    public function uniqueId(): string
    {
        return "sync:{$this->empresaId}:{$this->proveedorId}";
    }

    public function handle(SincronizarEmpresa $sincronizador): void
    {
        $empresa = Empresa::query()->find($this->empresaId);
        $proveedor = ProveedorExterno::query()->find($this->proveedorId);

        if ($empresa === null || ! $empresa->activa || $proveedor === null || ! $proveedor->activo) {
            return;
        }

        try {
            $resultado = $sincronizador->ejecutar($empresa, $proveedor, soloActualizarVinculados: true);
            Log::info("Sincronización de catálogo externo completada para Empresa {$empresa->id}", $resultado);
        } catch (Throwable $e) {
            Log::error("Error al sincronizar catálogo externo para Empresa {$empresa->id}: {$e->getMessage()}");
        }
    }
}
