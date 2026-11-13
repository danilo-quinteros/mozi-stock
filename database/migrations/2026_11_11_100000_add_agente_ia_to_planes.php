<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca el plan que incluye a Mozi, el agente de IA, como siat_habilitado marca la facturación.
     * Hoy lo usa el sitio público para armar las tarjetas de precios; el superadmin lo cambia en
     * Superadmin › Suscripciones › Planes.
     */
    public function up(): void
    {
        Schema::table('planes', function (Blueprint $table) {
            $table->boolean('agente_ia')->default(false)->after('siat_habilitado');
        });

        // El tercer plan ya se creó desde Superadmin con un código derivado de su título
        // («agente-mozi-…»). Se marca una sola vez; desde aquí lo decide la casilla del plan.
        DB::table('planes')->where('codigo', 'like', '%agente%')->update(['agente_ia' => true]);
    }

    public function down(): void
    {
        Schema::table('planes', function (Blueprint $table) {
            $table->dropColumn('agente_ia');
        });
    }
};
