<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Se quita la vida útil del producto (PR13, decisión del propietario del 16/11/2026): el vencimiento del lote
     * producido se escribe al finalizar cada orden. Primero el CHECK, porque MySQL no deja borrar una columna que un
     * CHECK usa. down() la restituye tal como la creó 2026_11_16_120000_create_recetas_tables (los valores no vuelven).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('productos', 'vida_util_dias')) {
            return;
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE productos DROP CONSTRAINT productos_vida_util_check');
        }

        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn('vida_util_dias');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('productos', 'vida_util_dias')) {
            return;
        }

        Schema::table('productos', function (Blueprint $table) {
            $table->unsignedSmallInteger('vida_util_dias')->nullable()->after('costo_promedio');
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE productos ADD CONSTRAINT productos_vida_util_check CHECK (vida_util_dias IS NULL OR vida_util_dias BETWEEN 1 AND 3650)');
        }
    }
};
