<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedidos en cocina, etapa K2 (ESPECIFICACION_COCINA.md § 4.4 y § 6): minutos a partir de los cuales un
     * pedido En cocina se resalta como demorado. Entero de 1 a 240, 15 por defecto. Las empresas existentes
     * quedan con 15. No hay tablas ni índices nuevos: el panel usa los índices de entrega de despachos.
     */
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->unsignedSmallInteger('minutos_alerta_cocina')->default(15);
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE empresas ADD CONSTRAINT empresas_minutos_alerta_cocina_check CHECK (minutos_alerta_cocina BETWEEN 1 AND 240)');
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE empresas DROP CONSTRAINT empresas_minutos_alerta_cocina_check');
        }

        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('minutos_alerta_cocina');
        });
    }
};
