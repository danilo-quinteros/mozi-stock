<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Producción, etapa P1 (ESPECIFICACION_PRODUCCION.md § 4 y § 10): materia prima como producto marcado.
 *
 * - clase: 'venta' (todos los productos actuales) o 'materia_prima' (nunca se vende; siempre controla stock).
 * - stock_maximo: aviso de sobre stock y sugerencia de compra (PR17).
 * - observacion: «Observación» del Excel § 1.1.
 * - costo_promedio: costo promedio ponderado (PR12), con 6 decimales para costos unitarios pequeños (por gramo).
 *
 * Índice (empresa_id, clase, activo, nombre): los listados filtran siempre por clase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->string('clase', 20)->default('venta')->after('empresa_id');
            $table->decimal('stock_maximo', 18, 3)->nullable()->after('stock_minimo');
            $table->string('observacion', 250)->nullable()->after('descripcion');
            $table->decimal('costo_promedio', 18, 6)->default(0)->after('precio_mayorista');
            $table->index(['empresa_id', 'clase', 'activo', 'nombre'], 'productos_empresa_clase_index');
        });

        if ($this->esMysql()) {
            DB::statement("ALTER TABLE productos ADD CONSTRAINT productos_clase_check CHECK (clase IN ('venta', 'materia_prima') AND (clase = 'venta' OR controla_stock = 1) AND (stock_maximo IS NULL OR stock_maximo >= stock_minimo) AND costo_promedio >= 0)");
        }
    }

    public function down(): void
    {
        if ($this->esMysql()) {
            DB::statement('ALTER TABLE productos DROP CONSTRAINT productos_clase_check');
        }

        Schema::table('productos', function (Blueprint $table) {
            $table->dropIndex('productos_empresa_clase_index');
            $table->dropColumn(['clase', 'stock_maximo', 'observacion', 'costo_promedio']);
        });
    }

    private function esMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
