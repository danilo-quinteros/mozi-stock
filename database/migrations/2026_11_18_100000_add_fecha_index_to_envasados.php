<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agente Mozi, etapa 7a (AGENTE_MOZI_IA.md § 5.2): las fuentes envasados y materiales_envasado y el análisis de
 * rendimiento (UC-23) filtran los envasados de la empresa por su fecha. Índice (empresa_id, created_at) para ese rango
 * sin recorrer todos los envasados de la empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envasados', function (Blueprint $table): void {
            $table->index(['empresa_id', 'created_at'], 'envasados_empresa_fecha_index');
        });
    }

    public function down(): void
    {
        Schema::table('envasados', function (Blueprint $table): void {
            $table->dropIndex('envasados_empresa_fecha_index');
        });
    }
};
