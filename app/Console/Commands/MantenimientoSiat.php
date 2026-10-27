<?php

namespace App\Console\Commands;

use App\Enums\EstadoFactura;
use App\Jobs\Siat\EnviarFactura;
use App\Jobs\Siat\RenovarCufdEmpresa;
use App\Jobs\Siat\SincronizarCatalogosEmpresa;
use App\Models\Empresa;
use App\Models\Scopes\EmpresaScope;
use App\Models\SiatConfiguracion;
use App\Models\SiatFactura;
use Illuminate\Console\Command;

/**
 * Programado en routes/console.php: pone en cola un trabajo por empresa que puede facturar (activa,
 * habilitada por MoziStudios y con token vigente). Requiere un trabajador de cola. La tarea «facturas»
 * vuelve a encolar las facturas que siguen pendientes (por ejemplo, si el SIN estuvo caído más que los reintentos).
 */
class MantenimientoSiat extends Command
{
    protected $signature = 'mozistock:siat {tarea : cufd (renovar CUFD), catalogos (sincronizar catálogos) o facturas (reenviar pendientes)}';

    protected $description = 'Pone en cola la renovación de CUFD, la sincronización de catálogos SIAT o el reenvío de facturas pendientes';

    /** Una factura pendiente se reencola si nadie la tocó en este tiempo (sus propios reintentos van antes). */
    private const MINUTOS_FACTURA_DETENIDA = 15;

    public function handle(): int
    {
        $tarea = (string) $this->argument('tarea');

        if (! in_array($tarea, ['cufd', 'catalogos', 'facturas'], true)) {
            $this->error('La tarea debe ser «cufd», «catalogos» o «facturas».');

            return self::FAILURE;
        }

        $empresas = Empresa::query()
            ->where('activa', true)
            ->where('siat_habilitado', true)
            ->whereIn('id', SiatConfiguracion::withoutGlobalScope(EmpresaScope::class)->select('empresa_id')
                ->whereNotNull('nit')->whereNotNull('token_sufijo')
                ->where(fn ($q) => $q->whereNull('token_vence_en')->orWhere('token_vence_en', '>', now())))
            ->pluck('id');

        if ($tarea === 'facturas') {
            $pendientes = SiatFactura::withoutGlobalScope(EmpresaScope::class)
                ->whereIn('empresa_id', $empresas)
                ->where('estado', EstadoFactura::Pendiente)
                ->where('updated_at', '<', now()->subMinutes(self::MINUTOS_FACTURA_DETENIDA))
                ->get(['id', 'empresa_id']);

            foreach ($pendientes as $factura) {
                EnviarFactura::dispatch($factura->empresa_id, $factura->id);
            }

            $this->info("Facturas en cola: {$pendientes->count()}.");

            return self::SUCCESS;
        }

        foreach ($empresas as $empresaId) {
            $tarea === 'cufd' ? RenovarCufdEmpresa::dispatch($empresaId) : SincronizarCatalogosEmpresa::dispatch($empresaId);
        }

        $this->info("Empresas en cola: {$empresas->count()}.");

        return self::SUCCESS;
    }
}
