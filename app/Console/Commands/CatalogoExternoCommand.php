<?php

namespace App\Console\Commands;

use App\Domain\CatalogoExterno\Actions\DescargarCatalogoCentral;
use App\Domain\CatalogoExterno\Actions\SincronizarEmpresa;
use App\Jobs\CatalogoExterno\DescargarCatalogoJob;
use App\Jobs\CatalogoExterno\SincronizarEmpresaJob;
use App\Models\Empresa;
use App\Models\EmpresaProveedorExterno;
use App\Models\ProveedorExterno;
use Illuminate\Console\Command;

class CatalogoExternoCommand extends Command
{
    protected $signature = 'mozistock:catalogo-externo 
        {accion : descargar (bajada desde API) o sincronizar (a empresas)}
        {--proveedor=cyclone : Código del proveedor externo}
        {--empresa= : ID de empresa específica (para sincronizar)}
        {--todos : Sincronizar todos los productos del catálogo central incluso los no vinculados}
        {--sync : Ejecutar síncronamente sin enviar a la cola}';

    protected $description = 'Descarga el catálogo de proveedores externos y sincroniza con empresas revendedoras';

    public function handle(
        DescargarCatalogoCentral $descargador,
        SincronizarEmpresa $sincronizador
    ): int {
        $accion = (string) $this->argument('accion');
        $codigoProveedor = (string) $this->option('proveedor');
        $esSincrono = (bool) $this->option('sync');
        $soloActualizarVinculados = ! ((bool) $this->option('todos'));

        $proveedor = ProveedorExterno::query()->where('codigo', $codigoProveedor)->first();
        if ($proveedor === null) {
            $this->error("Proveedor externo no encontrado con código: {$codigoProveedor}");

            return self::FAILURE;
        }

        if ($accion === 'descargar') {
            if ($esSincrono) {
                $this->info("Descargando catálogo central de {$proveedor->nombre} síncronamente...");
                $res = $descargador->ejecutar($proveedor);
                $this->info("Listo: {$res['procesados']} procesados, {$res['nuevos']} nuevos, {$res['actualizados']} actualizados, {$res['descontinuados']} descontinuados.");

                // Sincronizar empresas vinculadas
                $vinculos = EmpresaProveedorExterno::withoutGlobalScopes()
                    ->where('proveedor_externo_id', $proveedor->id)
                    ->where('activo', true)
                    ->get();

                foreach ($vinculos as $v) {
                    $empresa = Empresa::query()->find($v->empresa_id);
                    if ($empresa) {
                        $sincronizador->ejecutar($empresa, $proveedor, soloActualizarVinculados: $soloActualizarVinculados);
                        $this->line("Empresa {$empresa->nombre} (#{$empresa->id}) sincronizada.");
                    }
                }
            } else {
                DescargarCatalogoJob::dispatch($proveedor->id);
                $this->info("Trabajo de descarga encolado para {$proveedor->nombre}.");
            }

            return self::SUCCESS;
        }

        if ($accion === 'sincronizar') {
            $empresaId = $this->option('empresa');

            if ($empresaId !== null && ctype_digit((string) $empresaId)) {
                $empresa = Empresa::query()->findOrFail((int) $empresaId);
                if ($esSincrono) {
                    $res = $sincronizador->ejecutar($empresa, $proveedor, soloActualizarVinculados: $soloActualizarVinculados);
                    $this->info("Empresa {$empresa->nombre} sincronizada: {$res['creados']} creados, {$res['actualizados']} actualizados, {$res['sin_cambios']} sin cambios.");
                } else {
                    SincronizarEmpresaJob::dispatch($empresa->id, $proveedor->id);
                    $this->info("Trabajo de sincronización encolado para empresa {$empresa->nombre}.");
                }
            } else {
                $vinculos = EmpresaProveedorExterno::withoutGlobalScopes()
                    ->where('proveedor_externo_id', $proveedor->id)
                    ->where('activo', true)
                    ->get();

                $this->info("Encolando sincronización para {$vinculos->count()} empresas vinculadas...");
                foreach ($vinculos as $v) {
                    SincronizarEmpresaJob::dispatch($v->empresa_id, $proveedor->id);
                }
            }

            return self::SUCCESS;
        }

        $this->error('Acción inválida. Usa "descargar" o "sincronizar".');

        return self::FAILURE;
    }
}
