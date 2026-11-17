<?php

use App\Support\RestriccionCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Producción (ESPECIFICACION_PRODUCCION.md § 8): nueva capacidad `produccion`. Solo amplía el CHECK; ninguna
 * empresa la recibe activada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->esMysql()) {
            return;
        }

        RestriccionCheck::quitar('empresa_capacidades', 'empresa_capacidades_capacidad_check');
        DB::statement("ALTER TABLE empresa_capacidades ADD CONSTRAINT empresa_capacidades_capacidad_check CHECK (capacidad IN ('variantes', 'lotes_vencimientos', 'despachos', 'cocina', 'produccion'))");
    }

    public function down(): void
    {
        if (! $this->esMysql()) {
            return;
        }

        DB::table('empresa_capacidades')->where('capacidad', 'produccion')->delete();
        RestriccionCheck::quitar('empresa_capacidades', 'empresa_capacidades_capacidad_check');
        DB::statement("ALTER TABLE empresa_capacidades ADD CONSTRAINT empresa_capacidades_capacidad_check CHECK (capacidad IN ('variantes', 'lotes_vencimientos', 'despachos', 'cocina'))");
    }

    private function esMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
