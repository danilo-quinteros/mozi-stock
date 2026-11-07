<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega soporte para la modalidad "Electrónica en Línea" (GRACO/PRICO con firma digital XMLDSig).
 * Por defecto las empresas tienen modalidad computarizada.
 * Si eligen modalidad electrónica, se almacena la ruta al archivo PKCS#12 (.p12) en storage privado
 * y la contraseña cifrada en base de datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('siat_configuraciones', function (Blueprint $table) {
            $table->string('modalidad', 20)->default('computarizada')->after('modo');
            $table->string('certificado_ruta')->nullable()->after('token_actualizado_por');
            $table->string('certificado_nombre')->nullable()->after('certificado_ruta');
            $table->text('certificado_pass')->nullable()->after('certificado_nombre');
            $table->timestamp('certificado_vence_en')->nullable()->after('certificado_pass');
        });
    }

    public function down(): void
    {
        Schema::table('siat_configuraciones', function (Blueprint $table) {
            $table->dropColumn([
                'modalidad',
                'certificado_ruta',
                'certificado_nombre',
                'certificado_pass',
                'certificado_vence_en',
            ]);
        });
    }
};
