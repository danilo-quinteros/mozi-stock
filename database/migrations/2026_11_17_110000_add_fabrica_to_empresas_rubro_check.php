<?php

use App\Support\RestriccionCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rubro «Fábricas y laboratorios» (`fabrica`, decisión del propietario del 17/11/2026): solo amplía el CHECK de
 * empresas.rubro. Ninguna empresa existente cambia de rubro ni de funciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->esMysql()) {
            return;
        }

        RestriccionCheck::quitar('empresas', 'empresas_rubro_check');
        DB::statement("ALTER TABLE empresas ADD CONSTRAINT empresas_rubro_check CHECK (rubro IN ('general', 'ropa', 'farmacia', 'distribuidora', 'restaurante', 'fabrica'))");
    }

    /**
     * Las empresas que ya eligieron el rubro vuelven a «Tienda» (general); sus funciones encendidas no se tocan.
     */
    public function down(): void
    {
        if (! $this->esMysql()) {
            return;
        }

        DB::table('empresas')->where('rubro', 'fabrica')->update(['rubro' => 'general']);
        RestriccionCheck::quitar('empresas', 'empresas_rubro_check');
        DB::statement("ALTER TABLE empresas ADD CONSTRAINT empresas_rubro_check CHECK (rubro IN ('general', 'ropa', 'farmacia', 'distribuidora', 'restaurante'))");
    }

    private function esMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
