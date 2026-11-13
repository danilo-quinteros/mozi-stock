<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resultado de la última verificación de la configuración SIAT contra el SIN (asistente de
 * Facturación → Datos fiscales). Guarda una huella de los datos verificados: si cambian el NIT, la
 * modalidad, el token, el código de sistema o el ambiente, el resultado deja de valer solo.
 * Nunca guarda el token ni respuestas crudas del SIN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('siat_configuraciones', function (Blueprint $table) {
            $table->json('verificacion')->nullable()->after('certificado_vence_en');
            $table->timestamp('verificado_en')->nullable()->after('verificacion');
        });
    }

    public function down(): void
    {
        Schema::table('siat_configuraciones', function (Blueprint $table) {
            $table->dropColumn(['verificacion', 'verificado_en']);
        });
    }
};
