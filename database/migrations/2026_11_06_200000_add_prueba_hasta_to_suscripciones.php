<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Último día de la prueba gratis de una empresa registrada desde el sitio (fecha de La Paz).
     * Es informativo, como el resto de la suscripción: nunca bloquea el acceso.
     */
    public function up(): void
    {
        Schema::table('suscripciones', function (Blueprint $table) {
            $table->date('prueba_hasta')->nullable()->after('vencimiento');
        });
    }

    public function down(): void
    {
        Schema::table('suscripciones', function (Blueprint $table) {
            $table->dropColumn('prueba_hasta');
        });
    }
};
