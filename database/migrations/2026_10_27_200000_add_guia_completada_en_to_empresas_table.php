<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fecha en que la empresa terminó la guía de configuración inicial; con valor, la guía no vuelve.
        Schema::table('empresas', function (Blueprint $table) {
            $table->timestamp('guia_completada_en')->nullable()->after('siat_habilitado');
        });

        // La guía es para empresas nuevas: las que ya existían no la ven.
        DB::table('empresas')->update(['guia_completada_en' => now()]);
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('guia_completada_en');
        });
    }
};
