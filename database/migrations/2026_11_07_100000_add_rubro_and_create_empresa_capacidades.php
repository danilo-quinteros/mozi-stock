<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rubros y capacidades (etapa R0 de ESPECIFICACION_RUBROS_Y_CAPACIDADES.md).
     *
     * - empresas.rubro: etiqueta del negocio. Las empresas que ya existen quedan «general» por el valor
     *   por defecto, sin capacidades: su comportamiento no cambia.
     * - empresa_capacidades: funciones encendidas o apagadas por empresa. Se lee con una sola consulta por
     *   petición (índice único empresa_id + capacidad) y solo se escribe con CambiarFuncionesEmpresa.
     */
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('rubro', 20)->default('general')->after('siat_habilitado');
        });

        Schema::create('empresa_capacidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('capacidad', 40);
            $table->boolean('activa')->default(false);
            // Quién hizo el último cambio. Puede ser el superadmin (sin empresa), así que la FK es simple;
            // el historial completo queda en auditoría.
            $table->foreignId('cambiada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['empresa_id', 'capacidad']);
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE empresas ADD CONSTRAINT empresas_rubro_check CHECK (rubro IN ('general', 'ropa', 'farmacia', 'distribuidora', 'restaurante'))");
            DB::statement("ALTER TABLE empresa_capacidades ADD CONSTRAINT empresa_capacidades_capacidad_check CHECK (capacidad IN ('variantes', 'lotes_vencimientos', 'despachos', 'cocina'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_capacidades');

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE empresas DROP CONSTRAINT empresas_rubro_check');
        }

        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('rubro');
        });
    }
};
